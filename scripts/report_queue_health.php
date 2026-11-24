<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/config.php';
require_once '/var/www/html/queue_health_helpers.php';
try {
    $health = applicationQueueHealth($conn);
    $health['account_key'] = (string) (getenv('DNR_ACCOUNT_KEY') ?: 'primary');
    echo json_encode($health, JSON_THROW_ON_ERROR) . "\n";
    exit($health['healthy'] ? 0 : 1);
} catch (Throwable $error) {
    // No database error, credential or recipient information in external alerts.
    fwrite(STDERR, "Queue health is unavailable.\n");
    exit(2);
}
