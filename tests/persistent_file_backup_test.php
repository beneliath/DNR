<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/database_backup_helpers.php';

function expectFileBackup(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/dnr-file-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$previousRoot = getenv('DNR_FILE_STORAGE_PATH');
putenv('DNR_FILE_STORAGE_PATH=' . $directory);
$archive = tempnam(sys_get_temp_dir(), 'dnr-file-archive-');
$source = $directory . '/source';
$contents = random_bytes(120000);
$metadata = ['storage_key' => '', 'filename' => 'notes.pdf', 'content_type' => 'application/pdf',
    'size' => (string) strlen($contents), 'checksum' => hash('sha256', $contents)];
$metadata['storage_key'] = persistentFileKey($metadata['checksum'], $metadata['filename'], $metadata['content_type']);
$columns = array_keys($metadata);
$schema = [['name' => 'stored_files', 'engine' => 'InnoDB', 'definition' => 'fixture',
    'columns' => array_map(static fn($name) => ['name' => $name, 'extra' => ''], $columns)]];

$writeArchive = static function (string $mode = '') use ($archive, $metadata, $contents, $schema, $columns): void {
    $tables = $schema;
    $tables[0]['row_count'] = 1;
    $handle = fopen($archive, 'wb');
    $hash = hash_init('sha256');
    $bytes = 0;
    databaseBackupWriteLine($handle, ['type' => 'header', 'format' => DNR_DATABASE_BACKUP_FORMAT,
        'version' => 3, 'schema_fingerprint' => databaseBackupSchemaFingerprint($schema), 'tables' => $tables], $bytes, 1048576, $hash);
    databaseBackupWriteRow($handle, 'stored_files', $metadata, $columns, $bytes, 1048576, $hash);
    if ($mode !== 'missing') {
        for ($offset = 0; $offset < strlen($contents); $offset += 49152) {
            $chunk = substr($contents, $offset, 49152);
            if ($mode === 'corrupt' && $offset === 0) $chunk[0] = chr(ord($chunk[0]) ^ 1);
            databaseBackupWriteLine($handle, ['type' => 'file_chunk', 'storage_key' => $metadata['storage_key'],
                'offset' => $mode === 'offset' ? $offset + 1 : $offset, 'data' => base64_encode($chunk)], $bytes, 1048576, $hash);
        }
        databaseBackupWriteLine($handle, ['type' => 'file_end', 'storage_key' => $metadata['storage_key']], $bytes, 1048576, $hash);
        if ($mode === 'duplicate') databaseBackupWriteLine($handle, ['type' => 'file_end', 'storage_key' => $metadata['storage_key']], $bytes, 1048576, $hash);
    }
    databaseBackupWriteLine($handle, ['type' => 'end', 'row_count' => 1, 'file_count' => 1, 'sha256' => hash_final($hash)], $bytes, 1048576);
    fclose($handle);
};

try {
    $writeArchive();
    $result = inspectDatabaseBackup($archive, $schema, 1048576);
    expectFileBackup($result['file_count'] === 1 && !file_exists(persistentFilePath($metadata['storage_key'])), 'Inspection must validate files without writing them.');
    inspectDatabaseBackup($archive, $schema, 1048576, null, true);
    expectFileBackup(file_get_contents(persistentFilePath($metadata['storage_key'])) === $contents, 'Restore must reproduce exact binary contents.');
    inspectDatabaseBackup($archive, $schema, 1048576, null, true);
    expectFileBackup(count(glob($directory . '/.restore-*')) === 0, 'Repeat restore must be idempotent and remove temporary files.');
    foreach (['missing', 'corrupt', 'offset', 'duplicate'] as $mode) {
        $writeArchive($mode);
        try { inspectDatabaseBackup($archive, $schema, 1048576); $rejected = false; }
        catch (RuntimeException $exception) { $rejected = true; }
        expectFileBackup($rejected, 'Reject invalid file archive: ' . $mode);
    }
    try { persistentFilePath('../outside'); $rejected = false; }
    catch (RuntimeException $exception) { $rejected = true; }
    expectFileBackup($rejected, 'Reject traversal keys.');
    file_put_contents($source, $contents);
    unlink(persistentFilePath($metadata['storage_key']));
    symlink($source, $directory . '/' . $metadata['storage_key']);
    try { installPersistentFile($source, $metadata); $rejected = false; }
    catch (RuntimeException $exception) { $rejected = true; }
    expectFileBackup($rejected, 'Reject symlink destinations.');
    unlink($directory . '/' . $metadata['storage_key']);
    file_put_contents($directory . '/' . $metadata['storage_key'], 'conflict');
    try { installPersistentFile($source, $metadata); $rejected = false; }
    catch (RuntimeException $exception) { $rejected = true; }
    expectFileBackup($rejected && file_get_contents($directory . '/' . $metadata['storage_key']) === 'conflict', 'Never overwrite an existing file during restore.');
    echo "Persistent file backup tests passed.\n";
} finally {
    unlink($archive);
    foreach (array_merge(glob($directory . '/*'), glob($directory . '/.restore-*')) as $path) @unlink($path);
    @unlink($directory . '/.lifecycle.lock');
    rmdir($directory);
    putenv($previousRoot === false ? 'DNR_FILE_STORAGE_PATH' : 'DNR_FILE_STORAGE_PATH=' . $previousRoot);
}
