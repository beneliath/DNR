<?php

declare(strict_types=1);

require_once __DIR__ . '/persistent_file_helpers.php';

/** One locked row per transaction makes this safe to resume after interruption. */
function migratePersistentFiles(mysqli $conn): int
{
    $converted = 0;
    foreach (['users' => 'profile_picture', 'contacts' => 'contact_photo', 'speakers' => 'photo'] as $table => $column) {
        $lastId = 0;
        while (true) {
            $conn->begin_transaction();
            try {
                $row = $conn->execute_query("SELECT id, {$column}, {$column}_thumbnail,
                    {$column}_key, {$column}_thumbnail_key, {$column}_mime, {$column}_thumbnail_mime
                    FROM {$table} WHERE id > ? AND ({$column} IS NOT NULL OR {$column}_thumbnail IS NOT NULL)
                    ORDER BY id LIMIT 1 FOR UPDATE", [$lastId])->fetch_assoc();
                if (!$row) { $conn->commit(); break; }
                $lastId = (int) $row['id'];
                foreach ([$column, $column . '_thumbnail'] as $field) {
                    if ($row[$field] === null) continue;
                    if ($row[$field . '_key'] !== null) {
                        $metadata = persistentFileMetadata($conn, $row[$field . '_key']);
                        if (!hash_equals($metadata['checksum'], hash('sha256', $row[$field]))) {
                            throw new RuntimeException('Conflicting legacy and persistent portrait.');
                        }
                    }
                    $mime = (string) $row[$field . '_mime'];
                    $extension = match ($mime) {
                        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                        default => throw new RuntimeException('Unknown legacy portrait content type.'),
                    };
                    $key = storePersistentFile($conn, $row[$field], $table . '-' . $row['id'] . '-' . $field . '.' . $extension, $mime);
                    $conn->execute_query("UPDATE {$table} SET {$field}_key = ?, {$field} = NULL WHERE id = ?", [$key, $row['id']]);
                    $converted++;
                }
                $conn->commit();
            } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
        }
    }
    $lastPresentation = $lastSpeaker = 0;
    while (true) {
        $conn->begin_transaction();
        try {
            $row = $conn->execute_query('SELECT presentation_id, speaker_id, pdf, filename, storage_key, size, sha256
                FROM presentation_notes WHERE (presentation_id > ? OR (presentation_id = ? AND speaker_id > ?))
                    AND pdf IS NOT NULL ORDER BY presentation_id, speaker_id LIMIT 1 FOR UPDATE',
                [$lastPresentation, $lastPresentation, $lastSpeaker])->fetch_assoc();
            if (!$row) { $conn->commit(); break; }
            $lastPresentation = (int) $row['presentation_id'];
            $lastSpeaker = (int) $row['speaker_id'];
            if (strlen($row['pdf']) !== (int) $row['size']
                || !hash_equals((string) $row['sha256'], hash('sha256', $row['pdf'], true))) {
                throw new RuntimeException('Legacy PDF size/checksum mismatch; conversion stopped without clearing its bytes.');
            }
            if ($row['storage_key'] !== null) {
                $metadata = persistentFileMetadata($conn, $row['storage_key']);
                if (!hash_equals($metadata['checksum'], hash('sha256', $row['pdf']))) {
                    throw new RuntimeException('Conflicting legacy and persistent PDF.');
                }
            }
            $key = storePersistentFile($conn, $row['pdf'], $row['filename'] ?: 'speaker-notes.pdf', 'application/pdf');
            $conn->execute_query('UPDATE presentation_notes SET storage_key = ?, pdf = NULL
                WHERE presentation_id = ? AND speaker_id = ?', [$key, $row['presentation_id'], $row['speaker_id']]);
            $converted++;
            $conn->commit();
        } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
    }
    return $converted;
}
