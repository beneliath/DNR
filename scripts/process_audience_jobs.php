<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/config.php';
require_once '/var/www/html/audience_job_worker.php';
require_once '/var/www/html/worker_health_helpers.php';

$loop = in_array('--loop', $argv, true);
do {
    try {
        $batches = processAudienceJobs($conn);
        recordWorkerHeartbeat('audience-jobs', true);
    } catch (Throwable $error) {
        applicationLog('error', 'Audience job worker failed', ['error' => $error->getMessage()]);
        recordWorkerHeartbeat('audience-jobs', false);
        exit(1); // The supervisor supplies a fresh database connection.
    }
    if ($loop) usleep($batches > 0 ? 100000 : 2000000);
} while ($loop);
