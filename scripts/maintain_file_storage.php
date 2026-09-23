<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/file_storage_maintenance_helpers.php';
require_once '/var/www/html/presentation_chunk_helpers.php';
try {
    cleanupPresentationChunks();
    $result = in_array('--prune', $argv, true)
        ? prunePersistentFiles($conn, in_array('--apply', $argv, true))
        : checkPersistentStorageBatch($conn);
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'File storage maintenance failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
