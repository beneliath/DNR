<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/key_rotation_helpers.php';
$options = getopt('', ['table:', 'after:', 'limit:', 'apply', 'retire-check:']);
try {
    $result = isset($options['retire-check'])
        ? applicationKeyRetirementCheck($conn, (string) $options['retire-check'])
        : rotateApplicationKeyBatch($conn, (string) ($options['table'] ?? ''),
            (int) ($options['after'] ?? 0), (int) ($options['limit'] ?? 100), isset($options['apply']));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Key rotation stopped: ' . $exception->getMessage() . "\n");
    exit(1);
}
