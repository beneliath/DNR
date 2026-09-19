<?php

declare(strict_types=1);

/** Hold through the request/transaction so cleanup cannot race publishing or backup snapshots. */
function lockPersistentFiles(): void
{
    static $locks = [];
    $root = persistentFileRoot();
    if (isset($locks[$root])) return;
    $path = $root . '/.lifecycle.lock';
    if (is_link($path)) throw new RuntimeException('Invalid file lifecycle lock.');
    $handle = @fopen($path, is_file($path) ? 'rb' : 'x+b');
    if ($handle === false || !flock($handle, LOCK_SH)) throw new RuntimeException('Persistent storage is busy or unavailable.');
    $locks[$root] = $handle;
}

/** Uploads live outside the document root on a shared, persistent volume. */
function persistentFileRoot(): string
{
    $root = rtrim((string) (getenv('DNR_FILE_STORAGE_PATH') ?: '/var/lib/dnr/files'), '/');
    if ($root === '' || $root[0] !== '/' || !is_dir($root) || is_link($root)) {
        throw new RuntimeException('Persistent file storage is unavailable.');
    }
    return $root;
}

function persistentFileKey(string $checksum, string $filename, string $contentType): string
{
    return hash('sha256', $checksum . "\0" . $filename . "\0" . $contentType);
}

function persistentFilePath(string $key): string
{
    if (preg_match('/\A[0-9a-f]{64}\z/D', $key) !== 1) {
        throw new RuntimeException('Invalid persistent storage key.');
    }
    $path = persistentFileRoot() . '/' . $key;
    if (is_link($path)) {
        throw new RuntimeException('Persistent files cannot be symbolic links.');
    }
    return $path;
}

/** Publish complete immutable files atomically; never overwrite existing files. */
function installPersistentFile(string $source, array $metadata): void
{
    lockPersistentFiles();
    $key = (string) $metadata['storage_key'];
    $checksum = (string) $metadata['checksum'];
    if (!hash_equals(persistentFileKey($checksum, $metadata['filename'], $metadata['content_type']), $key)
        || filesize($source) !== (int) $metadata['size']
        || !hash_equals($checksum, (string) hash_file('sha256', $source))) {
        throw new RuntimeException('Persistent file integrity check failed.');
    }
    $destination = persistentFilePath($key);
    if (!file_exists($destination)) {
        // The staging file is on this volume, so link() atomically publishes it
        // without replacing a file installed by a concurrent upload/restore.
        if (!@link($source, $destination) && !is_file($destination)) {
            throw new RuntimeException('Unable to publish a persistent file.');
        }
    }
    $directory = @fopen(persistentFileRoot(), 'r');
    if ($directory === false) throw new RuntimeException('Unable to open the persistent storage directory.');
    try {
        if (!fsync($directory)) throw new RuntimeException('Unable to flush the persistent storage directory.');
    } finally { fclose($directory); }
    if (is_link($destination) || filesize($destination) !== (int) $metadata['size']
        || !hash_equals($checksum, (string) hash_file('sha256', $destination))) {
        throw new RuntimeException('An existing persistent file failed verification.');
    }
}

/** Write before the database pointer. A rolled-back transaction can leave only an unused file. */
function storePersistentFile(mysqli $conn, string $data, string $filename, string $contentType): string
{
    lockPersistentFiles();
    $filename = substr(basename(str_replace('\\', '/', $filename)), 0, 255);
    if ($data === '' || $filename === '' || strlen($contentType) > 127) {
        throw new InvalidArgumentException('Invalid persistent file metadata.');
    }
    $checksum = hash('sha256', $data);
    $key = persistentFileKey($checksum, $filename, $contentType);
    $metadata = ['storage_key' => $key, 'filename' => $filename, 'content_type' => $contentType,
        'size' => strlen($data), 'checksum' => $checksum];
    $temporary = tempnam(persistentFileRoot(), '.upload-');
    if ($temporary === false) throw new RuntimeException('Unable to stage the persistent file.');
    try {
        $handle = fopen($temporary, 'wb');
        if ($handle === false) throw new RuntimeException('Unable to write the persistent file.');
        try {
            for ($offset = 0, $size = strlen($data); $offset < $size;) {
                $written = fwrite($handle, substr($data, $offset, 1048576));
                if ($written === false || $written === 0) throw new RuntimeException('Persistent storage is full.');
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) throw new RuntimeException('Unable to flush the persistent file.');
        } finally { fclose($handle); }
        installPersistentFile($temporary, $metadata);
    } finally { @unlink($temporary); }
    $conn->execute_query('INSERT INTO stored_files (storage_key, filename, content_type, size, checksum)
        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE storage_key = VALUES(storage_key)',
        [$key, $filename, $contentType, strlen($data), $checksum]);
    return $key;
}

function persistentFileMetadata(mysqli $conn, string $key): array
{
    $metadata = $conn->execute_query('SELECT storage_key, filename, content_type, size, checksum
        FROM stored_files WHERE storage_key = ?', [$key])->fetch_assoc();
    if (!$metadata) throw new RuntimeException('Persistent file metadata is missing.');
    return $metadata;
}

/** @return resource */
function openPersistentFile(array $metadata, bool $verifyChecksum = false)
{
    lockPersistentFiles();
    $path = persistentFilePath((string) $metadata['storage_key']);
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('A persistent file is missing.');
    $stat = fstat($handle);
    if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== (int) $metadata['size']) {
        fclose($handle);
        throw new RuntimeException('A persistent file has an invalid size or type.');
    }
    if ($verifyChecksum) {
        $hash = hash_init('sha256');
        hash_update_stream($hash, $handle);
        rewind($handle);
        if (!hash_equals((string) $metadata['checksum'], hash_final($hash))) {
            fclose($handle);
            throw new RuntimeException('A persistent file failed its checksum check.');
        }
    }
    return $handle;
}

function storePersistentPortrait(mysqli $conn, array $picture, string $name): array
{
    $extension = static fn(string $mime): string => match ($mime) {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        default => throw new InvalidArgumentException('Unsupported portrait format.'),
    };
    $picture['storage_key'] = storePersistentFile($conn, $picture['data'],
        $name . '.' . $extension($picture['mime_type']), $picture['mime_type']);
    $picture['thumbnail_key'] = storePersistentFile($conn, $picture['thumbnail_data'],
        $name . '-thumbnail.' . $extension($picture['thumbnail_mime_type']), $picture['thumbnail_mime_type']);
    return $picture;
}

/** Called only after the portrait endpoint has checked record access. */
function servePersistentPortrait(mysqli $conn, string $key): void
{
    try {
        $metadata = persistentFileMetadata($conn, $key);
        if (!in_array($metadata['content_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Unsupported portrait format.');
        }
        $handle = openPersistentFile($metadata);
    } catch (Throwable $exception) {
        http_response_code(503);
        header('Cache-Control: no-store');
        return;
    }
    try {
        $etag = '"portrait-' . $key . '"';
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . $metadata['content_type']);
        header('Content-Length: ' . $metadata['size']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') fpassthru($handle);
    } finally { fclose($handle); }
}
