<?php
// This file lives outside the everyday web document root. Only the internal
// exporter vhost exposes it; it independently authenticates every export.
declare(strict_types=1);
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: '/var/www/html') . '/bootstrap.php';
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: '/var/www/html') . '/database_backup_service.php';
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$backup = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/export.php') {
        http_response_code(404);
        exit;
    }
    $body = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($body) > 16384) throw new InvalidArgumentException('Backup request is too large.');
    $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new InvalidArgumentException('Invalid backup request.');
    ignore_user_abort(true);
    set_time_limit(300);
    $backup = createAuthenticatedDatabaseExport($conn, $request, APP_VERSION);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $backup['size']);
    readfile($backup['path']);
} catch (Throwable $exception) {
    $status = in_array($exception->getCode(), [403, 409], true) ? $exception->getCode() : 503;
    if ($exception instanceof InvalidArgumentException || $exception instanceof JsonException) $status = 400;
    http_response_code($status);
    header('Content-Type: application/json');
    // Never return database errors, credentials, or internal paths to callers.
    echo json_encode(['error' => $status === 403 ? 'Your administrator password or authentication code was not accepted.'
        : ($status === 409 ? 'Another database backup is already in progress. Try again after it finishes.'
        : 'The database backup could not be created. Check the exporter configuration and try again.')]);
    applicationLog('error', 'Isolated database export failed', ['status' => $status]);
} finally {
    if ($backup) @unlink($backup['path']);
}
