<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The geocoder worker is available only from the CLI.\n");
    exit(1);
}

require_once '/var/www/html/config.php';
require_once '/var/www/html/functions.php';
require_once '/var/www/html/map_helpers.php';

$loop = in_array('--loop', $argv, true);
$batch_size = max(1, min(50, (int) (getenv('DNR_GEOCODER_BATCH_SIZE') ?: 10)));
$idle_seconds = max(5, min(300, (int) (getenv('DNR_GEOCODER_IDLE_SECONDS') ?: 30)));
$lease_seconds = max(60, min(3600, (int) (getenv('DNR_GEOCODER_LEASE_SECONDS') ?: 600)));
$maximum_attempts = max(1, min(100, (int) (getenv('DNR_GEOCODER_MAX_ATTEMPTS') ?: 8)));
$lock_path = sys_get_temp_dir() . '/dnr-geocoder-worker.lock';
$lock = fopen($lock_path, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another geocoder worker is already running.\n");
    exit(0);
}

do {
    $processed = 0;
    // A worker can disappear after consuming its final attempt. Reap those
    // expired leases (and any legacy exhausted ready rows) so they do not
    // remain permanently stuck in a runnable-looking state.
    $conn->query(
        "UPDATE engagement_map_geocode_queue
         SET status = 'failed', processing_started_at = NULL,
             last_error = COALESCE(last_error, 'Maximum geocoding attempts reached')
         WHERE attempts >= {$maximum_attempts}
           AND (
                status IN ('pending', 'retry')
                OR (status = 'processing'
                    AND processing_started_at <= DATE_SUB(
                        UTC_TIMESTAMP(), INTERVAL {$lease_seconds} SECOND
                    ))
           )"
    );
    for ($index = 0; $index < $batch_size; $index++) {
        $conn->begin_transaction();
        $job_result = $conn->query(
            "SELECT address_hash, address_query, attempts
             FROM engagement_map_geocode_queue
             WHERE attempts < {$maximum_attempts}
               AND (
                    (status IN ('pending', 'retry') AND next_attempt_at <= UTC_TIMESTAMP())
                    OR (status = 'processing' AND processing_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$lease_seconds} SECOND))
               )
             ORDER BY status = 'processing' DESC, next_attempt_at, created_at
             LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $job = $job_result ? $job_result->fetch_assoc() : null;
        if (!$job) {
            $conn->commit();
            break;
        }
        $mark = $conn->prepare(
            "UPDATE engagement_map_geocode_queue
             SET status = 'processing', attempts = attempts + 1,
                 processing_started_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE address_hash = ?"
        );
        $mark->bind_param('s', $job['address_hash']);
        $mark->execute();
        $mark->close();
        $conn->commit();

        try {
            $coordinates = geocodeEngagementMapAddress($job['address_query']);
            $latitude = $coordinates['latitude'] ?? null;
            $longitude = $coordinates['longitude'] ?? null;
            $lookup_status = $coordinates === null ? 'not_found' : 'found';
            $save = $conn->prepare(
                'INSERT INTO engagement_map_geocodes
                    (address_hash, address_query, latitude, longitude, lookup_status, geocoded_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    address_query = VALUES(address_query), latitude = VALUES(latitude),
                    longitude = VALUES(longitude), lookup_status = VALUES(lookup_status),
                    geocoded_at = VALUES(geocoded_at)'
            );
            $save->bind_param(
                'ssdds',
                $job['address_hash'],
                $job['address_query'],
                $latitude,
                $longitude,
                $lookup_status
            );
            $save->execute();
            $save->close();
            $delete = $conn->prepare(
                'DELETE FROM engagement_map_geocode_queue WHERE address_hash = ?'
            );
            $delete->bind_param('s', $job['address_hash']);
            $delete->execute();
            $delete->close();
        } catch (Throwable $exception) {
            $attempts = (int) $job['attempts'] + 1;
            $delay_minutes = min(1440, 2 ** min(10, $attempts));
            $error = substr($exception->getMessage(), 0, 255);
            $next_status = $attempts >= $maximum_attempts ? 'failed' : 'retry';
            $retry = $conn->prepare(
                "UPDATE engagement_map_geocode_queue
                 SET status = ?, processing_started_at = NULL,
                     next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE),
                     last_error = ?
                 WHERE address_hash = ?"
            );
            $retry->bind_param('siss', $next_status, $delay_minutes, $error, $job['address_hash']);
            $retry->execute();
            $retry->close();
            applicationLog('error', 'Background geocoding failed', [
                'error' => $error,
                'address_hash' => $job['address_hash'],
                'attempts' => $attempts,
                'status' => $next_status,
            ]);
        }

        $processed++;
        if ($index + 1 < $batch_size) {
            usleep(1100000);
        }
    }

    if ($loop && $processed === 0) {
        sleep($idle_seconds);
    }
} while ($loop);

flock($lock, LOCK_UN);
fclose($lock);
