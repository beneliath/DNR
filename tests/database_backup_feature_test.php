<?php

function expectDatabaseBackupFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Database backup feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/database_maintenance.php');
$exporter = file_get_contents($root . '/src/database_backup_service.php');
$endpoint = file_get_contents($root . '/scripts/backup_endpoint.php');
$client = file_get_contents($root . '/src/database_backup_client.php');
$helpers = file_get_contents($root . '/src/database_backup_helpers.php');
$header = file_get_contents($root . '/src/templates/header.php');
$login = file_get_contents($root . '/src/login.php');
$compose = file_get_contents($root . '/docker-compose.yaml');
$workflow = file_get_contents($root . '/.github/workflows/ci.yml');
$privileges = file_get_contents($root . '/scripts/configure_database_privileges.sh');
$restore_command = file_get_contents($root . '/scripts/restore_database.php');
$readme = file_get_contents($root . '/README.md');
$styles = file_get_contents($root . '/src/assets/css/pages/database_maintenance.css');

expectDatabaseBackupFeature(
    str_contains($page, 'requireAdmin();')
        && str_contains($page, 'requireValidCsrfToken();')
        && str_contains($exporter, 'PasswordPolicy::verify')
        && str_contains($exporter, 'verifyAndConsumeTotp')
        && str_contains($exporter, 'consumeRecoveryCode')
        && str_contains($exporter, "GET_LOCK('dnr_database_backup_export', 0)")
        && str_contains($exporter, "RELEASE_LOCK('dnr_database_backup_export')")
        && str_contains($page, 'ignore_user_abort(true)')
        && strpos($exporter, 'databaseBackupConnection()') > strpos($exporter, 'verifyAndConsumeTotp')
        && str_contains($page, 'requestEncryptedDatabaseBackup')
        && !str_contains($page, 'databaseBackupConnection')
        && !str_contains(explode('  backup:', $compose)[0], 'MYSQL_BACKUP'),
    'backup and restore must require admin authorization, CSRF validation, password re-entry, and a fresh second factor.'
);
expectDatabaseBackupFeature(
    !str_contains($page, 'is_uploaded_file')
        && str_contains($page, 'name="backup_password"')
        && str_contains($exporter, 'encryptDatabaseBackup')
        && str_contains($restore_command, "PHP_SAPI !== 'cli'")
        && str_contains($restore_command, "\$confirmation !== 'RESTORE'")
        && str_contains($restore_command, 'DNR_BACKUP_PASSWORD_FILE')
        && str_contains($restore_command, 'decryptDatabaseBackup')
        && str_contains($restore_command, 'restoreDatabaseBackup'),
    'the web process should export only, while restore requires an explicit one-shot CLI confirmation and password secret.'
);
expectDatabaseBackupFeature(
    strpos($exporter, 'encryptDatabaseBackup(') < strpos($exporter, "unlink(\$backup['path'])")
        && str_contains($endpoint, "readfile(\$backup['path'])")
        && str_contains($client, 'DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC'),
    'the isolated exporter must remove plaintext and the web client must accept only encrypted downloads.'
);
expectDatabaseBackupFeature(
    str_contains($helpers, 'SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')
        && str_contains($helpers, 'SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE')
        && str_contains($helpers, 'SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE')
        && str_contains($helpers, 'DNRBACKUP-ENC-1')
        && str_contains($helpers, 'DNRBACKUP-ENC-2')
        && str_contains($page, 'DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES')
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
    str_contains($helpers, "configurationSecret('MYSQL_BACKUP_PASSWORD')")
        && str_contains($compose, 'MYSQL_BACKUP_USER: dnrbackup')
        && str_contains($compose, 'MYSQL_BACKUP_PASSWORD_FILE: /run/secrets/dnr_mysql_backup_password')
        && str_contains($compose, '- dnr_mysql_backup_password')
        && str_contains($workflow, 'secrets/mysql_backup_password')
        && str_contains($privileges, "CREATE USER IF NOT EXISTS '\${backup_user}'@'%'")
        && str_contains($privileges, "GRANT SELECT ON \`\${MYSQL_DATABASE}\`.* TO '\${backup_user}'@'%';")
        && !str_contains($privileges, "GRANT SELECT, INSERT, UPDATE, DELETE ON \`\${MYSQL_DATABASE}\`.* TO '\${backup_user}'@'%';"),
    'web exports should use a dedicated full-schema read-only database identity without weakening the application account.'
);
expectDatabaseBackupFeature(
    str_contains($header, 'database_maintenance.php')
        && str_contains($header, '<span>Database</span>')
        && str_contains($login, 'database_restored'),
    'admins should have a Database navigation entry and receive a successful restore message after signing out.'
);
expectDatabaseBackupFeature(
    str_contains($compose, 'DNR_DATABASE_BACKUP_MAX_BYTES')
        && str_contains($compose, 'profiles: [maintenance]')
        && str_contains($compose, 'MYSQL_USER: dnrmaintenance')
        && str_contains($compose, 'MYSQL_PASSWORD_FILE: /run/secrets/dnr_mysql_maintenance_password')
        && str_contains($readme, '### Exact database restore runbook')
        && str_contains($readme, 'pre-restore-safety.sql')
        && str_contains($readme, '/backups/restore.dnrbackup RESTORE'),
    'restore should use an isolated maintenance credential and document the complete safety-dump workflow.'
);
expectDatabaseBackupFeature(
    str_contains($compose, 'migrator:')
        && str_contains($compose, 'condition: service_completed_successfully')
        && str_contains($compose, 'DNR_PRIVILEGE_SCRIPT: /opt/dnr/bin/configure_database_privileges')
        && !str_contains($compose, 'docker-entrypoint-initdb.d/00-init.sql'),
    'fresh databases should run the same ordered migrations and grants as upgrades.'
);
expectDatabaseBackupFeature(
    preg_match('/\.database-maintenance-card\s*\{[^}]*background:\s*transparent\s*!important;/s', $styles) === 1,
    'database-maintenance cards should reveal the shared page background instead of a legacy black fill.'
);
expectDatabaseBackupFeature(
    str_contains($page, '<body class="database-maintenance-body">')
        && str_contains($page, '<main class="container database-maintenance-page">')
        && str_contains($page, 'page-heading database-maintenance-heading')
        && preg_match('/\.database-maintenance-page\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $styles) === 1
        && preg_match('/\.database-maintenance-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $styles) === 1
        && preg_match('/\.database-maintenance-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $styles) === 1,
    'database maintenance should use the Dashboard browsing width, heading scale, and footer alignment.'
);
expectDatabaseBackupFeature(
    preg_match('/\.database-maintenance-grid\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);/s', $styles) === 1
        && preg_match('/@media \(max-width:\s*1000px\)\s*\{\s*\.database-maintenance-grid\s*\{[^}]*grid-template-columns:\s*1fr;/s', $styles) === 1,
    'Export Backup and Restore Procedure should share a responsive two-column row.'
);

echo "Database backup feature tests passed.\n";
