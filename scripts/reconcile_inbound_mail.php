<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/inbound_ingestion_helpers.php';

// Run inside mail-ingest, which has the mailbox credentials and restricted DB
// grants. Listing is read-only; every mutation names one UID and its generation.
$options = getopt('', ['list', 'import:', 'ignore:', 'uid-validity:']);
$host = trim((string) getenv('DNR_IMAP_HOST'));
$security = strtolower(trim((string) (getenv('DNR_IMAP_SECURITY') ?: 'starttls')));
$port = (int) (getenv('DNR_IMAP_PORT') ?: ($security === 'tls' ? 993 : 1143));
$username = trim((string) getenv('DNR_IMAP_USERNAME'));
$mailbox = trim((string) (getenv('DNR_IMAP_MAILBOX') ?: 'INBOX'));
$key = inboundMailboxKey($host, $port, $username, $mailbox);
$conn = applicationDatabaseConnection();
try {
    if (!isset($options['import']) && !isset($options['ignore'])) {
        $state = inboundSyncRow($conn, 'SELECT mailbox_label, uid_validity, last_checked_at FROM inbound_mailbox_state WHERE mailbox_key = ?', [$key]);
        $result = $conn->execute_query('SELECT uid_validity, uid, sender_address, subject, sent_at, discovered_at
            FROM inbound_mail_reconciliation WHERE mailbox_key = ? AND decision = \'pending\'
            ORDER BY uid_validity DESC, uid LIMIT 100', [$key]);
        echo json_encode(['mailbox' => $state, 'pending_first_100' => $result->fetch_all(MYSQLI_ASSOC)], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
        exit(0);
    }
    if (isset($options['import'], $options['ignore'])) {
        throw new InvalidArgumentException('Choose either --import=UID or --ignore=UID.');
    }
    $decision = isset($options['import']) ? 'import' : 'ignore';
    $uid = filter_var($options[$decision], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $validity = filter_var($options['uid-validity'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if (!$uid || !$validity) {
        throw new InvalidArgumentException('Name a positive UID and --uid-validity from the reconciliation listing.');
    }
    $client = new \Dnr\Infrastructure\ImapClient($host, $port,
        $security, getenv('DNR_IMAP_VERIFY_PEER') !== '0',
        15, inboundEmailMaximumRawBytes());
    try {
        $client->connect($username, configurationSecret('DNR_IMAP_PASSWORD'), $mailbox);
        setDatabaseAuditContext($conn, null, 'Mail Reconciliation');
        echo json_encode(reconcileInboundMailboxMessage($conn, $client, $key, $validity, $uid, $decision)), "\n";
    } finally {
        $client->close();
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
