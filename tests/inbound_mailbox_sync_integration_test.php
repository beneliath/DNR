<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Mailbox synchronization tests skipped (requires disposable worker credentials).\n";
    exit(0);
}

$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/inbound_ingestion_helpers.php';
require_once $source . '/operations_helpers.php';

function expectMailSync(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Mailbox synchronization: ' . $message);
}

function expectMailSyncFailure(callable $action, string $message): void
{
    try {
        $action();
    } catch (Throwable $error) {
        expectMailSync(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

// A real restricted connection, with one injected failure at the checkpoint
// write. The queue insert and receipt trigger execute in MySQL before it fails.
final class MailSyncTestConnection extends mysqli
{
    public bool $failCheckpoint = false;
    public bool $failQuarantine = false;
    public function execute_query(string $query, ?array $params = null): mysqli_result|bool
    {
        if ($this->failCheckpoint && str_starts_with($query, 'UPDATE inbound_mailbox_state SET scanned_uid')) {
            $this->failCheckpoint = false;
            throw new RuntimeException('Injected checkpoint write failure');
        }
        return parent::execute_query($query, $params);
    }
    public function prepare(string $query): mysqli_stmt|false
    {
        if ($this->failQuarantine && str_contains($query, 'INSERT INTO inbound_email_quarantine')) {
            $this->failQuarantine = false;
            throw new RuntimeException('Injected quarantine write failure');
        }
        return parent::prepare($query);
    }
}

final class MailSyncFixture implements \Dnr\Infrastructure\InboundMailbox
{
    public int $validity = 100;
    public int $next = 1;
    public array $messages = [];
    public array $seen = [];
    public array $fetches = [];
    public array $searches = [];
    public array $failures = [];
    public bool $aborted = false;
    public function uidValidity(): int { return $this->validity; }
    public function uidNext(): int { return $this->next; }
    public function uidsBetween(int $first, int $last): array
    {
        $this->searches[] = [$first, $last];
        $uids = array_values(array_filter(array_keys($this->messages), fn(int $uid): bool => $uid >= $first && $uid <= $last));
        sort($uids, SORT_NUMERIC);
        return $uids;
    }
    public function fetchRawMessage(int $uid): string
    {
        expectMailSync(!$this->aborted, 'A rejected connection must not be reused');
        $this->fetches[] = $uid;
        if (isset($this->failures[$uid])) throw $this->failures[$uid];
        return $this->messages[$uid];
    }
    public function abort(): void { $this->aborted = true; }
}

$password = configurationSecret('DNR_TEST_MAIL_INGEST_PASSWORD');
expectMailSync($password !== '', 'The restricted worker password is required');
$worker = new MailSyncTestConnection((string) (getenv('DB_HOST') ?: 'db'), 'dnrmailingest', $password, (string) (getenv('MYSQL_DATABASE') ?: 'dnr'));
$worker->set_charset('utf8mb4');
setDatabaseAuditContext($worker, null, 'Email Gateway');
expectMailSync(str_starts_with($worker->query('SELECT CURRENT_USER() AS identity')->fetch_assoc()['identity'], 'dnrmailingest@'), 'Use real restricted grants');
expectMailSyncFailure(fn() => $worker->query('DELETE FROM inbound_email_import_receipts WHERE 1 = 0'), 'command denied');
expectMailSyncFailure(fn() => $worker->query('UPDATE inbound_email_import_receipts SET purged_at = NULL WHERE 1 = 0'), 'command denied');

putenv('DNR_INBOUND_ADDRESS=sync-gateway@example.test');
putenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM=1');
putenv('DNR_INBOUND_TRUSTED_AUTH_SERVERS=');
putenv('DNR_INBOUND_ROUTING_KEY_FILE');
putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('S', 32)));
$suffix = bin2hex(random_bytes(6));
$fixtureIds = [];
$key = inboundMailboxKey('fixture.example.test', 993, $suffix, 'INBOX');
$label = 'Synchronization fixture ' . $suffix;
$mailbox = new MailSyncFixture();
$raw = fn(string $name): string => "From: sender@example.test\r\nTo: sync-gateway@example.test\r\n"
    . "Subject: Sync {$name}\r\nMessage-ID: <{$suffix}-{$name}@example.test>\r\nDate: Mon, 14 Sep 2026 10:00:00 +0000\r\n\r\nFixture {$name}";
$fingerprints = [];
$fixture = function (string $name) use ($raw, &$fingerprints): string {
    $message = $raw($name);
    $fingerprints[] = parseInboundEmail($message)['deduplication_hash'];
    return $message;
};
$state = fn(): array => inboundSyncRow($conn, 'SELECT * FROM inbound_mailbox_state WHERE mailbox_key = ?', [$key]);
$mailRow = fn(string $message): array => inboundSyncRow($conn, 'SELECT * FROM inbound_email_messages WHERE deduplication_hash = ?', [parseInboundEmail($message)['deduplication_hash']]);
$candidate = fn(int $validity, int $uid): array => inboundSyncRow($conn, 'SELECT * FROM inbound_mail_reconciliation WHERE mailbox_key = ? AND uid_validity = ? AND uid = ?', [$key, $validity, $uid]);
$sync = fn(int $batch = 20): int => syncInboundMailbox($worker, $mailbox, $key, $label, $batch);

try {
    $sender = 'sync-' . $suffix . '@example.test';
    $conn->execute_query("INSERT INTO users (username, email, email_verified_at, password, role, account_status)
        VALUES (?, ?, UTC_TIMESTAMP(), ?, 'editor', 'active')", ['sync-' . $suffix, $sender, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $fixtureIds['users'] = (int) $conn->insert_id;
    $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', [$label]);
    $fixtureIds['organizations'] = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
        VALUES (?, ?, '2026-09-14', '2026-09-15', 'conference', 'confirmed')", [$fixtureIds['organizations'], $label]);
    $fixtureIds['engagements'] = (int) $conn->insert_id;
    // Establishing an empty Inbox gives the next arrival a normal automatic path.
    $sync();
    expectMailSync((int) $state()['scanned_uid'] === 0 && $state()['last_checked_at'] !== null, 'Empty polling establishes healthy tracking');
    $payload = json_encode(['kind' => 'internal', 'from' => $sender, 'id' => hash('sha256', $suffix . '-already-read@example.test')], JSON_THROW_ON_ERROR);
    $signingKey = hash_hmac('sha256', 'dnr:proton-sender-auth:key:v1', \Dnr\Security\InboundRoutingKey::bytes(), true);
    $assertion = 'v1.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.'
        . hash_hmac('sha256', "dnr:proton-sender-auth:v1\n" . $payload, $signingKey);
    $mailbox->messages[2] = "From: {$sender}\r\nTo: sync-gateway@example.test\r\n"
        . 'Subject: Already read ' . applicationInboundMarker($fixtureIds['engagements']) . "\r\n"
        . "Message-ID: <{$suffix}-already-read@example.test>\r\nX-Dnr-Sender-Authentication: {$assertion}\r\n\r\nPreserve this correspondence";
    $fingerprints[] = parseInboundEmail($mailbox->messages[2])['deduplication_hash'];
    $mailbox->seen[2] = true;
    $mailbox->next = 3;
    $sync();
    $imported = $mailRow($mailbox->messages[2]);
    expectMailSync($imported !== [] && $imported['status'] === 'pending', 'Already-read arrival enters the normal routing queue');
    expectMailSync(processInboundEmailMessage($worker, (int) $imported['id']) === 'processed', 'Already-read mail passes authenticated routing and files to Chron');
    $chron = inboundSyncRow($conn, 'SELECT id, entry_text FROM engagement_chron_entries WHERE inbound_email_message_id = ?', [(int) $imported['id']]);
    expectMailSync($chron !== [], 'Automatic import creates its intended Chron entry');
    expectMailSync($state()['last_imported_at'] !== null && (int) $state()['scanned_uid'] === 2, 'Import and checkpoint persist');
    $mailbox->seen[2] = false;
    $fetchCount = count($mailbox->fetches);
    $sync();
    expectMailSync(count($mailbox->fetches) === $fetchCount, 'Flag changes and empty polling do not re-fetch or duplicate mail');

    // A fetch failure cannot skip that UID or any later arrival.
    $mailbox->messages[4] = $fixture('retry-fetch');
    $mailbox->messages[7] = $fixture('after-retry');
    $mailbox->next = 8;
    $mailbox->failures[4] = new RuntimeException('Temporary mailbox outage');
    expectMailSyncFailure($sync, 'Temporary mailbox outage');
    expectMailSync((int) $state()['scanned_uid'] === 2 && (int) $state()['consecutive_failures'] === 1 && $mailRow($mailbox->messages[7]) === [], 'Fetch failure preserves progress and records health failure');
    unset($mailbox->failures[4]);
    $sync(1);
    expectMailSync((int) $state()['scanned_uid'] === 4 && $mailRow($mailbox->messages[7]) === [], 'A bounded batch does not advance over unhandled mail');
    $sync(1);
    expectMailSync((int) $state()['scanned_uid'] === 7 && (int) $state()['consecutive_failures'] === 0, 'A retry drains the next batch and clears failure health');

    // Simulate interruption after queue/receipt insertion but before checkpoint.
    $mailbox->messages[8] = $fixture('atomic-retry');
    $mailbox->next = 9;
    $worker->failCheckpoint = true;
    expectMailSyncFailure($sync, 'Injected checkpoint write failure');
    expectMailSync($mailRow($mailbox->messages[8]) === [] && (int) $state()['scanned_uid'] === 7, 'Uncommitted message and checkpoint both roll back');
    expectMailSync(!inboundImportWasRecorded($conn, parseInboundEmail($mailbox->messages[8])['deduplication_hash']), 'The receipt trigger rolls back with the failed checkpoint');
    $sync();
    expectMailSync($mailRow($mailbox->messages[8]) !== [] && (int) $state()['scanned_uid'] === 8, 'Retry imports the rolled-back message exactly once');

    // Purging source content preserves a receipt. Redelivery under a new UID
    // cannot recreate the mail card or route a second Chron entry.
    $conn->execute_query('DELETE FROM inbound_email_messages WHERE id = ?', [(int) $imported['id']]);
    $receipt = inboundSyncRow($conn, 'SELECT * FROM inbound_email_import_receipts WHERE deduplication_hash = ?', [parseInboundEmail($mailbox->messages[2])['deduplication_hash']]);
    expectMailSync($receipt['purged_at'] !== null, 'Deleting retained source preserves a purge receipt');
    $mailbox->messages[9] = $mailbox->messages[2];
    $mailbox->next = 10;
    $sync();
    expectMailSync($mailRow($mailbox->messages[9]) === [] && (int) $state()['scanned_uid'] === 9, 'Purged redelivery is acknowledged without recreating source content');
    $chronAfter = inboundSyncRow($conn, 'SELECT entry_text, inbound_email_message_id FROM engagement_chron_entries WHERE id = ?', [(int) $chron['id']]);
    expectMailSync($chronAfter['entry_text'] === $chron['entry_text'] && $chronAfter['inbound_email_message_id'] === null,
        'Purge and redelivery preserve Chron text and detach only the source link');
    expectMailSync((int) inboundSyncRow($conn, 'SELECT COUNT(*) AS total FROM engagement_chron_entries WHERE engagement_id = ?', [$fixtureIds['engagements']])['total'] === 1,
        'Redelivery must not create another Chron entry');

    // After a mailbox rebuild, all existing unknown mail is held for review,
    // while known imports/purges deduplicate even when the UIDs are different.
    $mailbox->validity++;
    $mailbox->messages = [1 => $mailbox->messages[9], 2 => $fixture('historical-import'), 3 => $fixture('historical-ignore'), 4 => $fixture('historical-rebuilt')];
    $mailbox->next = 5;
    $sync();
    expectMailSync($candidate(101, 1) === [] && $candidate(101, 2)['decision'] === 'pending' && $mailRow($mailbox->messages[2]) === [], 'Reset reconciles unknown history without resurrecting purged mail');
    expectMailSyncFailure(fn() => reconcileInboundMailboxMessage($worker, $mailbox, $key, 100, 2, 'import'), 'mailbox identity is invalid');
    $original = $mailbox->messages[2];
    $mailbox->messages[2] = $fixture('changed-identity');
    expectMailSyncFailure(fn() => reconcileInboundMailboxMessage($worker, $mailbox, $key, 101, 2, 'import'), 'Message identity changed');
    $mailbox->messages[2] = $original;
    $decision = reconcileInboundMailboxMessage($worker, $mailbox, $key, 101, 2, 'import');
    expectMailSync($decision['outcome'] === 'imported' && $mailRow($original)['status'] === 'pending', 'Explicit reconciliation imports only the selected message');
    $decision = reconcileInboundMailboxMessage($worker, $mailbox, $key, 101, 3, 'ignore');
    expectMailSync($decision['outcome'] === 'ignored' && $mailRow($mailbox->messages[3]) === [], 'Ignore records a durable receipt without mail content');
    $mailbox->messages[5] = $mailbox->messages[3];
    $mailbox->messages[6] = $fixture('after-reset');
    $mailbox->next = 7;
    $sync();
    expectMailSync($mailRow($mailbox->messages[5]) === [] && $mailRow($mailbox->messages[6]) !== [], 'Ignored mail stays ignored; fresh mail after reset imports normally');
    $mailbox->validity++;
    $mailbox->messages = [1 => $mailbox->messages[4]];
    $mailbox->next = 2;
    $sync();
    expectMailSync($candidate(101, 4)['decision'] === 'superseded' && $candidate(102, 1)['decision'] === 'pending', 'Rebuilt UIDs supersede stale reconciliation entries');
    $health = array_values(array_filter(inboundMailboxOperationalStates($conn), fn(array $row): bool => $row['mailbox_label'] === $label))[0];
    expectMailSync((int) $health['reconciliation_count'] === 1 && !(bool) $health['stale'], 'Operations shows only actionable history and a recent successful check');
    $mailbox->next = 1;
    expectMailSyncFailure($sync, 'UIDNEXT moved backwards');
    $mailbox->next = 2;

    // Quarantine must be durable before progressing, and rejected literals
    // require a fresh connection before later UIDs can be fetched.
    $mailbox->messages[2] = $fixture('oversize');
    $mailbox->messages[3] = $fixture('after-oversize');
    $mailbox->next = 4;
    $mailbox->failures[2] = new \Dnr\Infrastructure\ImapMessageRejectedException('Oversized literal');
    $worker->failQuarantine = true;
    expectMailSyncFailure($sync, 'Injected quarantine write failure');
    expectMailSync($mailbox->aborted && (int) $state()['scanned_uid'] === 1, 'Failed quarantine aborts the connection and does not skip the message');
    $mailbox->aborted = false;
    $sync();
    expectMailSync($mailbox->aborted && (int) $state()['scanned_uid'] === 2 && $mailRow($mailbox->messages[3]) === [], 'Stored quarantine advances only through the rejected message');
    expectMailSync(inboundSyncRow($conn, 'SELECT id FROM inbound_email_quarantine WHERE transport_key = ?', [$key . ':102:2']) !== [], 'Quarantine is actually stored');
    $mailbox->aborted = false;
    $sync();
    expectMailSync($mailRow($mailbox->messages[3]) !== [], 'Later mail proceeds after reconnecting');

    // Sparse UID space is traversed in bounded numeric windows.
    $mailbox->messages = [];
    $mailbox->next = 2501;
    $sync();
    expectMailSync((int) $state()['scanned_uid'] === 1003, 'A sparse scan advances at most 1000 UIDs per pass');
    $sync(); $sync();
    expectMailSync((int) $state()['scanned_uid'] === 2500, 'Empty UID gaps eventually reach the observed mailbox end');

    // A second worker/reconciliation cannot race the mailbox cursor.
    $lock = inboundMailboxLockName($key);
    $conn->execute_query('SELECT GET_LOCK(?, 0)', [$lock]);
    try {
        expectMailSync($sync() === 0, 'The advisory lock serializes consumers');
    } finally {
        $conn->execute_query('SELECT RELEASE_LOCK(?)', [$lock]);
    }
    echo "Mailbox synchronization tests passed (read flags, bounded discovery, retries, atomic rollback, purge receipts, UID resets, reconciliation, quarantine, locks, health, restricted grants).\n";
} finally {
    $worker->rollback();
    foreach ($fingerprints as $fingerprint) {
        $conn->execute_query('DELETE FROM inbound_email_messages WHERE deduplication_hash = ?', [$fingerprint]);
        $conn->execute_query('DELETE FROM inbound_email_import_receipts WHERE deduplication_hash = ?', [$fingerprint]);
    }
    $conn->execute_query('DELETE FROM inbound_email_quarantine WHERE transport_key LIKE ?', [$key . ':%']);
    $conn->execute_query('DELETE FROM inbound_mail_reconciliation WHERE mailbox_key = ?', [$key]);
    $conn->execute_query('DELETE FROM inbound_mailbox_state WHERE mailbox_key = ?', [$key]);
    if (isset($fixtureIds['engagements'])) {
        $conn->execute_query('DELETE FROM engagement_chron_entries WHERE engagement_id = ?', [$fixtureIds['engagements']]);
    }
    foreach (array_reverse($fixtureIds, true) as $table => $id) {
        $conn->execute_query("DELETE FROM {$table} WHERE id = ?", [$id]);
    }
    $worker->close();
}
