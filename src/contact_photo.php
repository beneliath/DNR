<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/contact_photo_helpers.php';
startSecureSession();
requireLogin();

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    http_response_code(400);
    exit;
}

$stmt = $conn->prepare(
    'SELECT contact_first_name, contact_last_name, contact_photo, contact_photo_mime
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
$photo = $contact['contact_photo'] ?? null;

header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

if (is_string($photo) && $photo !== '' && in_array($mime_type, $allowed_mime_types, true)) {
    $etag = '"contact-photo-' . $contact_id . '-' . hash('sha256', $photo) . '"';
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
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
