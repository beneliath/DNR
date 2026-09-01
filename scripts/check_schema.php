<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once '/var/www/html/config.php';

try {
    \Dnr\Security\InboundRoutingKey::bytes();
} catch (Throwable $exception) {
    fwrite(STDERR, "Inbound routing key is unavailable or invalid.\n");
    exit(1);
}

$migrationDirectory = '/opt/dnr/migrations';
$orderPath = $migrationDirectory . '/order.txt';
$migrationPaths = glob($migrationDirectory . '/*.sql') ?: [];
$orderedNames = is_readable($orderPath)
    ? array_values(array_filter(array_map(
        'trim',
        file($orderPath, FILE_IGNORE_NEW_LINES) ?: []
    ), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')))
    : [];
if (!$migrationPaths || !$orderedNames) {
    fwrite(STDERR, "Schema health check could not find the migration manifest.\n");
    exit(1);
}
$filesystemNames = array_map('basename', $migrationPaths);
sort($filesystemNames);
$uniqueOrderedNames = array_values(array_unique($orderedNames));
$sortedOrderedNames = $uniqueOrderedNames;
sort($sortedOrderedNames);
if (count($orderedNames) !== count($uniqueOrderedNames)
    || $filesystemNames !== $sortedOrderedNames
) {
    fwrite(STDERR, "Migration order manifest is incomplete or contains duplicates.\n");
    exit(1);
}

$ledger_result = $conn->query('SELECT migration_name, checksum, state FROM schema_migrations');
if (!$ledger_result) {
    fwrite(STDERR, "Schema health check could not inspect the migration ledger.\n");
    exit(1);
}
$ledger = [];
while ($row = $ledger_result->fetch_assoc()) {
    $ledger[(string) $row['migration_name']] = [
        'checksum' => (string) $row['checksum'],
        'state' => (string) $row['state'],
    ];
}

$manifest = [];
foreach ($orderedNames as $migrationName) {
    $migrationPath = $migrationDirectory . '/' . $migrationName;
    $manifest[$migrationName] = true;
    $expectedChecksum = hash_file('sha256', $migrationPath);
    $record = $ledger[$migrationName] ?? null;
    $recorded_checksum = is_array($record) ? ($record['checksum'] ?? null) : null;
    if (!is_string($expectedChecksum)
        || !is_string($recorded_checksum)
        || ($record['state'] ?? null) !== 'applied'
        || !hash_equals($expectedChecksum, $recorded_checksum)
    ) {
        fwrite(STDERR, "Migration ledger is incomplete or inconsistent for {$migrationName}.\n");
        exit(1);
    }
}
if (array_diff_key($ledger, $manifest) !== []) {
    fwrite(STDERR, "Migration ledger contains files absent from the manifest.\n");
    exit(1);
}
exit(0);
