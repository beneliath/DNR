<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/speaker_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
releaseApplicationSessionLock();

$speaker_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$speaker_id) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare(
    'SELECT name, photo_mime,
            photo_thumbnail_mime,
            OCTET_LENGTH(photo_thumbnail) AS photo_thumbnail_size,
            HEX(photo_sha256) AS photo_sha256
     FROM speakers
     WHERE id = ?'
);
if (!$stmt) {
    http_response_code(503);
    exit;
}

$stmt->bind_param('i', $speaker_id);
$stmt->execute();
$speaker = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$speaker) {
    http_response_code(404);
    exit;
}

$allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
$mime_type = (string) ($speaker['photo_mime'] ?? '');
$photo_hash = strtolower((string) ($speaker['photo_sha256'] ?? ''));

header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

if (preg_match('/^[0-9a-f]{64}$/', $photo_hash) === 1
    && in_array($mime_type, $allowed_mime_types, true)
) {
    $serve_full_size = \Dnr\Http\RequestInput::string($_GET, 'size') === 'full';
    $etag = '"speaker-photo-' . $speaker_id . '-' . ($serve_full_size ? 'full-' : 'thumb-') . $photo_hash . '"';
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    $has_thumbnail = !$serve_full_size
        && (int) ($speaker['photo_thumbnail_size'] ?? 0) > 0
        && in_array(
            (string) ($speaker['photo_thumbnail_mime'] ?? ''),
            $allowed_mime_types,
            true
        );
    $photo_column = $has_thumbnail ? 'photo_thumbnail' : 'photo';
    $served_mime = $has_thumbnail
        ? (string) $speaker['photo_thumbnail_mime']
        : $mime_type;
    $photo_sql = "SELECT {$photo_column} AS photo FROM speakers
                  WHERE id = ? AND photo_sha256 = UNHEX(?)
                    AND photo_mime = ?";
    if ($has_thumbnail) {
        $photo_sql .= ' AND photo_thumbnail_mime = ?'
            . ' AND OCTET_LENGTH(photo_thumbnail) = ?';
    }
    $photo_stmt = $conn->prepare($photo_sql);
    if (!$photo_stmt) {
        http_response_code(503);
        exit;
    }
    if ($has_thumbnail) {
        $thumbnail_size = (int) $speaker['photo_thumbnail_size'];
        $photo_stmt->bind_param(
            'isssi',
            $speaker_id,
            $photo_hash,
            $mime_type,
            $served_mime,
            $thumbnail_size
        );
    } else {
        $photo_stmt->bind_param('iss', $speaker_id, $photo_hash, $mime_type);
    }
    $photo_stmt->execute();
    $photo = $photo_stmt->get_result()->fetch_assoc()['photo'] ?? null;
    $photo_stmt->close();
    if (!is_string($photo) || $photo === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $served_mime);
    header('Content-Length: ' . strlen($photo));
    echo $photo;
    exit;
}

$svg = speakerInitialsSvg($speaker);
header('Cache-Control: private, no-cache');
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Length: ' . strlen($svg));
echo $svg;
