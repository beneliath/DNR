<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Inbound queue integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/inbound_queue_helpers.php';
$conn = applicationDatabaseConnection();
function expectInboxQueue(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$prefix = 'inboxpaging' . bin2hex(random_bytes(6));
$conn->begin_transaction();
try {
    $insert = $conn->prepare("INSERT INTO inbound_email_messages
        (transport, transport_key, deduplication_hash, gateway_address, sender_address,
         to_addresses, cc_addresses, subject, received_at, body_text, attachment_names, raw_headers, status)
        VALUES ('file', ?, ?, 'test@example.test', 'sender@example.test', '[]', '[]', ?,
                '2026-09-01 12:00:00', ?, '[]', '', ?)");
    $ids = [];
    for ($i = 0; $i < 132; $i++) {
        $key = $prefix . $i;
        $hash = hash('sha256', $key, true);
        $subject = $prefix . ' message ' . $i;
        $body = $i === 0 ? 'Literal 100%_complete' : 'Ordinary text';
        $status = $i < 130 ? 'review' : 'processed';
        $insert->bind_param('sssss', $key, $hash, $subject, $body, $status);
        $insert->execute();
        $ids[] = (int) $conn->insert_id;
    }
    $insert->close();
    $seen = [];
    for ($page = 1; $page <= 7; $page++) {
        $result = fetchInboundMailQueue($conn, 'review', $prefix, 'oldest', $page);
        expectInboxQueue($result['page_size'] === 20 && $result['total'] === 130 && $result['pages'] === 7, 'The default 20 rows per page must cover all 130 messages in seven pages');
        array_push($seen, ...array_map('intval', array_column($result['messages'], 'id')));
    }
    expectInboxQueue($seen === array_slice($ids, 0, 130), 'Every message, including those beyond 100, must be reachable once with stable equal-time ordering');
    $newest = fetchInboundMailQueue($conn, 'all', $prefix, 'newest', 1);
    expectInboxQueue((int) $newest['messages'][0]['id'] === $ids[131] && $newest['total'] === 132, 'All-status newest order must reverse the stable ID tie-break');
    expectInboxQueue(fetchInboundMailQueue($conn, 'review', $prefix, 'oldest', -1)['page'] === 1, 'Negative pages clamp to the first page');
    expectInboxQueue(fetchInboundMailQueue($conn, 'review', $prefix, 'oldest', PHP_INT_MAX)['page'] === 7, 'Out-of-range pages clamp to the last nonempty page');
    expectInboxQueue(fetchInboundMailQueue($conn, 'processed', $prefix, 'oldest', 1)['total'] === 2, 'Search and status must combine');
    $literal = fetchInboundMailQueue($conn, 'review', '100%_complete', 'oldest', 1);
    expectInboxQueue($literal['total'] === 1 && (int) $literal['messages'][0]['id'] === $ids[0], 'Percent and underscore search must be literal, including matches in the body');
    $empty = fetchInboundMailQueue($conn, 'review', $prefix . 'missing', 'oldest', 99);
    expectInboxQueue($empty['page'] === 1 && $empty['pages'] === 1 && $empty['messages'] === [], 'Empty queues need a stable first page without stale results');
    echo "Inbound queue database integration tests passed (132 fixtures, full reachability, sorting, search, page bounds).\n";
} finally {
    $conn->rollback();
}
