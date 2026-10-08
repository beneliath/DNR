<?php
declare(strict_types=1);

// Read-only synthetic SQL; never inserts or reads stored message bodies.
if (getenv('DNR_ACCOUNT_CAPACITY_TEST') !== '1') {
    echo "Account mail capacity check skipped (explicit local database opt-in required).\n";
    exit(0);
}
$source = getenv('DNR_ACCOUNT_TEST_SOURCE_ROOT') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/account_mail_helpers.php';

final class MailCapacityConnection extends mysqli {
    public function __construct(private mysqli $source) {}
    public function execute_query(string $query, ?array $params = null): mysqli_result|bool {
        $numbers = implode(' UNION ALL ', array_map(static fn(int $id): string => "SELECT $id AS id", range(1, 20)));
        // Each valid 9.75 MiB body expands to ~19.5 MiB JSON. The old buffered
        // list exhausted 512 MiB; metadata must stay small even with this batch.
        $fixture = "WITH platform_inbound_mail AS (SELECT id,'review' AS status,'' AS review_reason,
            '2026-10-08 00:00:00' AS received_at,
            JSON_OBJECT('message',JSON_OBJECT('sender_name','Fixture','sender_address','test@example.invalid',
                'subject',CONCAT('Capacity ',id),'body_text',REPEAT(CHAR(34),10223616))) AS payload
            FROM ($numbers) numbers) ";
        return $this->source->execute_query($fixture . $query, $params);
    }
}
$rows = platformMailReviewRows(new MailCapacityConnection($conn), 'review', 0);
if (count($rows) !== 20 || $rows[0]['subject'] !== 'Capacity 20' || isset($rows[0]['payload'])
    || memory_get_peak_usage(true) > 32 * 1024 * 1024) {
    throw new RuntimeException('Mail queue metadata exceeded its memory budget or changed content.');
}
echo 'PASS: 20 large-message previews; peak PHP bytes=' . memory_get_peak_usage(true) . "\n";
