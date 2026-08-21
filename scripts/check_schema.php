<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once '/var/www/html/config.php';

$required_migration = '20260821_security_performance_hardening.sql';
$stmt = $conn->prepare(
    'SELECT 1 FROM schema_migrations WHERE migration_name = ? LIMIT 1'
);
if (!$stmt) {
    fwrite(STDERR, "Schema health check could not inspect the migration ledger.\n");
    exit(1);
}
$stmt->bind_param('s', $required_migration);
$stmt->execute();
$current = (bool) $stmt->get_result()->fetch_row();
$stmt->close();
if (!$current) {
    fwrite(STDERR, "Required migration {$required_migration} has not been applied.\n");
    exit(1);
}
exit(0);
