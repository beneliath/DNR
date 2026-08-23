<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Geocoder worker integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/map_helpers.php';

function expectGeocoderWorkerIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Geocoder worker integration test failed: {$message}\n");
        exit(1);
    }
}

$suffix = bin2hex(random_bytes(4));
$hashes = [];
register_shutdown_function(static function () use ($conn, &$hashes): void {
    foreach ($hashes as $hash) {
        try {
            $stmt = $conn->prepare('DELETE FROM engagement_map_geocode_queue WHERE address_hash = ?');
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('DELETE FROM engagement_map_geocodes WHERE address_hash = ?');
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $exception) {
            fwrite(STDERR, "Geocoder integration cleanup failed: {$exception->getMessage()}\n");
        }
    }
});

$successfulAddress = 'Worker geocode success ' . $suffix;
$successfulHash = engagementMapAddressHash($successfulAddress);
$hashes[] = $successfulHash;
expectGeocoderWorkerIntegration(
    queueEngagementMapAddress($conn, $successfulAddress),
    'the worker fixture should be queued.'
);
$processing = $conn->prepare(
    "UPDATE engagement_map_geocode_queue SET status = 'processing' WHERE address_hash = ?"
);
$processing->bind_param('s', $successfulHash);
$processing->execute();
$processing->close();
completeEngagementMapGeocodeJob(
    $conn,
    $successfulHash,
    $successfulAddress,
    ['latitude' => 32.7767, 'longitude' => -96.7970]
);
$completed = $conn->prepare(
    'SELECT g.lookup_status, q.address_hash AS queued_hash
     FROM engagement_map_geocodes g
     LEFT JOIN engagement_map_geocode_queue q ON q.address_hash = g.address_hash
     WHERE g.address_hash = ?'
);
$completed->bind_param('s', $successfulHash);
$completed->execute();
$completedRow = $completed->get_result()->fetch_assoc();
$completed->close();
expectGeocoderWorkerIntegration(
    $completedRow !== null
        && $completedRow['lookup_status'] === 'found'
        && $completedRow['queued_hash'] === null,
    'the worker should atomically store a result and acknowledge its queue item.'
);

$failedAddress = 'Worker geocode rollback ' . $suffix;
$failedHash = engagementMapAddressHash($failedAddress);
$hashes[] = $failedHash;
expectGeocoderWorkerIntegration(
    queueEngagementMapAddress($conn, $failedAddress),
    'the rollback fixture should be queued.'
);
$processing = $conn->prepare(
    "UPDATE engagement_map_geocode_queue SET status = 'processing' WHERE address_hash = ?"
);
$processing->bind_param('s', $failedHash);
$processing->execute();
$processing->close();
$completionFailed = false;
try {
    completeEngagementMapGeocodeJob(
        $conn,
        $failedHash,
        str_repeat('x', 1001),
        ['latitude' => 32.7767, 'longitude' => -96.7970]
    );
} catch (Throwable) {
    $completionFailed = true;
}
$rolledBack = $conn->prepare(
    'SELECT q.status,
        (SELECT COUNT(*) FROM engagement_map_geocodes g WHERE g.address_hash = ?) AS result_count
     FROM engagement_map_geocode_queue q WHERE q.address_hash = ?'
);
$rolledBack->bind_param('ss', $failedHash, $failedHash);
$rolledBack->execute();
$rolledBackRow = $rolledBack->get_result()->fetch_assoc();
$rolledBack->close();
expectGeocoderWorkerIntegration(
    $completionFailed
        && $rolledBackRow !== null
        && $rolledBackRow['status'] === 'processing'
        && (int) $rolledBackRow['result_count'] === 0,
    'a failed result write should roll back without losing the claimed queue item.'
);

echo "Geocoder worker integration tests passed.\n";
