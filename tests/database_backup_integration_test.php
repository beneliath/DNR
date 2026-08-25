<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_DESTRUCTIVE_BACKUP_TEST') !== 'isolated-restore'
) {
    echo "Database backup integration tests skipped (requires an explicitly isolated disposable restore database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/functions.php';
require_once $source_directory . '/database_backup_helpers.php';

function expectDatabaseBackupIntegration($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Database backup integration test failed: {$message}\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$suffix = bin2hex(random_bytes(4));
$username = 'backup_admin_' . $suffix;
$password_hash = password_hash('integration-password', PASSWORD_DEFAULT);

$user_stmt = $conn->prepare(
    "INSERT INTO users (username, password, role, auth_version)
     VALUES (?, ?, 'admin', 4)"
);
$user_stmt->bind_param('ss', $username, $password_hash);
$user_stmt->execute();
$user_id = (int) $conn->insert_id;
$user_stmt->close();

$organization_name = 'Backup Original ' . $suffix;
$organization_stmt = $conn->prepare(
    'INSERT INTO organizations (organization_name, notes) VALUES (?, ?)'
);
$organization_notes = "Binary-safe text \x00 remains data";
$organization_stmt->bind_param('ss', $organization_name, $organization_notes);
$organization_stmt->execute();
$organization_id = (int) $conn->insert_id;
$organization_stmt->close();

$engagement_title = 'Backup timestamp test ' . $suffix;
$engagement_stmt = $conn->prepare(
    "INSERT INTO engagements
        (organization_id, event_title, event_start_date, event_end_date,
         event_type, confirmation_status)
     VALUES (?, ?, '2026-09-10', '2026-09-11', 'conference', 'under_review')"
);
$engagement_stmt->bind_param('is', $organization_id, $engagement_title);
$engagement_stmt->execute();
$engagement_id = (int) $conn->insert_id;
$engagement_stmt->close();

$chron_created_at = '2025-03-04 12:34:56';
$chron_updated_at = '2025-03-05 13:45:01';
$chron_text = 'Timestamp preservation ' . $suffix;
$chron_stmt = $conn->prepare(
    'INSERT INTO engagement_chron_entries
        (engagement_id, entry_text, created_by, created_by_username_snapshot,
         updated_by, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$chron_stmt->bind_param(
    'isisiss',
    $engagement_id,
    $chron_text,
    $user_id,
    $username,
    $user_id,
    $chron_created_at,
    $chron_updated_at
);
$chron_stmt->execute();
$chron_entry_id = (int) $conn->insert_id;
$chron_stmt->close();

$backup_conn = databaseBackupConnection();
$expected_table_count = (int) $conn->query(
    "SELECT COUNT(*) AS table_count
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
)->fetch_assoc()['table_count'];
$backup_table_count = (int) $backup_conn->query(
    "SELECT COUNT(*) AS table_count
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
)->fetch_assoc()['table_count'];
expectDatabaseBackupIntegration(
    $backup_table_count === $expected_table_count,
    'the read-only backup identity should see every database table.'
);
$backup_is_read_only = false;
try {
    $backup_conn->query('DELETE FROM organizations WHERE 1 = 0');
} catch (mysqli_sql_exception $exception) {
    $backup_is_read_only = true;
}
expectDatabaseBackupIntegration(
    $backup_is_read_only,
    'the backup identity should not be able to mutate database data.'
);

$backup = createDatabaseBackup($backup_conn, 'integration-test', 16777216);
$backup_conn->close();
$backup_password = 'integration backup password';
$encrypted_backup = encryptDatabaseBackup($backup['path'], $backup_password, 16777216);
$decrypted_backup = decryptDatabaseBackup($encrypted_backup['path'], $backup_password, 16777216);
$schema = databaseBackupSchemaDescriptor($conn);
$inspection = inspectDatabaseBackup($decrypted_backup['path'], $schema, 16777216);
expectDatabaseBackupIntegration($inspection['row_count'] > 0, 'the test database should produce a non-empty backup.');

$conn->query("UPDATE organizations SET organization_name = 'Changed after backup' WHERE id = {$organization_id}");
$conn->query("INSERT INTO organizations (organization_name) VALUES ('Extra after backup {$suffix}')");
$conn->query(
    "UPDATE engagement_chron_entries
     SET entry_text = 'Changed after backup', updated_at = UTC_TIMESTAMP()
     WHERE id = {$chron_entry_id}"
);

$restored = restoreDatabaseBackup(
    $conn,
    $decrypted_backup['path'],
    $schema,
    ['id' => $user_id, 'username' => $username],
    16777216
);
expectDatabaseBackupIntegration(
    $restored['row_count'] === $inspection['row_count'],
    'restore should consume every backed-up row.'
);

$restored_organization = $conn->query(
    "SELECT organization_name, notes FROM organizations WHERE id = {$organization_id}"
)->fetch_assoc();
expectDatabaseBackupIntegration(
    $restored_organization['organization_name'] === $organization_name
        && $restored_organization['notes'] === $organization_notes,
    'restore should replace changed data with the original row values.'
);
$restored_chron_entry = $conn->query(
    "SELECT entry_text, created_at, updated_at
     FROM engagement_chron_entries
     WHERE id = {$chron_entry_id}"
)->fetch_assoc();
expectDatabaseBackupIntegration(
    $restored_chron_entry['entry_text'] === $chron_text
        && $restored_chron_entry['created_at'] === $chron_created_at
        && $restored_chron_entry['updated_at'] === $chron_updated_at,
    'restore should preserve Chron creation and update timestamps exactly.'
);
$extra_count = (int) $conn->query(
    "SELECT COUNT(*) AS row_count FROM organizations
     WHERE organization_name = 'Extra after backup {$suffix}'"
)->fetch_assoc()['row_count'];
expectDatabaseBackupIntegration($extra_count === 0, 'restore should remove rows created after the backup.');

$restored_user = $conn->query(
    "SELECT auth_version FROM users WHERE id = {$user_id}"
)->fetch_assoc();
expectDatabaseBackupIntegration(
    (int) $restored_user['auth_version'] === 5,
    'restore should advance auth_version to invalidate pre-restore sessions.'
);
$restore_audit_count = (int) $conn->query(
    "SELECT COUNT(*) AS row_count FROM security_audit_log
     WHERE event_type = 'database_restored' AND actor_username = '"
    . $conn->real_escape_string($username) . "'"
)->fetch_assoc()['row_count'];
expectDatabaseBackupIntegration($restore_audit_count === 1, 'restore should append a durable audit event.');

$rollback_marker = 'Rollback marker ' . $suffix;
$marker_stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
$marker_stmt->bind_param('s', $rollback_marker);
$marker_stmt->execute();
$marker_stmt->close();

$tampered_path = tempnam(sys_get_temp_dir(), 'dnr-backup-tampered-');
$tampered_contents = file_get_contents($backup['path']);
$tampered_contents = preg_replace('/"sha256":"[0-9a-f]{64}"/', '"sha256":"' . str_repeat('0', 64) . '"', $tampered_contents, 1);
file_put_contents($tampered_path, $tampered_contents);

try {
    restoreDatabaseBackup(
        $conn,
        $tampered_path,
        $schema,
        ['id' => $user_id, 'username' => $username],
        16777216
    );
    expectDatabaseBackupIntegration(false, 'a tampered backup should not restore.');
} catch (RuntimeException $exception) {
    expectDatabaseBackupIntegration(
        str_contains($exception->getMessage(), 'integrity check failed'),
        'the failed restore should report an integrity failure.'
    );
}

$marker_count = (int) $conn->query(
    "SELECT COUNT(*) AS row_count FROM organizations WHERE organization_name = '"
    . $conn->real_escape_string($rollback_marker) . "'"
)->fetch_assoc()['row_count'];
expectDatabaseBackupIntegration(
    $marker_count === 1,
    'a failed restore should roll back all deletes and inserts.'
);
$foreign_key_checks = (int) $conn->query('SELECT @@SESSION.foreign_key_checks AS enabled')->fetch_assoc()['enabled'];
expectDatabaseBackupIntegration($foreign_key_checks === 1, 'foreign-key checks should be restored after failure.');

unlink($backup['path']);
unlink($encrypted_backup['path']);
unlink($decrypted_backup['path']);
unlink($tampered_path);

echo "Database backup integration tests passed.\n";
