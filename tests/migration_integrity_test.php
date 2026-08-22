<?php

function expectMigrationIntegrity(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Migration integrity test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/20260822_architecture_integrity_hardening.sql');
$runner = file_get_contents($root . '/scripts/migrate.sh');
$healthCheck = file_get_contents($root . '/scripts/check_schema.php');
$readiness = file_get_contents($root . '/src/ready.php');

expectMigrationIntegrity(
    str_contains($migration, 'caller_user_id')
        && str_contains($migration, 'contacts')
        && str_contains($migration, 'updated_at')
        && str_contains($migration, 'CHECK'),
    'the hardening migration should install caller identity, contact concurrency, and reference-data constraints.'
);
expectMigrationIntegrity(
    str_contains($runner, 'Applied migration checksum mismatch')
        && str_contains($runner, 'Create a new migration instead of editing an applied migration.')
        && str_contains($runner, "sha256sum"),
    'the migration runner should reject changed, already-applied migrations.'
);
expectMigrationIntegrity(
    str_contains($healthCheck, "glob('/opt/dnr/migrations/*.sql')")
        && str_contains($healthCheck, 'hash_equals')
        && str_contains($readiness, 'migration_checksum_mismatch'),
    'health and readiness checks should verify the complete migration manifest.'
);

echo "Migration integrity tests passed.\n";
