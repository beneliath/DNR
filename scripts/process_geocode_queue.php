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
    if (!$conn->query(
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
    )) {
        throw new RuntimeException('Unable to reap exhausted geocoding jobs: ' . $conn->error);
    }
    for ($index = 0; $index < $batch_size; $index++) {
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start a geocoding-job claim: ' . $conn->error);
        }
        try {
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
            if (!$job_result) {
                throw new RuntimeException('Unable to select a geocoding job: ' . $conn->error);
            }
            $job = $job_result->fetch_assoc();
            if (!$job) {
                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to finish the empty geocoding-job claim: ' . $conn->error);
                }
                break;
            }
            $mark = $conn->prepare(
                "UPDATE engagement_map_geocode_queue
                 SET status = 'processing', attempts = attempts + 1,
                     processing_started_at = UTC_TIMESTAMP(), last_error = NULL
                 WHERE address_hash = ?"
            );
            if (!$mark) {
                throw new RuntimeException('Unable to prepare a geocoding-job claim: ' . $conn->error);
            }
            $mark->bind_param('s', $job['address_hash']);
            if (!$mark->execute() || $mark->affected_rows !== 1) {
                $mark_error = $mark->error;
                $mark->close();
                throw new RuntimeException('Unable to claim a geocoding job: ' . $mark_error);
            }
            $mark->close();
            if (!$conn->commit()) {
                throw new RuntimeException('Unable to commit a geocoding-job claim: ' . $conn->error);
            }
        } catch (Throwable $claim_exception) {
            $conn->rollback();
            throw $claim_exception;
        }

        try {
            $coordinates = geocodeEngagementMapAddress($job['address_query']);
            completeEngagementMapGeocodeJob(
                $conn,
                (string) $job['address_hash'],
                (string) $job['address_query'],
                $coordinates
            );
        } catch (Throwable $exception) {
            $attempts = (int) $job['attempts'] + 1;
            $delay_minutes = min(1440, 2 ** min(10, $attempts));
            $error = substr($exception->getMessage(), 0, 255);
            $next_status = $attempts >= $maximum_attempts ? 'failed' : 'retry';
            $retry_recorded = false;
            $retry_record_error = null;
            try {
                $retry = $conn->prepare(
                    "UPDATE engagement_map_geocode_queue
                     SET status = ?, processing_started_at = NULL,
                         next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE),
                         last_error = ?
                     WHERE address_hash = ?"
                );
                if (!$retry) {
                    throw new RuntimeException('Unable to prepare the geocoding retry: ' . $conn->error);
                }
                $retry->bind_param('siss', $next_status, $delay_minutes, $error, $job['address_hash']);
                if (!$retry->execute() || $retry->affected_rows !== 1) {
                    throw new RuntimeException('Unable to record the geocoding retry: ' . $retry->error);
                }
                $retry_recorded = true;
                $retry->close();
            } catch (Throwable $retry_exception) {
                $retry_record_error = substr($retry_exception->getMessage(), 0, 255);
            }
            applicationLog('error', 'Background geocoding failed', [
                'error' => $error,
                'address_hash' => $job['address_hash'],
                'attempts' => $attempts,
                'status' => $next_status,
                'retry_recorded' => $retry_recorded,
                'retry_record_error' => $retry_record_error,
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
