<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$source = is_file('/var/www/html/bootstrap.php') ? '/var/www/html' : dirname(__DIR__) . '/src';
require_once $source . '/bootstrap.php';
require_once $source . '/persistent_file_migration_helpers.php';
$locked = false;
try {
    if (!is_writable(persistentFileRoot())) throw new RuntimeException('Persistent storage is not writable by the PHP user.');
    $locked = (int) $conn->query("SELECT GET_LOCK('dnr_persistent_file_migration', 60)")->fetch_row()[0] === 1;
    if (!$locked) throw new RuntimeException('Another file migration is running.');
    $count = migratePersistentFiles($conn);
    fwrite(STDOUT, "Persistent storage migration completed: {$count} files converted.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Persistent storage migration failed: {$exception->getMessage()}\n");
    exit(1);
} finally {
    if ($locked) $conn->query("DO RELEASE_LOCK('dnr_persistent_file_migration')");
}
