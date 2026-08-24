<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This maintenance command is available only from the CLI.\n");
    exit(1);
}

$backup_path = $argv[1] ?? '';
$confirmation = $argv[2] ?? '';
if (in_array($backup_path, ['-h', '--help'], true)) {
    fwrite(STDOUT, "Usage: restore_database.php /backups/file.dnrbackup RESTORE\n");
    fwrite(STDOUT, "Stop the web and geocoder services, create a safety dump, then run this command through the maintenance Compose profile.\n");
    exit(0);
}
if ($backup_path === '' || !is_file($backup_path) || !is_readable($backup_path)) {
    fwrite(STDERR, "Usage: restore_database.php /backups/file.dnrbackup RESTORE\n");
    exit(64);
}
if ($confirmation !== 'RESTORE') {
    fwrite(STDERR, "Restore not started. Pass the literal confirmation word RESTORE as the second argument.\n");
    exit(64);
}

$password_file = trim((string) (getenv('DNR_BACKUP_PASSWORD_FILE') ?: ''));
if ($password_file === '' || !is_file($password_file) || !is_readable($password_file)) {
    fwrite(STDERR, "DNR_BACKUP_PASSWORD_FILE must identify a readable file.\n");
    exit(64);
}
$backup_password = (string) file_get_contents($password_file);
if ($backup_password === '') {
    fwrite(STDERR, "The backup password file is empty.\n");
    exit(64);
}

require_once '/var/www/html/config.php';
require_once '/var/www/html/functions.php';
require_once '/var/www/html/database_backup_helpers.php';

$maximum_bytes = databaseBackupMaximumBytes();
$decrypted = null;
$restore_lock_acquired = false;
try {
    $lock_result = $conn->query("SELECT GET_LOCK('dnr_database_restore', 0) AS acquired");
    $restore_lock_acquired = $lock_result
        && (int) ($lock_result->fetch_assoc()['acquired'] ?? 0) === 1;
    if (!$restore_lock_acquired) {
        throw new RuntimeException('Another database maintenance operation is already running.');
    }
    $decrypted = decryptDatabaseBackup($backup_path, $backup_password, $maximum_bytes);
    $schema = databaseBackupSchemaDescriptor($conn);
    inspectDatabaseBackup($decrypted['path'], $schema, $maximum_bytes);
    $inspection = restoreDatabaseBackup(
        $conn,
        $decrypted['path'],
        $schema,
        ['id' => 0, 'username' => 'maintenance-cli'],
        $maximum_bytes
    );
    fwrite(STDOUT, sprintf(
        "Database restore completed: %d rows across %d tables. All existing sessions were invalidated.\n",
        $inspection['row_count'],
        $inspection['table_count']
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, "Database restore failed: " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if (is_array($decrypted) && isset($decrypted['path'])) {
        @unlink($decrypted['path']);
    }
    if ($restore_lock_acquired) {
        $conn->query("SELECT RELEASE_LOCK('dnr_database_restore')");
    }
}
