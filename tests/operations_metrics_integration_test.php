<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Operations metrics integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/operations_helpers.php';

function expectOperationsMetric(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Operations metric integration test failed: {$message}\n");
        exit(1);
    }
}

$marker = 'operations-metric-' . bin2hex(random_bytes(8));
$before = countRecentFailedAuthentications($conn);
$conn->begin_transaction();
$insert = $conn->prepare(
    'INSERT INTO security_audit_log
        (event_category, event_type, details)
     VALUES (\'login\', ?, ?)'
);
try {
    foreach (['successful_login', 'logout', 'failed_login'] as $event_type) {
        $insert->bind_param('ss', $event_type, $marker);
        $insert->execute();
    }
    expectOperationsMetric(
        countRecentFailedAuthentications($conn) === $before + 1,
        'only failed_login events should increase the failed-authentication metric.'
    );
} finally {
    $insert->close();
    $conn->rollback();
}

echo "Operations metrics integration tests passed.\n";
