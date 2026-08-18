<?php

require_once __DIR__ . '/../src/database_backup_helpers.php';

function expectDatabaseBackup($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Database backup helper test failed: {$message}\n");
        exit(1);
    }
}

function writeTestDatabaseBackup(
    $path,
    array $schema,
    array $rows,
    $version = DNR_DATABASE_BACKUP_VERSION
) {
    $counts = array_fill_keys(array_column($schema, 'name'), 0);
    foreach ($rows as $row) {
        $counts[$row['table']]++;
    }

    $tables = [];
    foreach ($schema as $table) {
        $table['row_count'] = $counts[$table['name']];
        $tables[] = $table;
    }
    $header = [
        'type' => 'header',
        'format' => DNR_DATABASE_BACKUP_FORMAT,
        'version' => $version,
        'created_at' => '2026-08-18T12:00:00Z',
        'application_version' => 'test',
        'schema_fingerprint' => databaseBackupSchemaFingerprint($tables),
        'tables' => $tables,
    ];

    $handle = fopen($path, 'wb');
    $bytes = 0;
    $hash = hash_init('sha256');
    databaseBackupWriteLine($handle, $header, $bytes, 1048576, $hash);
    foreach ($rows as $row) {
        databaseBackupWriteLine(
            $handle,
            ['type' => 'row', 'table' => $row['table'], 'values' => $row['values']],
            $bytes,
            1048576,
            $hash
        );
    }
    databaseBackupWriteLine(
        $handle,
        ['type' => 'end', 'row_count' => count($rows), 'sha256' => hash_final($hash)],
        $bytes,
        1048576
    );
    fclose($handle);
}

$schema = [
    [
        'name' => 'users',
        'engine' => 'InnoDB',
        'columns' => [
            ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment', 'collation' => null],
            ['name' => 'username', 'type' => 'varchar(50)', 'nullable' => false, 'default' => null, 'extra' => '', 'collation' => 'utf8mb4_0900_ai_ci'],
            ['name' => 'secret', 'type' => 'binary(3)', 'nullable' => true, 'default' => null, 'extra' => '', 'collation' => null],
        ],
    ],
    [
        'name' => 'security_audit_log',
        'engine' => 'InnoDB',
        'columns' => [
            ['name' => 'id', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment', 'collation' => null],
            ['name' => 'details', 'type' => 'varchar(255)', 'nullable' => true, 'default' => null, 'extra' => '', 'collation' => 'utf8mb4_0900_ai_ci'],
        ],
    ],
];

$encoded = databaseBackupEncodedValues(
    ['id' => '7', 'username' => 'admin', 'secret' => "\x00\xFF\x10"],
    ['id', 'username', 'secret']
);
$decoded = databaseBackupDecodedValues($encoded, 3);
expectDatabaseBackup($decoded === ['7', 'admin', "\x00\xFF\x10"], 'binary values should round-trip exactly.');
expectDatabaseBackup(
    databaseBackupDecodedValues([null], 1) === [null],
    'SQL NULL should remain distinct from an empty string.'
);

$timestamp_schema = [[
    'name' => 'timestamped_rows',
    'engine' => 'InnoDB',
    'columns' => [
        ['name' => 'id', 'type' => 'int', 'nullable' => false, 'default' => null, 'extra' => 'auto_increment', 'collation' => null],
        ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => false, 'default' => 'CURRENT_TIMESTAMP', 'extra' => 'DEFAULT_GENERATED', 'collation' => null],
        ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => false, 'default' => 'CURRENT_TIMESTAMP', 'extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP', 'collation' => null],
        ['name' => 'computed_value', 'type' => 'int', 'nullable' => true, 'default' => null, 'extra' => 'STORED GENERATED', 'collation' => null],
    ],
]];
expectDatabaseBackup(
    databaseBackupExportColumnNames($timestamp_schema[0]) === ['id', 'created_at', 'updated_at'],
    'current backups should include default-generated timestamps but omit computed columns.'
);
expectDatabaseBackup(
    databaseBackupExportColumnNames(
        $timestamp_schema[0],
        DNR_DATABASE_BACKUP_LEGACY_VERSION
    ) === ['id'],
    'legacy backups should retain their original generated-column interpretation.'
);

$legacy_backup_path = tempnam(sys_get_temp_dir(), 'dnr-legacy-backup-test-');
writeTestDatabaseBackup(
    $legacy_backup_path,
    $timestamp_schema,
    [['table' => 'timestamped_rows', 'values' => [base64_encode('9')]]],
    DNR_DATABASE_BACKUP_LEGACY_VERSION
);
$legacy_columns = null;
$legacy_inspection = inspectDatabaseBackup(
    $legacy_backup_path,
    $timestamp_schema,
    1048576,
    static function ($table, $columns) use (&$legacy_columns) {
        $legacy_columns = $columns;
    }
);
expectDatabaseBackup(
    $legacy_inspection['row_count'] === 1 && $legacy_columns === ['id'],
    'version 1 backups should remain readable with their original exported columns.'
);

$backup_path = tempnam(sys_get_temp_dir(), 'dnr-backup-test-');
writeTestDatabaseBackup($backup_path, $schema, [
    ['table' => 'users', 'values' => $encoded],
    ['table' => 'security_audit_log', 'values' => [base64_encode('1'), null]],
]);

$consumed_rows = [];
$inspection = inspectDatabaseBackup(
    $backup_path,
    $schema,
    1048576,
    static function ($table, $columns, $values) use (&$consumed_rows) {
        $consumed_rows[] = [$table['name'], $columns, $values];
    }
);
expectDatabaseBackup($inspection['row_count'] === 2, 'the inspector should count every row.');
expectDatabaseBackup($inspection['table_count'] === 2, 'the inspector should count every table.');
expectDatabaseBackup(
    $consumed_rows[0][0] === 'users' && $consumed_rows[1][0] === 'security_audit_log',
    'rows should be passed to the restore consumer in table order.'
);

$encryption_password = 'correct horse battery staple';
$encrypted = encryptDatabaseBackup($backup_path, $encryption_password, 1048576);
expectDatabaseBackup(
    file_get_contents($encrypted['path'], false, null, 0, strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC))
        === DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC,
    'encrypted backups should use the versioned DNR encrypted-container header.'
);
$decrypted = decryptDatabaseBackup($encrypted['path'], $encryption_password, 1048576);
expectDatabaseBackup(
    hash_file('sha256', $decrypted['path']) === hash_file('sha256', $backup_path),
    'authenticated encryption should round-trip the complete backup exactly.'
);

try {
    decryptDatabaseBackup($encrypted['path'], 'incorrect backup password', 1048576);
    expectDatabaseBackup(false, 'an incorrect encryption password should be rejected.');
} catch (RuntimeException $exception) {
    expectDatabaseBackup(
        str_contains($exception->getMessage(), 'password is incorrect'),
        'an incorrect password should return a safe authentication error.'
    );
}

$encrypted_tampered_path = tempnam(sys_get_temp_dir(), 'dnr-encrypted-backup-test-');
$encrypted_tampered = file_get_contents($encrypted['path']);
$tamper_index = strlen($encrypted_tampered) - 3;
$encrypted_tampered[$tamper_index] = chr(ord($encrypted_tampered[$tamper_index]) ^ 1);
file_put_contents($encrypted_tampered_path, $encrypted_tampered);
try {
    decryptDatabaseBackup($encrypted_tampered_path, $encryption_password, 1048576);
    expectDatabaseBackup(false, 'tampered ciphertext should be rejected.');
} catch (RuntimeException $exception) {
    expectDatabaseBackup(
        str_contains($exception->getMessage(), 'encrypted file is damaged'),
        'authenticated encryption should detect ciphertext tampering.'
    );
}

$mismatched_schema = $schema;
$mismatched_schema[0]['columns'][1]['type'] = 'varchar(100)';
try {
    inspectDatabaseBackup($backup_path, $mismatched_schema, 1048576);
    expectDatabaseBackup(false, 'a schema mismatch should be rejected.');
} catch (RuntimeException $exception) {
    expectDatabaseBackup(
        str_contains($exception->getMessage(), 'different database schema'),
        'a schema mismatch should provide an actionable message.'
    );
}

$tampered_path = tempnam(sys_get_temp_dir(), 'dnr-backup-test-');
$tampered = file_get_contents($backup_path);
$tampered = str_replace(base64_encode('admin'), base64_encode('other'), $tampered);
file_put_contents($tampered_path, $tampered);
try {
    inspectDatabaseBackup($tampered_path, $schema, 1048576);
    expectDatabaseBackup(false, 'a tampered backup should be rejected.');
} catch (RuntimeException $exception) {
    expectDatabaseBackup(
        str_contains($exception->getMessage(), 'integrity check failed'),
        'tampering should be detected by the integrity check.'
    );
}

unlink($backup_path);
unlink($legacy_backup_path);
unlink($tampered_path);
unlink($encrypted['path']);
unlink($decrypted['path']);
unlink($encrypted_tampered_path);

echo "Database backup helper tests passed.\n";
