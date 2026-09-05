<?php
// Encrypt a native SQL/gzip recovery archive without loading it into PHP memory.
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/database_backup_helpers.php';
$mode = $argv[1] ?? '';
$input = $argv[2] ?? '';
$output = $argv[3] ?? '';
$password = rtrim((string) file_get_contents('/run/secrets/backup_password'), "\r\n");
if ($input === '' || $output === '' || !is_file($input) || strlen($password) < 16) exit(64);
try {
    // Native archives use host storage; their capacity is not the browser's 512 MiB budget.
    $result = $mode === 'encrypt'
        ? encryptDatabaseBackup($input, $password, max(1, (int) filesize($input)))
        : ($mode === 'decrypt' ? decryptDatabaseBackup($input, $password, max(1, (int) filesize($input))) : null);
    if (!is_array($result) || !rename($result['path'], $output)) throw new RuntimeException('Native backup cryptography failed');
    chmod($output, 0600);
} catch (Throwable $error) {
    fwrite(STDERR, "Native backup cryptography failed; deployment must stop.\n");
    exit(1);
} finally { sodium_memzero($password); }
