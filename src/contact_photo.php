<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/contact_photo_helpers.php';
startSecureSession();
requireLogin();

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare(
    'SELECT contact_first_name, contact_last_name, contact_photo_mime,
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

    $photo_stmt = $conn->prepare('SELECT contact_photo FROM contacts WHERE id = ?');
    $photo_stmt->bind_param('i', $contact_id);
    $photo_stmt->execute();
    $photo = $photo_stmt->get_result()->fetch_assoc()['contact_photo'] ?? null;
    $photo_stmt->close();
    if (!is_string($photo) || $photo === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . strlen($photo));
    echo $photo;
    exit;
}

$svg = contactInitialsSvg($contact);
header('Cache-Control: private, no-cache');
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Length: ' . strlen($svg));
echo $svg;
