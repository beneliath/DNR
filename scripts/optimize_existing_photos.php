<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$source = is_file('/var/www/html/bootstrap.php') ? '/var/www/html' : dirname(__DIR__) . '/src';
require_once $source . '/bootstrap.php';
require_once $source . '/profile_helpers.php';
require_once $source . '/speaker_helpers.php';

/** @return array{width: int, height: int, mime: string}|null */
function storedPhotoDimensions(mixed $data): ?array
{
    if (!is_string($data) || $data === '') {
        return null;
    }
    $dimensions = @getimagesizefromstring($data);
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    $mime = (string) ($dimensions['mime'] ?? '');
    if ($width < 1 || $height < 1 || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return null;
    }
    return ['width' => $width, 'height' => $height, 'mime' => $mime];
}

function storedPhotoIsOptimized(array $row): bool
{
    $full = storedPhotoDimensions($row['full_data'] ?? null);
    $thumbnail = storedPhotoDimensions($row['thumbnail_data'] ?? null);
    if ($full === null || $thumbnail === null
        || $full['width'] !== $full['height']
        || $full['width'] > UPLOADED_IMAGE_DETAIL_DIMENSION
        || $thumbnail['width'] !== $thumbnail['height']
        || $thumbnail['width'] !== min(UPLOADED_IMAGE_THUMBNAIL_DIMENSION, $full['width'])
        || $full['mime'] !== (string) ($row['full_mime'] ?? '')
        || $thumbnail['mime'] !== (string) ($row['thumbnail_mime'] ?? '')
    ) {
        return false;
    }
    if (function_exists('imagewebp') && ($full['mime'] !== 'image/webp' || $thumbnail['mime'] !== 'image/webp')) {
        return false;
    }
    $stored_hash = $row['full_sha256'] ?? null;
    return is_string($stored_hash)
        && strlen($stored_hash) === 32
        && hash_equals(hash('sha256', (string) $row['full_data'], true), $stored_hash);
}

function normalizeStoredPhoto(string $data, string $kind): array
{
    $path = tempnam(sys_get_temp_dir(), 'dnr-photo-optimize-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary image file.');
    }
    try {
        if (file_put_contents($path, $data) !== strlen($data)) {
            throw new RuntimeException('Unable to stage an existing image for optimization.');
        }
        return match ($kind) {
            'profile' => validatedProfilePictureFile($path),
            'contact' => validatedContactPhotoFile($path),
            'speaker' => validatedSpeakerPhotoFile($path),
            default => throw new InvalidArgumentException('Unknown stored photo type.'),
        };
    } finally {
        @unlink($path);
    }
}

/**
 * @param array{
 *   kind: string,
 *   label: string,
 *   table: string,
 *   full: string,
 *   full_mime: string,
 *   thumbnail: string,
 *   thumbnail_mime: string,
 *   sha256: string,
 *   updated_at: string,
 *   version: bool
 * } $definition
 * @return array{processed: int, preserved: int}
 */
function optimizeStoredPhotoCollection(mysqli $connection, array $definition): array
{
    $processed = 0;
    $preserved = 0;
    $last_id = 0;
    do {
        $rows = $connection->execute_query(
            "SELECT id,
                    {$definition['full']} AS full_data,
                    {$definition['full']}_key AS full_key,
                    {$definition['thumbnail']}_key AS thumbnail_key,
                    {$definition['full_mime']} AS full_mime,
                    {$definition['thumbnail']} AS thumbnail_data,
                    {$definition['thumbnail_mime']} AS thumbnail_mime,
                    {$definition['sha256']} AS full_sha256
             FROM {$definition['table']}
             WHERE id > ? AND ({$definition['full']} IS NOT NULL OR {$definition['full']}_key IS NOT NULL)
             ORDER BY id
             LIMIT 50",
            [$last_id]
        )->fetch_all(MYSQLI_ASSOC);
        if ($rows === []) {
            break;
        }

        $connection->begin_transaction();
        try {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $last_id = $id;
                foreach (['full', 'thumbnail'] as $variant) {
                    if (!empty($row[$variant . '_key'])) {
                        $file = openPersistentFile(persistentFileMetadata($connection, $row[$variant . '_key']), true);
                        try { $row[$variant . '_data'] = stream_get_contents($file); } finally { fclose($file); }
                    }
                }
                if (storedPhotoIsOptimized($row)) {
                    $preserved++;
                    continue;
                }
                try {
                    $photo = normalizeStoredPhoto((string) $row['full_data'], $definition['kind']);
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        "Unable to optimize {$definition['label']} ID {$id}: {$exception->getMessage()}",
                        0,
                        $exception
                    );
                }
                $photo = storePersistentPortrait($connection, $photo, $definition['kind']);
                $version_sql = $definition['version'] ? ', version = version + 1' : '';
                $connection->execute_query(
                    "UPDATE {$definition['table']}
                     SET {$definition['full']} = NULL, {$definition['thumbnail']} = NULL,
                         {$definition['full']}_key = ?,
                         {$definition['thumbnail']}_key = ?,
                         {$definition['thumbnail_mime']} = ?,
                         {$definition['full_mime']} = ?,
                         {$definition['sha256']} = ?,
                         {$definition['updated_at']} = UTC_TIMESTAMP(6)
                         {$version_sql}
                     WHERE id = ?",
                    [
                        $photo['storage_key'],
                        $photo['thumbnail_key'],
                        $photo['thumbnail_mime_type'],
                        $photo['mime_type'],
                        $photo['sha256'],
                        $id,
                    ]
                );
                $processed++;
            }
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    } while (count($rows) === 50);

    return ['processed' => $processed, 'preserved' => $preserved];
}

try {
    $definitions = [
        [
            'kind' => 'profile',
            'label' => 'profile picture',
            'table' => 'users',
            'full' => 'profile_picture',
            'full_mime' => 'profile_picture_mime',
            'thumbnail' => 'profile_picture_thumbnail',
            'thumbnail_mime' => 'profile_picture_thumbnail_mime',
            'sha256' => 'profile_picture_sha256',
            'updated_at' => 'profile_picture_updated_at',
            'version' => false,
        ],
        [
            'kind' => 'contact',
            'label' => 'contact photo',
            'table' => 'contacts',
            'full' => 'contact_photo',
            'full_mime' => 'contact_photo_mime',
            'thumbnail' => 'contact_photo_thumbnail',
            'thumbnail_mime' => 'contact_photo_thumbnail_mime',
            'sha256' => 'contact_photo_sha256',
            'updated_at' => 'contact_photo_updated_at',
            'version' => false,
        ],
        [
            'kind' => 'speaker',
            'label' => 'speaker photo',
            'table' => 'speakers',
            'full' => 'photo',
            'full_mime' => 'photo_mime',
            'thumbnail' => 'photo_thumbnail',
            'thumbnail_mime' => 'photo_thumbnail_mime',
            'sha256' => 'photo_sha256',
            'updated_at' => 'photo_updated_at',
            'version' => true,
        ],
    ];
    $result = [];
    foreach ($definitions as $definition) {
        $result[$definition['table']] = optimizeStoredPhotoCollection($conn, $definition);
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Existing photo optimization failed: {$exception->getMessage()}\n");
    exit(1);
}
