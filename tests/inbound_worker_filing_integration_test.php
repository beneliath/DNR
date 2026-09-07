<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Inbound worker filing tests skipped (requires disposable worker credentials).\n";
    exit(0);
}

$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/inbound_email_helpers.php';

function expectWorkerFiling(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Inbound worker filing: ' . $message);
    }
}

$workerPassword = configurationSecret('DNR_TEST_MAIL_INGEST_PASSWORD');
expectWorkerFiling($workerPassword !== '', 'The disposable worker password is required.');
$worker = new mysqli(
    (string) (getenv('DB_HOST') ?: 'db'), 'dnrmailingest', $workerPassword,
    (string) (getenv('MYSQL_DATABASE') ?: 'dnr')
);
$worker->set_charset('utf8mb4');
setDatabaseAuditContext($worker, null, 'Email Gateway');
expectWorkerFiling(
    str_starts_with($worker->query('SELECT CURRENT_USER() AS identity')->fetch_assoc()['identity'], 'dnrmailingest@'),
    'Filing must use the real restricted worker identity.'
);

$tables = [
    'contact_chron_entries' => 'contact_id',
    'organization_chron_entries' => 'organization_id',
    'engagement_chron_entries' => 'engagement_id',
    'booking_inquiry_chron_entries' => 'booking_inquiry_id',
];
foreach ($tables as $table => $column) {
    try {
        $worker->query("UPDATE {$table} SET id = id WHERE 1 = 0");
        throw new RuntimeException('The worker unexpectedly has Chron UPDATE permission.');
    } catch (mysqli_sql_exception $error) {
        expectWorkerFiling(in_array($error->getCode(), [1142, 1143], true), 'Expected UPDATE permission denial.');
    }
}

putenv('DNR_INBOUND_ADDRESS=worker-gateway@example.test');
putenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM=1');
putenv('DNR_INBOUND_TRUSTED_AUTH_SERVERS=');
putenv('DNR_INBOUND_ROUTING_KEY_FILE');
putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));
$suffix = bin2hex(random_bytes(6));
$sender = 'worker-' . $suffix . '@example.test';
$contactEmail = 'contact-' . $suffix . '@example.test';
$organizationEmail = 'office-' . $suffix . '@example.test';
$ids = [];
$messageIds = [];

try {
    // Only fixture creation/cleanup uses the web account. Every filing call uses $worker.
    $conn->execute_query("INSERT INTO users (username, email, email_verified_at, password, role, account_status)
        VALUES (?, ?, UTC_TIMESTAMP(), ?, 'editor', 'active')",
        ['worker-' . $suffix, $sender, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $ids['users'] = (int) $conn->insert_id;
    $conn->execute_query('INSERT INTO organizations (organization_name, email) VALUES (?, ?)',
        ['Worker organization ' . $suffix, $organizationEmail]);
    $ids['organizations'] = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email)
        VALUES (?, 'Worker', 'Contact', 'admin', ?)", [$ids['organizations'], $contactEmail]);
    $ids['contacts'] = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO engagements
        (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status, lifecycle_status)
        VALUES (?, ?, '2026-09-10', '2026-09-12', 'conference', 'confirmed', 'active')",
        [$ids['organizations'], 'Worker event ' . $suffix]);
    $ids['engagements'] = (int) $conn->insert_id;
    $conn->execute_query('INSERT INTO booking_inquiries (title, organization_id) VALUES (?, ?)',
        ['Worker inquiry ' . $suffix, $ids['organizations']]);
    $ids['booking_inquiries'] = (int) $conn->insert_id;

    $engagementMarker = applicationInboundMarker($ids['engagements']);
    $inquiryMarker = applicationInquiryInboundMarker($ids['booking_inquiries']);
    $cases = [
        ['event-only', 'unmatched@example.test', $engagementMarker,
            ['engagement_chron_entries' => $ids['engagements']]],
        ['event-participants', $contactEmail . ', ' . $organizationEmail, $engagementMarker,
            ['contact_chron_entries' => $ids['contacts'], 'organization_chron_entries' => $ids['organizations'],
                'engagement_chron_entries' => $ids['engagements']]],
        ['inquiry', 'unmatched@example.test', $inquiryMarker,
            ['booking_inquiry_chron_entries' => $ids['booking_inquiries']]],
    ];
    foreach ($cases as [$name, $recipients, $marker, $targets]) {
        foreach ([false, true] as $existing) {
            $fixtureId = $name . '-' . (int) $existing . '-' . $suffix . '@example.test';
            $payload = json_encode(['kind' => 'internal', 'from' => $sender, 'id' => hash('sha256', $fixtureId)], JSON_THROW_ON_ERROR);
            $key = hash_hmac('sha256', 'dnr:proton-sender-auth:key:v1', \Dnr\Security\InboundRoutingKey::bytes(), true);
            $assertion = 'v1.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.'
                . hash_hmac('sha256', "dnr:proton-sender-auth:v1\n" . $payload, $key);
            $raw = "From: {$sender}\r\nTo: {$recipients}\r\nCc: worker-gateway@example.test\r\n"
                . "Message-ID: <{$fixtureId}>\r\nSubject: Worker filing {$marker}\r\n"
                . "X-Dnr-Sender-Authentication: {$assertion}\r\n\r\nWorker regression body.";
            $stored = storeInboundEmailMessage($worker, 'file', $fixtureId, parseInboundEmail($raw));
            $messageId = $stored['id'];
            $messageIds[] = $messageId;
            if ($existing) {
                foreach ($targets as $table => $targetId) {
                    $conn->execute_query("INSERT INTO {$table} ({$tables[$table]}, inbound_email_message_id,
                        entry_text, created_by_username_snapshot) VALUES (?, ?, 'Preserve existing entry', 'Email Gateway')",
                        [$targetId, $messageId]);
                }
            }
            expectWorkerFiling(processInboundEmailMessage($worker, $messageId) === 'processed', $name . ' must file automatically.');
            foreach ($tables as $table => $column) {
                $rows = $worker->execute_query("SELECT {$column} AS target_id, entry_text, created_by,
                    created_by_username_snapshot FROM {$table} WHERE inbound_email_message_id = ?", [$messageId])->fetch_all(MYSQLI_ASSOC);
                expectWorkerFiling(count($rows) === (isset($targets[$table]) ? 1 : 0), $name . ' must file once per intended target.');
                if ($rows !== []) {
                    expectWorkerFiling((int) $rows[0]['target_id'] === $targets[$table]
                        && $rows[0]['created_by'] === null && $rows[0]['created_by_username_snapshot'] === 'Email Gateway',
                        'Filing must retain the target and gateway attribution.');
                    expectWorkerFiling($existing ? $rows[0]['entry_text'] === 'Preserve existing entry'
                        : str_contains($rows[0]['entry_text'], 'Worker regression body.'),
                        'Existing Chron text must remain unchanged; new entries must retain the body.');
                }
            }
            $redelivery = storeInboundEmailMessage($worker, 'file', 'retry-' . $fixtureId, parseInboundEmail($raw));
            expectWorkerFiling(!$redelivery['inserted'] && $redelivery['id'] === $messageId, 'Delivery retries must deduplicate.');
            try {
                processInboundEmailMessage($worker, $messageId);
                throw new RuntimeException('An already processed message was filed again.');
            } catch (InvalidArgumentException $expected) {
                expectWorkerFiling(str_contains($expected->getMessage(), 'already been processed'), 'Processed mail remains terminal.');
            }
        }
    }
    echo "Inbound worker filing tests passed (all four Chron types, event-only mail, existing entries, redelivery, restricted grants).\n";
} finally {
    $worker->rollback();
    foreach ($messageIds as $messageId) {
        foreach ($tables as $table => $column) {
            $conn->execute_query("DELETE FROM {$table} WHERE inbound_email_message_id = ?", [$messageId]);
        }
        $conn->execute_query('DELETE FROM inbound_email_messages WHERE id = ?', [$messageId]);
    }
    foreach (array_reverse($ids, true) as $table => $id) {
        $conn->execute_query("DELETE FROM {$table} WHERE id = ?", [$id]);
    }
    $worker->close();
}
