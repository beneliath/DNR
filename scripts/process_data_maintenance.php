<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/config.php';
require_once '/var/www/html/data_maintenance_helpers.php';
require_once '/var/www/html/worker_health_helpers.php';

$loop = in_array('--loop', $argv, true);
do {
    $ok = false;
    try {
        $state = maintainApplicationData($conn);
        echo json_encode($state, JSON_THROW_ON_ERROR) . "\n";
        $ok = true;
    } catch (Throwable $exception) {
        applicationLog('error', 'Data maintenance failed', ['error' => $exception->getMessage()]);
    }
    recordWorkerHeartbeat('data-maintenance', $ok);
    // Let the supervisor recreate a lost database connection instead of reusing it forever.
    if (!$ok) exit(1);
    if ($loop) sleep(60);
} while ($loop);
exit($ok ? 0 : 1);
