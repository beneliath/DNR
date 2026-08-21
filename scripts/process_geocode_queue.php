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
$lock_path = sys_get_temp_dir() . '/dnr-geocoder-worker.lock';
$lock = fopen($lock_path, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another geocoder worker is already running.\n");
    exit(0);
}

do {
    $processed = 0;
    for ($index = 0; $index < $batch_size; $index++) {
        $conn->begin_transaction();
        $job_result = $conn->query(
            "SELECT address_hash, address_query, attempts
             FROM engagement_map_geocode_queue
             WHERE status IN ('pending', 'retry')
               AND next_attempt_at <= UTC_TIMESTAMP()
             ORDER BY next_attempt_at, created_at
             LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $job = $job_result ? $job_result->fetch_assoc() : null;
        if (!$job) {
            $conn->commit();
            break;
        }
        $mark = $conn->prepare(
            "UPDATE engagement_map_geocode_queue
             SET status = 'processing', attempts = attempts + 1, last_error = NULL
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
            $retry = $conn->prepare(
                "UPDATE engagement_map_geocode_queue
                 SET status = 'retry',
                     next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE),
                     last_error = ?
                 WHERE address_hash = ?"
            );
            $retry->bind_param('iss', $delay_minutes, $error, $job['address_hash']);
            $retry->execute();
            $retry->close();
            error_log('Background geocoding failed: ' . $error);
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
