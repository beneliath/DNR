<?php
declare(strict_types=1);

require_once __DIR__ . '/speaker_helpers.php';

function exportInitialSpeakerSeed(mysqli $conn): array
{
    $rows = $conn->query('SELECT * FROM speakers ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 1) {
        throw new RuntimeException('The initial speaker export requires exactly one speaker.');
    }
    $row = $rows[0];
    $speaker = normalizeSpeakerInput($row);
    $photo = null;
    if ($row['photo'] !== null) {
        $photo = [
            'data' => base64_encode($row['photo']),
            'thumbnail_data' => base64_encode($row['photo_thumbnail']),
            'mime_type' => $row['photo_mime'],
            'thumbnail_mime_type' => $row['photo_thumbnail_mime'],
            'sha256' => bin2hex($row['photo_sha256']),
        ];
    }
    $seed = ['format' => 1, 'source_id' => (int) $row['id'], 'source_version' => (int) $row['version'],
        'exported_at' => gmdate('c'), 'speaker' => $speaker, 'photo' => $photo];
    validateInitialSpeakerSeed($seed);
    return $seed;
}

/** @return array{speaker: array<string, string>, photo: array|null} */
function validateInitialSpeakerSeed(array $seed): array
{
    if (($seed['format'] ?? null) !== 1 || !is_array($seed['speaker'] ?? null)
        || !array_key_exists('photo', $seed)) {
        throw new InvalidArgumentException('Invalid initial speaker seed format.');
    }
    $speaker = normalizeSpeakerInput($seed['speaker']);
    if ($speaker['name'] !== 'Olivier Melnick' || $speaker['email'] !== 'olivier@shalominmessiah.com') {
        throw new InvalidArgumentException('The initial speaker seed must identify Olivier Melnick.');
    }
    $photo = $seed['photo'];
    if ($photo !== null) {
        if (!is_array($photo)) throw new InvalidArgumentException('Invalid speaker seed photo.');
        foreach (['data' => SPEAKER_PHOTO_MAX_BYTES, 'thumbnail_data' => 61440] as $field => $limit) {
            $encoded = $photo[$field] ?? null;
            $decoded = is_string($encoded) && strlen($encoded) <= (int) ceil($limit / 3) * 4
                ? base64_decode($encoded, true) : false;
            if ($decoded === false || $decoded === '' || strlen($decoded) > $limit) {
                throw new InvalidArgumentException('Invalid speaker seed image data.');
            }
            $mimeField = $field === 'data' ? 'mime_type' : 'thumbnail_mime_type';
            $dimensions = @getimagesizefromstring($decoded);
            $maximum = $field === 'data' ? CONTACT_PHOTO_MAX_DIMENSION : 256;
            if (!$dimensions || max($dimensions[0], $dimensions[1]) > $maximum
                || !in_array($photo[$mimeField] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
                || $dimensions['mime'] !== $photo[$mimeField]) {
                throw new InvalidArgumentException('Invalid speaker seed image type or dimensions.');
            }
            $photo[$field] = $decoded;
        }
        if (!is_string($photo['sha256'] ?? null)
            || !hash_equals(hash('sha256', $photo['data']), $photo['sha256'])) {
            throw new InvalidArgumentException('The speaker seed photo checksum does not match.');
        }
        $photo['sha256'] = hash('sha256', $photo['data'], true);
    }
    return ['speaker' => $speaker, 'photo' => $photo];
}

/** Caller owns a transaction and must keep application writers paused. */
function applyInitialSpeakerSeed(mysqli $conn, array $seed): array
{
    $normalized = validateInitialSpeakerSeed($seed);
    $rows = $conn->query('SELECT id, name, email, version FROM speakers ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 1 || $rows[0]['name'] !== 'Olivier Melnick'
        || $rows[0]['email'] !== 'olivier@shalominmessiah.com') {
        throw new RuntimeException('Initial speaker import requires the single migrated Olivier record.');
    }
    $id = (int) $rows[0]['id'];
    saveSpeaker($conn, $normalized['speaker'], $id, (int) $rows[0]['version'], $normalized['photo'], $normalized['photo'] === null);
    $stmt = $conn->prepare('UPDATE presentations SET speaker_id = ? WHERE speaker_id <> ?');
    $stmt->bind_param('ii', $id, $id);
    $stmt->execute();
    $stmt->close();
    $stored = exportInitialSpeakerSeed($conn);
    if ($stored['speaker'] !== $seed['speaker'] || $stored['photo'] !== $seed['photo']) {
        throw new RuntimeException('Imported speaker fields or photo differ from the exported record.');
    }
    $counts = $conn->query('SELECT COUNT(*) AS total, COALESCE(SUM(speaker_id <> ' . $id . ' OR speaker_id IS NULL), 0) AS mismatches FROM presentations')->fetch_assoc();
    if ((int) $counts['mismatches'] !== 0) throw new RuntimeException('Not all presentations point to Olivier.');
    return ['speaker_id' => $id, 'presentations' => (int) $counts['total'],
        'profile_sha256' => hash('sha256', json_encode($stored['speaker'], JSON_THROW_ON_ERROR)),
        'photo_sha256' => $stored['photo']['sha256'] ?? null];
}
