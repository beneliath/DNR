<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once '/var/www/html/config.php';

$migration_paths = glob('/opt/dnr/migrations/*.sql') ?: [];
if (!$migration_paths) {
    fwrite(STDERR, "Schema health check could not find the migration manifest.\n");
    exit(1);
}

$ledger_result = $conn->query('SELECT migration_name, checksum FROM schema_migrations');
if (!$ledger_result) {
    fwrite(STDERR, "Schema health check could not inspect the migration ledger.\n");
    exit(1);
}
$ledger = [];
while ($row = $ledger_result->fetch_assoc()) {
    $ledger[(string) $row['migration_name']] = (string) $row['checksum'];
}

$manifest = [];
foreach ($migration_paths as $migration_path) {
    $migration_name = basename($migration_path);
    $manifest[$migration_name] = true;
    $expected_checksum = hash_file('sha256', $migration_path);
    $recorded_checksum = $ledger[$migration_name] ?? null;
    if (!is_string($expected_checksum)
        || $recorded_checksum === null
        || !hash_equals($expected_checksum, $recorded_checksum)
    ) {
        fwrite(STDERR, "Migration ledger is incomplete or inconsistent for {$migration_name}.\n");
        exit(1);
    }
}
if (array_diff_key($ledger, $manifest) !== []) {
    fwrite(STDERR, "Migration ledger contains files absent from the manifest.\n");
    exit(1);
}
exit(0);
