<?php

declare(strict_types=1);

require_once __DIR__ . '/persistent_file_helpers.php';

/** Append files from the same MVCC snapshot as their database metadata. */
function writePersistentFilesToBackup(mysqli $conn, $handle, int &$bytes, int $maximum, $hash): int
{
    $files = $conn->query('SELECT storage_key, filename, content_type, size, checksum FROM stored_files ORDER BY storage_key', MYSQLI_USE_RESULT);
    $count = 0;
    try {
        while ($metadata = $files->fetch_assoc()) {
            $input = openPersistentFile($metadata, true);
            try {
                $offset = 0;
                while (!feof($input)) {
                    $chunk = fread($input, 49152);
                    if ($chunk === false) throw new RuntimeException('Unable to back up a persistent file.');
                    if ($chunk === '') break;
                    databaseBackupWriteLine($handle, ['type' => 'file_chunk', 'storage_key' => $metadata['storage_key'],
                        'offset' => $offset, 'data' => base64_encode($chunk)], $bytes, $maximum, $hash);
                    $offset += strlen($chunk);
                }
                if ($offset !== (int) $metadata['size']) throw new RuntimeException('Persistent file changed during backup.');
                databaseBackupWriteLine($handle, ['type' => 'file_end', 'storage_key' => $metadata['storage_key']], $bytes, $maximum, $hash);
                $count++;
            } finally { fclose($input); }
        }
    } finally { $files->free(); }
    return $count;
}

function persistentBackupMetadata(array $row): array
{
    foreach (['storage_key', 'filename', 'content_type', 'checksum', 'size'] as $field) {
        if (!isset($row[$field]) || !is_string($row[$field])) throw new RuntimeException('Invalid file metadata in backup.');
    }
    if (!ctype_digit($row['size']) || (int) $row['size'] < 1
        || strlen($row['filename']) > 255 || $row['filename'] === ''
        || strlen($row['content_type']) > 127 || $row['content_type'] === ''
        || preg_match('/\A[0-9a-f]{64}\z/D', $row['checksum']) !== 1
        || !hash_equals(persistentFileKey($row['checksum'], $row['filename'], $row['content_type']), $row['storage_key'])) {
        throw new RuntimeException('Invalid file metadata in backup.');
    }
    return $row;
}

/** Strict bounded-memory reader; a complete archive is checked before database replacement. */
function consumePersistentBackupRecord(array $record, array &$state, bool $restore): void
{
    $key = $record['storage_key'] ?? null;
    if (!is_string($key) || !isset($state['expected'][$key])) {
        throw new RuntimeException('Backup contains an unknown or duplicate file.');
    }
    if ($state['key'] === null) {
        $state['key'] = $key;
        $state['offset'] = 0;
        $state['hash'] = hash_init('sha256');
        if ($restore) {
            $state['path'] = tempnam(persistentFileRoot(), '.restore-');
            if ($state['path'] === false) throw new RuntimeException('Unable to stage a restored file.');
            $state['handle'] = fopen($state['path'], 'wb');
            if ($state['handle'] === false) throw new RuntimeException('Unable to write a restored file.');
        }
    }
    if ($state['key'] !== $key) throw new RuntimeException('Interleaved files in backup.');
    $metadata = $state['expected'][$key];
    if ($record['type'] === 'file_chunk') {
        if (($record['offset'] ?? null) !== $state['offset'] || !is_string($record['data'] ?? null)
            || strlen($record['data']) > 65536) throw new RuntimeException('Invalid file chunk in backup.');
        $chunk = base64_decode($record['data'], true);
        if ($chunk === false || $chunk === '' || $state['offset'] + strlen($chunk) > (int) $metadata['size']) {
            throw new RuntimeException('Invalid file chunk size in backup.');
        }
        hash_update($state['hash'], $chunk);
        if ($restore) databaseBackupWriteBytes($state['handle'], $chunk);
        $state['offset'] += strlen($chunk);
        return;
    }
    if ($state['offset'] !== (int) $metadata['size']
        || !hash_equals($metadata['checksum'], hash_final($state['hash']))) {
        throw new RuntimeException('A backed-up file failed its size or checksum check.');
    }
    if ($restore) {
        if (!fflush($state['handle']) || !fsync($state['handle'])) throw new RuntimeException('Unable to flush a restored file.');
        fclose($state['handle']);
        $state['handle'] = null;
        installPersistentFile($state['path'], $metadata);
        unlink($state['path']);
        $state['path'] = null;
    }
    unset($state['expected'][$key]);
    $state['count']++;
    $state['key'] = null;
}

function cleanupPersistentBackupState(array $state): void
{
    if (is_resource($state['handle'] ?? null)) fclose($state['handle']);
    if (is_string($state['path'] ?? null)) @unlink($state['path']);
}
