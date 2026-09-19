<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/native_backup_stream_helpers.php';
$mode = $argv[1] ?? '';
$input = $argv[2] ?? '';
$output = $argv[3] ?? '';
$password = rtrim((string) file_get_contents('/run/secrets/backup_password'), "\r\n");
if (!in_array($mode, ['encrypt', 'decrypt'], true) || $input === '' || $output === '') exit(64);
$createdOutput = false;
try {
    $reader = fopen($input === '-' ? 'php://stdin' : $input, 'rb');
    $writer = fopen($output === '-' ? 'php://stdout' : $output, $output === '-' ? 'wb' : 'xb');
    $createdOutput = $writer !== false && $output !== '-';
    if (!$reader || !$writer) throw new RuntimeException('Unable to open backup stream.');
    if ($output !== '-') chmod($output, 0600);
    transformNativeBackupStream($reader, $writer, $password, $mode === 'encrypt');
    fclose($reader); fclose($writer);
} catch (Throwable $error) {
    if ($createdOutput) @unlink($output);
    fwrite(STDERR, "Native backup cryptography failed; deployment must stop.\n");
    exit(1);
} finally { sodium_memzero($password); }
