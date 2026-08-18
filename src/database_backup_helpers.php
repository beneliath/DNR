<?php

const DNR_DATABASE_BACKUP_FORMAT = 'dnr-database-backup';
const DNR_DATABASE_BACKUP_VERSION = 1;
const DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC = "DNRBACKUP-ENC-1\n";
const DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES = 65536;

function databaseBackupMaximumBytes() {
    $configured = filter_var(
        getenv('DNR_DATABASE_BACKUP_MAX_BYTES') ?: null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1048576, 'max_range' => 1073741824]]
    );

    return $configured ?: 67108864;
}

function databaseBackupMaximumSizeLabel($bytes) {
    $megabytes = (int) floor(((int) $bytes) / 1048576);
    return $megabytes . ' MB';
}

function databaseBackupMaximumEncryptedBytes($maximum_plaintext_bytes) {
    $maximum_plaintext_bytes = (int) $maximum_plaintext_bytes;
    $frame_count = max(
        1,
        (int) ceil($maximum_plaintext_bytes / DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES)
    );

    return strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC)
        + SODIUM_CRYPTO_PWHASH_SALTBYTES
        + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        + $maximum_plaintext_bytes
        + ($frame_count * (4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES));
}

function databaseBackupDeriveEncryptionKey($password, $salt) {
    return sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
        (string) $password,
        $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
    );
}

function databaseBackupWriteBytes($handle, $bytes) {
    $length = strlen($bytes);
    $written = fwrite($handle, $bytes);
    if ($written !== $length) {
        throw new RuntimeException('Unable to write the encrypted database backup.');
    }
}

function databaseBackupReadExactBytes($handle, $length) {
    $value = '';
    while (strlen($value) < $length) {
        $chunk = fread($handle, $length - strlen($value));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('The encrypted database backup is incomplete.');
        }
        $value .= $chunk;
    }

    return $value;
}

function encryptDatabaseBackup($plaintext_path, $password, $maximum_plaintext_bytes = null) {
    $maximum_plaintext_bytes = $maximum_plaintext_bytes ?: databaseBackupMaximumBytes();
    if (!is_string($password) || strlen($password) < 12) {
        throw new InvalidArgumentException('The backup encryption password must contain at least 12 characters.');
    }

    $plaintext_size = @filesize($plaintext_path);
    if ($plaintext_size === false || $plaintext_size < 1 || $plaintext_size > $maximum_plaintext_bytes) {
        throw new RuntimeException('The database backup cannot be encrypted at its current size.');
    }

    $encrypted_path = tempnam(sys_get_temp_dir(), 'dnr-encrypted-backup-');
    if ($encrypted_path === false) {
        throw new RuntimeException('Unable to create a temporary encrypted backup file.');
    }
    $input = fopen($plaintext_path, 'rb');
    $output = fopen($encrypted_path, 'wb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($encrypted_path);
        throw new RuntimeException('Unable to create the encrypted database backup.');
    }

    $key = null;
    $stream_state = null;
    try {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = databaseBackupDeriveEncryptionKey($password, $salt);
        [$stream_state, $stream_header] =
            sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        databaseBackupWriteBytes($output, DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC);
        databaseBackupWriteBytes($output, $salt);
        databaseBackupWriteBytes($output, $stream_header);

        $current_chunk = fread($input, DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES);
        if ($current_chunk === false || $current_chunk === '') {
            throw new RuntimeException('The database backup is empty.');
        }
        while (true) {
            $next_chunk = fread($input, DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES);
            if ($next_chunk === false) {
                throw new RuntimeException('Unable to read the database backup for encryption.');
            }
            $is_final = $next_chunk === '' && feof($input);
            $tag = $is_final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                $stream_state,
                $current_chunk,
                '',
                $tag
            );
            databaseBackupWriteBytes($output, pack('N', strlen($ciphertext)));
            databaseBackupWriteBytes($output, $ciphertext);

            if ($is_final) {
                break;
            }
            $current_chunk = $next_chunk;
        }

        fclose($input);
        fclose($output);
        $input = null;
        $output = null;
        $encrypted_size = filesize($encrypted_path);
        if ($encrypted_size === false
            || $encrypted_size > databaseBackupMaximumEncryptedBytes($maximum_plaintext_bytes)
        ) {
            throw new RuntimeException('The encrypted database backup exceeds the configured size limit.');
        }

        return ['path' => $encrypted_path, 'size' => $encrypted_size];
    } catch (Throwable $exception) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($encrypted_path);
        throw $exception;
    } finally {
        if (is_string($key)) {
            sodium_memzero($key);
        }
        if (is_string($stream_state)) {
            sodium_memzero($stream_state);
        }
    }
}

function decryptDatabaseBackup($encrypted_path, $password, $maximum_plaintext_bytes = null) {
    $maximum_plaintext_bytes = $maximum_plaintext_bytes ?: databaseBackupMaximumBytes();
    $maximum_encrypted_bytes = databaseBackupMaximumEncryptedBytes($maximum_plaintext_bytes);
    $encrypted_size = @filesize($encrypted_path);
    $minimum_size = strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC)
        + SODIUM_CRYPTO_PWHASH_SALTBYTES
        + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        + 4
        + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    if ($encrypted_size === false
        || $encrypted_size < $minimum_size
        || $encrypted_size > $maximum_encrypted_bytes
    ) {
        throw new RuntimeException('Select a supported encrypted DNR backup within the configured size limit.');
    }

    $plaintext_path = tempnam(sys_get_temp_dir(), 'dnr-decrypted-backup-');
    if ($plaintext_path === false) {
        throw new RuntimeException('Unable to create a temporary restore file.');
    }
    $input = fopen($encrypted_path, 'rb');
    $output = fopen($plaintext_path, 'wb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($plaintext_path);
        throw new RuntimeException('The encrypted DNR backup could not be opened.');
    }

    $key = null;
    $stream_state = null;
    try {
        $magic = databaseBackupReadExactBytes($input, strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC));
        if (!hash_equals(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC, $magic)) {
            throw new RuntimeException('The selected file is not an encrypted DNR backup.');
        }
        $salt = databaseBackupReadExactBytes($input, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $stream_header = databaseBackupReadExactBytes(
            $input,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        );
        $key = databaseBackupDeriveEncryptionKey((string) $password, $salt);
        $stream_state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(
            $stream_header,
            $key
        );

        $plaintext_bytes = 0;
        $saw_final = false;
        while (!$saw_final) {
            $length_bytes = databaseBackupReadExactBytes($input, 4);
            $length = unpack('Nlength', $length_bytes)['length'];
            $maximum_frame_length = DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES
                + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                || $length > $maximum_frame_length
            ) {
                throw new RuntimeException('The encrypted backup contains an invalid frame.');
            }

            $ciphertext = databaseBackupReadExactBytes($input, $length);
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $stream_state,
                $ciphertext
            );
            if ($pulled === false) {
                throw new RuntimeException('The encrypted backup authentication failed.');
            }
            [$plaintext, $tag] = $pulled;
            if (!in_array($tag, [
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            ], true)) {
                throw new RuntimeException('The encrypted backup contains an unsupported frame.');
            }
            $plaintext_bytes += strlen($plaintext);
            if ($plaintext_bytes > $maximum_plaintext_bytes) {
                throw new RuntimeException('The decrypted backup exceeds the configured size limit.');
            }
            databaseBackupWriteBytes($output, $plaintext);
            $saw_final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }

        $trailing_byte = fread($input, 1);
        if ($trailing_byte !== '' || !feof($input)) {
            throw new RuntimeException('The encrypted backup contains trailing data.');
        }
        if ($plaintext_bytes < 1) {
            throw new RuntimeException('The decrypted backup is empty.');
        }

        fclose($input);
        fclose($output);
        $input = null;
        $output = null;
        return ['path' => $plaintext_path, 'size' => $plaintext_bytes];
    } catch (Throwable $exception) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($plaintext_path);
        if (str_contains($exception->getMessage(), 'not an encrypted DNR backup')) {
            throw $exception;
        }
        throw new RuntimeException(
            'The backup password is incorrect or the encrypted file is damaged.'
        );
    } finally {
        if (is_string($key)) {
            sodium_memzero($key);
        }
        if (is_string($stream_state)) {
            sodium_memzero($stream_state);
        }
    }
}

function databaseBackupJson(array $value) {
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function databaseBackupIdentifier($identifier) {
    if (!is_string($identifier) || preg_match('/\A[A-Za-z0-9_]+\z/', $identifier) !== 1) {
        throw new RuntimeException('The backup contains an invalid database identifier.');
    }

    return '`' . $identifier . '`';
}

function databaseBackupSchemaDescriptor(mysqli $conn) {
    $tables_result = $conn->query(
        "SELECT TABLE_NAME, ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY CASE WHEN TABLE_NAME = 'security_audit_log' THEN 1 ELSE 0 END,
                  TABLE_NAME"
    );
    if (!$tables_result) {
        throw new RuntimeException('Unable to inspect the database tables.');
    }

    $tables = [];
    while ($table = $tables_result->fetch_assoc()) {
        $table_name = (string) $table['TABLE_NAME'];
        $table_identifier = databaseBackupIdentifier($table_name);
        $create_result = $conn->query("SHOW CREATE TABLE {$table_identifier}");
        if (!$create_result) {
            throw new RuntimeException('Unable to inspect a database table definition.');
        }
        $create_row = $create_result->fetch_assoc();
        $create_sql = (string) ($create_row['Create Table'] ?? '');
        if ($create_sql === '') {
            throw new RuntimeException('Unable to inspect a database table definition.');
        }
        // MySQL includes the next generated identifier in SHOW CREATE TABLE.
        // It is data state rather than schema state, so omit it from compatibility checks.
        $normalized_create_sql = preg_replace('/ AUTO_INCREMENT=[0-9]+\b/', '', $create_sql);

        $columns_stmt = $conn->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        if (!$columns_stmt) {
            throw new RuntimeException('Unable to inspect the database columns.');
        }
        $columns_stmt->bind_param('s', $table_name);
        if (!$columns_stmt->execute()) {
            $columns_stmt->close();
            throw new RuntimeException('Unable to inspect the database columns.');
        }

        $columns = [];
        $columns_result = $columns_stmt->get_result();
        while ($column = $columns_result->fetch_assoc()) {
            $column_name = (string) $column['COLUMN_NAME'];
            databaseBackupIdentifier($column_name);
            $columns[] = [
                'name' => $column_name,
                'type' => (string) $column['COLUMN_TYPE'],
                'nullable' => $column['IS_NULLABLE'] === 'YES',
                'default' => $column['COLUMN_DEFAULT'],
                'extra' => (string) $column['EXTRA'],
                'collation' => $column['COLLATION_NAME'],
            ];
        }
        $columns_stmt->close();

        if (!$columns) {
            throw new RuntimeException('A database table has no restorable columns.');
        }

        $tables[] = [
            'name' => $table_name,
            'engine' => (string) $table['ENGINE'],
            'definition' => $normalized_create_sql,
            'columns' => $columns,
        ];
    }

    if (!$tables) {
        throw new RuntimeException('The database does not contain any tables to back up.');
    }

    return $tables;
}

function databaseBackupSchemaFingerprint(array $tables) {
    $schema = [];
    foreach ($tables as $table) {
        $schema[] = [
            'name' => $table['name'] ?? null,
            'engine' => $table['engine'] ?? null,
            'definition' => $table['definition'] ?? null,
            'columns' => $table['columns'] ?? null,
        ];
    }

    return hash('sha256', databaseBackupJson($schema));
}

function databaseBackupExportColumnNames(array $table) {
    $columns = [];
    foreach ($table['columns'] as $column) {
        $extra = strtolower((string) ($column['extra'] ?? ''));
        if (str_contains($extra, 'generated')) {
            continue;
        }
        $columns[] = (string) $column['name'];
    }

    if (!$columns) {
        throw new RuntimeException('A database table has no restorable columns.');
    }

    return $columns;
}

function databaseBackupEncodedValues(array $row, array $column_names) {
    $values = [];
    foreach ($column_names as $column_name) {
        if (!array_key_exists($column_name, $row) || $row[$column_name] === null) {
            $values[] = null;
            continue;
        }
        $values[] = base64_encode((string) $row[$column_name]);
    }

    return $values;
}

function databaseBackupDecodedValues(array $encoded_values, $expected_count) {
    if (count($encoded_values) !== (int) $expected_count) {
        throw new RuntimeException('A backup row does not match its table definition.');
    }

    $values = [];
    foreach ($encoded_values as $encoded_value) {
        if ($encoded_value === null) {
            $values[] = null;
            continue;
        }
        if (!is_string($encoded_value)) {
            throw new RuntimeException('A backup row contains an invalid value.');
        }
        $decoded = base64_decode($encoded_value, true);
        if ($decoded === false) {
            throw new RuntimeException('A backup row contains an invalid encoded value.');
        }
        $values[] = $decoded;
    }

    return $values;
}

function databaseBackupWriteLine($handle, array $record, &$bytes_written, $maximum_bytes, $hash = null) {
    $line = databaseBackupJson($record) . "\n";
    $next_size = (int) $bytes_written + strlen($line);
    if ($next_size > (int) $maximum_bytes) {
        throw new RuntimeException(
            'The database backup exceeds the configured maximum size of '
            . databaseBackupMaximumSizeLabel($maximum_bytes) . '.'
        );
    }
    if (fwrite($handle, $line) !== strlen($line)) {
        throw new RuntimeException('Unable to write the database backup.');
    }
    if ($hash !== null) {
        hash_update($hash, $line);
    }
    $bytes_written = $next_size;
}

function createDatabaseBackup(mysqli $conn, $application_version, $maximum_bytes = null) {
    $maximum_bytes = $maximum_bytes ?: databaseBackupMaximumBytes();
    $temporary_path = tempnam(sys_get_temp_dir(), 'dnr-backup-');
    if ($temporary_path === false) {
        throw new RuntimeException('Unable to create a temporary backup file.');
    }

    $handle = fopen($temporary_path, 'wb');
    if ($handle === false) {
        @unlink($temporary_path);
        throw new RuntimeException('Unable to create a temporary backup file.');
    }

    $transaction_started = false;
    try {
        if (!$conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
            throw new RuntimeException('Unable to prepare a consistent database snapshot.');
        }
        if (!$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY)) {
            throw new RuntimeException('Unable to start a consistent database snapshot.');
        }
        $transaction_started = true;

        $tables = databaseBackupSchemaDescriptor($conn);
        $total_rows = 0;
        foreach ($tables as &$table) {
            if (strcasecmp((string) $table['engine'], 'InnoDB') !== 0) {
                throw new RuntimeException('Every database table must use InnoDB for a safe backup and restore.');
            }
            $table_identifier = databaseBackupIdentifier($table['name']);
            $count_result = $conn->query("SELECT COUNT(*) AS row_count FROM {$table_identifier}");
            if (!$count_result) {
                throw new RuntimeException('Unable to count a database table for backup.');
            }
            $table['row_count'] = (int) $count_result->fetch_assoc()['row_count'];
            $total_rows += $table['row_count'];
        }
        unset($table);

        $header = [
            'type' => 'header',
            'format' => DNR_DATABASE_BACKUP_FORMAT,
            'version' => DNR_DATABASE_BACKUP_VERSION,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'application_version' => (string) $application_version,
            'schema_fingerprint' => databaseBackupSchemaFingerprint($tables),
            'tables' => $tables,
        ];

        $hash = hash_init('sha256');
        $bytes_written = 0;
        databaseBackupWriteLine($handle, $header, $bytes_written, $maximum_bytes, $hash);

        $written_rows = 0;
        foreach ($tables as $table) {
            $column_names = databaseBackupExportColumnNames($table);
            $column_sql = implode(', ', array_map('databaseBackupIdentifier', $column_names));
            $table_identifier = databaseBackupIdentifier($table['name']);
            $rows = $conn->query(
                "SELECT {$column_sql} FROM {$table_identifier}",
                MYSQLI_USE_RESULT
            );
            if (!$rows) {
                throw new RuntimeException('Unable to read a database table for backup.');
            }
            while ($row = $rows->fetch_assoc()) {
                databaseBackupWriteLine(
                    $handle,
                    [
                        'type' => 'row',
                        'table' => $table['name'],
                        'values' => databaseBackupEncodedValues($row, $column_names),
                    ],
                    $bytes_written,
                    $maximum_bytes,
                    $hash
                );
                $written_rows++;
            }
            $rows->free();
        }

        if ($written_rows !== $total_rows) {
            throw new RuntimeException('The database changed while the backup was being created.');
        }

        $footer = [
            'type' => 'end',
            'row_count' => $written_rows,
            'sha256' => hash_final($hash),
        ];
        databaseBackupWriteLine($handle, $footer, $bytes_written, $maximum_bytes);
        fclose($handle);
        $handle = null;
        $conn->commit();
        $transaction_started = false;

        return [
            'path' => $temporary_path,
            'created_at' => $header['created_at'],
            'row_count' => $written_rows,
            'table_count' => count($tables),
            'size' => $bytes_written,
        ];
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($transaction_started) {
            $conn->rollback();
        }
        @unlink($temporary_path);
        throw $exception;
    }
}

function databaseBackupReadLine($handle, &$bytes_read, $maximum_bytes) {
    $line = fgets($handle);
    if ($line === false) {
        return false;
    }

    $bytes_read += strlen($line);
    if ($bytes_read > (int) $maximum_bytes) {
        throw new RuntimeException(
            'The uploaded backup exceeds the configured maximum size of '
            . databaseBackupMaximumSizeLabel($maximum_bytes) . '.'
        );
    }

    return $line;
}

function databaseBackupHeaderTables(array $header) {
    if (($header['type'] ?? null) !== 'header'
        || ($header['format'] ?? null) !== DNR_DATABASE_BACKUP_FORMAT
        || ($header['version'] ?? null) !== DNR_DATABASE_BACKUP_VERSION
        || !isset($header['schema_fingerprint'], $header['tables'])
        || !is_string($header['schema_fingerprint'])
        || !is_array($header['tables'])
        || preg_match('/\A[0-9a-f]{64}\z/', $header['schema_fingerprint']) !== 1
    ) {
        throw new RuntimeException('The uploaded file is not a supported DNR database backup.');
    }

    foreach ($header['tables'] as $table) {
        if (!is_array($table)
            || !isset($table['name'], $table['engine'], $table['columns'], $table['row_count'])
            || !is_string($table['name'])
            || !is_string($table['engine'])
            || !is_array($table['columns'])
            || !is_int($table['row_count'])
            || $table['row_count'] < 0
        ) {
            throw new RuntimeException('The backup table definition is invalid.');
        }
        databaseBackupIdentifier($table['name']);
        databaseBackupExportColumnNames($table);
    }

    return $header['tables'];
}

function inspectDatabaseBackup($path, array $current_schema, $maximum_bytes = null, $row_consumer = null) {
    $maximum_bytes = $maximum_bytes ?: databaseBackupMaximumBytes();
    $file_size = @filesize($path);
    if ($file_size === false || $file_size < 1 || $file_size > $maximum_bytes) {
        throw new RuntimeException(
            'Select a non-empty DNR backup no larger than '
            . databaseBackupMaximumSizeLabel($maximum_bytes) . '.'
        );
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The uploaded backup could not be opened.');
    }

    try {
        $bytes_read = 0;
        $header_line = databaseBackupReadLine($handle, $bytes_read, $maximum_bytes);
        if ($header_line === false) {
            throw new RuntimeException('The uploaded backup is empty.');
        }
        try {
            $header = json_decode(trim($header_line), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('The uploaded file is not a valid DNR database backup.');
        }
        if (!is_array($header)) {
            throw new RuntimeException('The uploaded file is not a valid DNR database backup.');
        }

        $backup_tables = databaseBackupHeaderTables($header);
        $current_fingerprint = databaseBackupSchemaFingerprint($current_schema);
        $backup_fingerprint = databaseBackupSchemaFingerprint($backup_tables);
        if (!hash_equals($current_fingerprint, $header['schema_fingerprint'])
            || !hash_equals($current_fingerprint, $backup_fingerprint)
        ) {
            throw new RuntimeException(
                'This backup was created from a different database schema. '
                . 'Deploy the matching DNR version and migrations before restoring it.'
            );
        }

        $table_indexes = [];
        $expected_counts = [];
        foreach ($backup_tables as $index => $table) {
            if (isset($table_indexes[$table['name']])) {
                throw new RuntimeException('The backup contains a duplicate table definition.');
            }
            if (strcasecmp($table['engine'], 'InnoDB') !== 0) {
                throw new RuntimeException('The backup contains a table that cannot be restored atomically.');
            }
            $table_indexes[$table['name']] = $index;
            $expected_counts[$table['name']] = $table['row_count'];
        }

        $hash = hash_init('sha256');
        hash_update($hash, $header_line);
        $actual_counts = array_fill_keys(array_keys($expected_counts), 0);
        $total_rows = 0;
        $last_table_index = 0;
        $footer = null;

        while (($line = databaseBackupReadLine($handle, $bytes_read, $maximum_bytes)) !== false) {
            try {
                $record = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new RuntimeException('The backup contains invalid data.');
            }
            if (!is_array($record)) {
                throw new RuntimeException('The backup contains invalid data.');
            }

            if (($record['type'] ?? null) === 'end') {
                $footer = $record;
                break;
            }
            if (($record['type'] ?? null) !== 'row'
                || !isset($record['table'], $record['values'])
                || !is_string($record['table'])
                || !is_array($record['values'])
                || !isset($table_indexes[$record['table']])
            ) {
                throw new RuntimeException('The backup contains an invalid row record.');
            }

            $table_index = $table_indexes[$record['table']];
            if ($table_index < $last_table_index) {
                throw new RuntimeException('The backup rows are not in the required table order.');
            }
            $last_table_index = $table_index;
            $table = $backup_tables[$table_index];
            $column_names = databaseBackupExportColumnNames($table);
            $values = databaseBackupDecodedValues($record['values'], count($column_names));

            $actual_counts[$record['table']]++;
            if ($actual_counts[$record['table']] > $expected_counts[$record['table']]) {
                throw new RuntimeException('The backup contains more rows than its header declares.');
            }
            $total_rows++;
            hash_update($hash, $line);

            if (is_callable($row_consumer)) {
                $row_consumer($table, $column_names, $values);
            }
        }

        if (!is_array($footer)
            || !isset($footer['row_count'], $footer['sha256'])
            || !is_int($footer['row_count'])
            || !is_string($footer['sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/', $footer['sha256']) !== 1
        ) {
            throw new RuntimeException('The backup is incomplete.');
        }
        if ($footer['row_count'] !== $total_rows || $actual_counts !== $expected_counts) {
            throw new RuntimeException('The backup row counts do not match its header.');
        }
        if (!hash_equals(hash_final($hash), $footer['sha256'])) {
            throw new RuntimeException('The backup integrity check failed.');
        }

        while (($trailing_line = fgets($handle)) !== false) {
            if (trim($trailing_line) !== '') {
                throw new RuntimeException('The backup contains unexpected trailing data.');
            }
        }

        fclose($handle);
        return [
            'header' => $header,
            'tables' => $backup_tables,
            'row_count' => $total_rows,
            'table_count' => count($backup_tables),
            'size' => $bytes_read,
        ];
    } catch (Throwable $exception) {
        fclose($handle);
        throw $exception;
    }
}

function databaseBackupBindValues(mysqli_stmt $stmt, array $values) {
    $parameters = [str_repeat('s', count($values))];
    foreach ($values as $value) {
        $parameters[] = $value;
    }
    $references = [];
    foreach ($parameters as $index => &$parameter) {
        $references[$index] = &$parameter;
    }
    unset($parameter);

    return call_user_func_array([$stmt, 'bind_param'], $references);
}

function restoreDatabaseBackup(
    mysqli $conn,
    $path,
    array $current_schema,
    array $actor,
    $maximum_bytes = null
) {
    $maximum_bytes = $maximum_bytes ?: databaseBackupMaximumBytes();
    $prepared_inserts = [];
    $foreign_keys_disabled = false;
    $transaction_started = false;
    $audit_ready = false;

    $prepare_audit_restore = static function () use ($conn, &$audit_ready) {
        if ($audit_ready) {
            return;
        }

        if (!$conn->query('UPDATE users SET auth_version = auth_version + 1')) {
            throw new RuntimeException('Unable to invalidate existing sessions after the restore.');
        }
        if (!$conn->query('DELETE FROM `security_audit_log`')) {
            throw new RuntimeException('Unable to prepare the restored audit log.');
        }
        $audit_ready = true;
    };

    try {
        if (!$conn->query('SET FOREIGN_KEY_CHECKS = 0')) {
            throw new RuntimeException('Unable to prepare the database restore.');
        }
        $foreign_keys_disabled = true;
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start the database restore.');
        }
        $transaction_started = true;

        foreach ($current_schema as $table) {
            $table_identifier = databaseBackupIdentifier($table['name']);
            if (!$conn->query("DELETE FROM {$table_identifier}")) {
                throw new RuntimeException('Unable to clear a database table during restore.');
            }
        }

        $inspection = inspectDatabaseBackup(
            $path,
            $current_schema,
            $maximum_bytes,
            static function (array $table, array $column_names, array $values) use (
                $conn,
                &$prepared_inserts,
                $prepare_audit_restore
            ) {
                $table_name = $table['name'];
                if ($table_name === 'security_audit_log') {
                    $prepare_audit_restore();
                }

                if (!isset($prepared_inserts[$table_name])) {
                    $table_identifier = databaseBackupIdentifier($table_name);
                    $column_sql = implode(', ', array_map('databaseBackupIdentifier', $column_names));
                    $placeholders = implode(', ', array_fill(0, count($column_names), '?'));
                    $stmt = $conn->prepare(
                        "INSERT INTO {$table_identifier} ({$column_sql}) VALUES ({$placeholders})"
                    );
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare a database table for restore.');
                    }
                    $prepared_inserts[$table_name] = $stmt;
                }

                $stmt = $prepared_inserts[$table_name];
                if (!databaseBackupBindValues($stmt, $values) || !$stmt->execute()) {
                    throw new RuntimeException('A database row could not be restored.');
                }
            }
        );

        $prepare_audit_restore();
        foreach ($prepared_inserts as $stmt) {
            $stmt->close();
        }
        $prepared_inserts = [];

        foreach ($inspection['tables'] as $table) {
            $table_identifier = databaseBackupIdentifier($table['name']);
            $count_result = $conn->query("SELECT COUNT(*) AS row_count FROM {$table_identifier}");
            if (!$count_result
                || (int) $count_result->fetch_assoc()['row_count'] !== (int) $table['row_count']
            ) {
                throw new RuntimeException('A restored database table failed verification.');
            }
        }

        $actor_id = (int) ($actor['id'] ?? 0);
        $actor_username = substr((string) ($actor['username'] ?? ''), 0, 50);
        $restored_actor_id = null;
        if ($actor_id > 0 && $actor_username !== '') {
            $actor_stmt = $conn->prepare('SELECT id FROM users WHERE id = ? AND username = ?');
            $actor_stmt->bind_param('is', $actor_id, $actor_username);
            $actor_stmt->execute();
            if ($actor_stmt->get_result()->num_rows === 1) {
                $restored_actor_id = $actor_id;
            }
            $actor_stmt->close();
        }

        $audit_recorded = recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'database_restored',
            'actor_user_id' => $restored_actor_id,
            'actor_username' => $actor_username !== '' ? $actor_username : null,
            'entity_type' => 'database',
            'entity_label' => 'DNR database',
            'details' => sprintf(
                'Restored %d rows across %d tables; all sessions invalidated',
                $inspection['row_count'],
                $inspection['table_count']
            ),
        ]);
        if (!$audit_recorded) {
            throw new RuntimeException('Unable to record the completed database restore.');
        }

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit the database restore.');
        }
        $transaction_started = false;
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $foreign_keys_disabled = false;

        return $inspection;
    } catch (Throwable $exception) {
        foreach ($prepared_inserts as $stmt) {
            $stmt->close();
        }
        if ($transaction_started) {
            $conn->rollback();
        }
        if ($foreign_keys_disabled) {
            $conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        throw $exception;
    }
}
