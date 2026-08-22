<?php

function expectPasswordMigrationSurface(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Password migration surface test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$cli_script = file_get_contents($root . '/scripts/migrate_passwords.php');

expectPasswordMigrationSurface(
    !is_file($root . '/src/migrate_passwords.php'),
    'the legacy browser-accessible migration route should be removed.'
);
expectPasswordMigrationSurface(
    is_string($cli_script)
        && str_contains($cli_script, "PHP_SAPI !== 'cli'")
        && str_contains($cli_script, 'begin_transaction()')
        && str_contains($cli_script, 'password_get_info')
        && str_contains($cli_script, 'password_hash($password, PASSWORD_DEFAULT)')
        && str_contains($cli_script, 'must_change_password = 1')
        && str_contains($cli_script, 'auth_version = auth_version + 1')
        && str_contains($cli_script, '$conn->rollback()'),
    'legacy password conversion should exist only as an atomic, CLI-only operation.'
);

echo "Password migration surface tests passed.\n";
