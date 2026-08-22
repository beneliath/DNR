<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/profile_helpers.php';
startSecureSession();
requireLogin();

$current_user_id = (int) $_SESSION['user_id'];
$user_id = $current_user_id;
if (isset($_GET['id'])) {
    if (!ctype_digit((string) $_GET['id'])) {
        http_response_code(400);
        exit;
    }
    $user_id = (int) $_GET['id'];
}
if ($user_id < 1 || ($user_id !== $current_user_id && !checkRole('admin'))) {
    http_response_code(403);
    exit;
}
$stmt = $conn->prepare(
    'SELECT username, first_name, last_name, profile_picture_mime,
            HEX(profile_picture_sha256) AS profile_picture_sha256,
            profile_picture_updated_at
     FROM users
     WHERE id = ?'
);

if (!$stmt) {
    http_response_code(503);
    exit;
}

$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    exit;
}

$allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
$mime_type = (string) ($user['profile_picture_mime'] ?? '');
$picture_hash = strtolower((string) ($user['profile_picture_sha256'] ?? ''));

header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

if (preg_match('/^[0-9a-f]{64}$/', $picture_hash) === 1
    && in_array($mime_type, $allowed_mime_types, true)
) {
    $etag = '"profile-' . $user_id . '-' . $picture_hash . '"';
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    $picture_stmt = $conn->prepare('SELECT profile_picture FROM users WHERE id = ?');
    $picture_stmt->bind_param('i', $user_id);
    $picture_stmt->execute();
    $picture = $picture_stmt->get_result()->fetch_assoc()['profile_picture'] ?? null;
    $picture_stmt->close();
    if (!is_string($picture) || $picture === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . strlen($picture));
    echo $picture;
    exit;
}

$initials = htmlspecialchars(profileInitials($user), ENT_QUOTES | ENT_XML1, 'UTF-8');
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" role="img">'
    . '<rect width="128" height="128" rx="64" fill="#1f57e7"/>'
    . '<text x="64" y="68" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="46" font-weight="700" text-anchor="middle" dominant-baseline="middle">'
    . $initials
    . '</text></svg>';
header('Cache-Control: private, no-cache');
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Length: ' . strlen($svg));
echo $svg;
