<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/contact_photo_helpers.php';
startSecureSession();
requireLogin();
releaseApplicationSessionLock();

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare(
    'SELECT contact_first_name, contact_last_name, contact_photo_mime,
            contact_photo_thumbnail_mime,
            OCTET_LENGTH(contact_photo_thumbnail) AS contact_photo_thumbnail_size,
            HEX(contact_photo_sha256) AS contact_photo_sha256
     FROM contacts
     WHERE id = ?'
);
if (!$stmt) {
    http_response_code(503);
    exit;
}

$stmt->bind_param('i', $contact_id);
$stmt->execute();
$contact = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$contact) {
    http_response_code(404);
    exit;
}

$allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
$mime_type = (string) ($contact['contact_photo_mime'] ?? '');
$photo_hash = strtolower((string) ($contact['contact_photo_sha256'] ?? ''));

header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

if (preg_match('/^[0-9a-f]{64}$/', $photo_hash) === 1
    && in_array($mime_type, $allowed_mime_types, true)
) {
    $etag = '"contact-photo-' . $contact_id . '-' . $photo_hash . '"';
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    $serve_full_size = \Dnr\Http\RequestInput::string($_GET, 'size') === 'full';
    $has_thumbnail = !$serve_full_size
        && (int) ($contact['contact_photo_thumbnail_size'] ?? 0) > 0
        && in_array(
            (string) ($contact['contact_photo_thumbnail_mime'] ?? ''),
            $allowed_mime_types,
            true
        );
    $photo_column = $has_thumbnail ? 'contact_photo_thumbnail' : 'contact_photo';
    $served_mime = $has_thumbnail
        ? (string) $contact['contact_photo_thumbnail_mime']
        : $mime_type;
    $photo_sql = "SELECT {$photo_column} AS photo FROM contacts
                  WHERE id = ? AND contact_photo_sha256 = UNHEX(?)
                    AND contact_photo_mime = ?";
    if ($has_thumbnail) {
        $photo_sql .= ' AND contact_photo_thumbnail_mime = ?'
            . ' AND OCTET_LENGTH(contact_photo_thumbnail) = ?';
    }
    $photo_stmt = $conn->prepare($photo_sql);
    if (!$photo_stmt) {
        http_response_code(503);
        exit;
    }
    if ($has_thumbnail) {
        $thumbnail_size = (int) $contact['contact_photo_thumbnail_size'];
        $photo_stmt->bind_param(
            'isssi',
            $contact_id,
            $photo_hash,
            $mime_type,
            $served_mime,
            $thumbnail_size
        );
    } else {
        $photo_stmt->bind_param('iss', $contact_id, $photo_hash, $mime_type);
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

$svg = contactInitialsSvg($contact);
header('Cache-Control: private, no-cache');
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Length: ' . strlen($svg));
echo $svg;
