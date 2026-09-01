<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$readiness_reason = 'dependency_failure';
try {
    try {
        \Dnr\Security\InboundRoutingKey::bytes();
    } catch (Throwable $exception) {
        $readiness_reason = 'inbound_routing_key_unavailable';
        throw $exception;
    }
    define('DNR_DATABASE_FAILURES_THROW', true);
    require_once __DIR__ . '/bootstrap.php';
    $migration_directory = is_dir('/opt/dnr/migrations')
        ? '/opt/dnr/migrations'
        : dirname(__DIR__) . '/migrations';
    $migration_paths = glob($migration_directory . '/*.sql') ?: [];
    if (!$migration_paths) {
        $readiness_reason = 'migration_manifest_unavailable';
        throw new RuntimeException('Migration manifest unavailable.');
    }
    $ledger_result = $conn->query('SELECT migration_name, checksum, state FROM schema_migrations');
    if (!$ledger_result) {
        $readiness_reason = 'migration_ledger_unavailable';
        throw new RuntimeException('Migration ledger unavailable.');
    }
    $ledger = [];
    while ($row = $ledger_result->fetch_assoc()) {
        $ledger[(string) $row['migration_name']] = [
            'checksum' => (string) $row['checksum'],
            'state' => (string) $row['state'],
        ];
    }
    $manifest = [];
    foreach ($migration_paths as $migration_path) {
        $name = basename($migration_path);
        $manifest[$name] = true;
        $checksum = hash_file('sha256', $migration_path);
        $record = $ledger[$name] ?? null;
        $recorded_checksum = is_array($record) ? ($record['checksum'] ?? null) : null;
        if (!is_string($checksum)
            || !is_string($recorded_checksum)
            || ($record['state'] ?? null) !== 'applied'
            || !hash_equals($checksum, $recorded_checksum)
        ) {
            $readiness_reason = 'migration_checksum_mismatch';
            throw new RuntimeException('Migration ledger mismatch.');
        }
    }
    if (array_diff_key($ledger, $manifest) !== []) {
        $readiness_reason = 'migration_checksum_mismatch';
        throw new RuntimeException('Migration ledger contains files absent from the manifest.');
    }
    echo json_encode([
        'status' => 'ready',
        'version' => APP_VERSION,
        'request_id' => applicationRequestId(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    applicationLog('error', 'Readiness check failed', ['error' => $exception->getMessage()]);
    http_response_code(503);
    echo json_encode([
        'status' => 'not_ready',
        'reason' => $readiness_reason,
        'request_id' => applicationRequestId(),
    ], JSON_THROW_ON_ERROR);
}
