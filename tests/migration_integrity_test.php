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
$performanceMigration = file_get_contents($root . '/migrations/20260822_functional_performance_improvements.sql');
$runner = file_get_contents($root . '/scripts/migrate.sh');
$orderManifest = file($root . '/migrations/order.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$privileges = file_get_contents($root . '/scripts/configure_database_privileges.sh');
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
    str_contains($runner, 'DNR_PRIVILEGE_SCRIPT')
        && str_contains($privileges, 'GRANT SELECT ON')
        && str_contains($privileges, 'calendar_feed_revision'),
    'fresh and upgraded deployments should grant the application read-only access to calendar revisions.'
);
expectMigrationIntegrity(
    str_contains($performanceMigration, "ENUM('pending', 'processing', 'retry', 'failed')")
        && str_contains($performanceMigration, 'processing_started_at')
        && str_contains($performanceMigration, 'presentation_time TIME')
        && str_contains($performanceMigration, 'due_date_overridden')
        && str_contains($performanceMigration, 'contact_photo_thumbnail')
        && str_contains($performanceMigration, 'profile_picture_thumbnail')
        && str_contains($performanceMigration, 'calendar_feed_revision'),
    'the functional-performance migration should install recoverable jobs, native times, thumbnails, checklist overrides, and feed revisions.'
);
expectMigrationIntegrity(
    str_contains($runner, 'Applied migration checksum mismatch')
        && str_contains($runner, 'Create a new migration instead of editing an applied migration.')
        && str_contains($runner, "sha256sum"),
    'the migration runner should reject changed, already-applied migrations.'
);
expectMigrationIntegrity(
    str_contains($healthCheck, "'/order.txt'")
        && str_contains($healthCheck, "glob(\$migrationDirectory . '/*.sql')")
        && str_contains($healthCheck, 'hash_equals')
        && str_contains($readiness, 'migration_checksum_mismatch'),
    'health and readiness checks should verify the complete migration manifest.'
);
$orderedNames = array_values(array_filter(
    array_map('trim', $orderManifest),
    static fn(string $line): bool => !str_starts_with($line, '#')
));
$migrationNames = array_map('basename', glob($root . '/migrations/*.sql') ?: []);
sort($migrationNames);
$sortedOrderedNames = array_values(array_unique($orderedNames));
sort($sortedOrderedNames);
$twoFactorPosition = array_search('20260814_add_two_factor_authentication.sql', $orderedNames, true);
$lastLoginPosition = array_search('20260814_add_last_login_at.sql', $orderedNames, true);
expectMigrationIntegrity(
    count($orderedNames) === count($sortedOrderedNames)
        && $migrationNames === $sortedOrderedNames
        && ($orderedNames[0] ?? null) === '20260813_baseline.sql'
        && is_int($twoFactorPosition)
        && is_int($lastLoginPosition)
        && $twoFactorPosition < $lastLoginPosition,
    'the explicit dependency order should list every migration exactly once.'
);

echo "Migration integrity tests passed.\n";
