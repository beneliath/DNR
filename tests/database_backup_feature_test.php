<?php

function expectDatabaseBackupFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Database backup feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/database_maintenance.php');
$helpers = file_get_contents($root . '/src/database_backup_helpers.php');
$header = file_get_contents($root . '/src/templates/header.php');
$login = file_get_contents($root . '/src/login.php');
$compose = file_get_contents($root . '/docker-compose.yaml');

expectDatabaseBackupFeature(
    str_contains($page, 'requireAdmin();')
        && str_contains($page, 'requireValidCsrfToken();')
        && str_contains($page, 'password_verify')
        && str_contains($page, 'verifyAndConsumeTotp')
        && str_contains($page, 'consumeRecoveryCode'),
    'backup and restore must require admin authorization, CSRF validation, password re-entry, and a fresh second factor.'
);
expectDatabaseBackupFeature(
    str_contains($page, "'RESTORE DATABASE'")
        && str_contains($page, 'is_uploaded_file')
        && str_contains($page, 'databaseBackupMaximumBytes')
        && str_contains($page, 'name="backup_password"')
        && str_contains($page, 'encryptDatabaseBackup')
        && str_contains($page, 'decryptDatabaseBackup')
        && str_contains($page, 'session_unset()'),
    'restore must require explicit confirmation and an encryption password, validate the upload, enforce a size limit, and sign out the current session.'
);
expectDatabaseBackupFeature(
    str_contains($helpers, 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')
        && str_contains($helpers, 'sodium_crypto_secretstream_xchacha20poly1305_push')
        && str_contains($helpers, 'sodium_crypto_secretstream_xchacha20poly1305_pull')
        && str_contains($helpers, 'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL')
        && str_contains($helpers, "SET FOREIGN_KEY_CHECKS = 0")
        && str_contains($helpers, 'begin_transaction()')
        && str_contains($helpers, 'rollback()')
        && str_contains($helpers, 'auth_version = auth_version + 1')
        && str_contains($helpers, "'security_audit_log'")
        && str_contains($helpers, 'hash_equals(hash_final($hash)'),
    'backups must use password-based authenticated encryption, and restore must be atomic, invalidate sessions, preserve imported audit history, and verify archive integrity.'
);
expectDatabaseBackupFeature(
    str_contains($header, 'database_maintenance.php')
        && str_contains($header, '<span>Database</span>')
        && str_contains($login, 'database_restored'),
    'admins should have a Database navigation entry and receive a successful restore message after signing out.'
);
expectDatabaseBackupFeature(
    str_contains($compose, 'DNR_DATABASE_BACKUP_MAX_BYTES')
        && !str_contains($compose, 'MYSQL_ROOT_PASSWORD_FILE=/run/secrets'),
    'the feature should be size-configurable without exposing database-administrator credentials to the web service.'
);

echo "Database backup feature tests passed.\n";
