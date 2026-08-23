<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/profile_helpers.php';
startSecureSession();
requireLogin();

$current_user_id = (int) $_SESSION['user_id'];
$user_id = $current_user_id;
if (isset($_GET['id'])) {
    $requested_user_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
    if ($requested_user_id === null) {
        http_response_code(400);
        exit;
    }
    $user_id = $requested_user_id;
}
if ($user_id < 1 || ($user_id !== $current_user_id && !checkRole('admin'))) {
    http_response_code(403);
    exit;
}
$stmt = $conn->prepare(
    'SELECT username, first_name, last_name, profile_picture_mime,
            HEX(profile_picture_sha256) AS profile_picture_sha256,
            profile_picture_thumbnail, profile_picture_thumbnail_mime,
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

    $serve_full_size = \Dnr\Http\RequestInput::string($_GET, 'size') === 'full';
    $picture = $serve_full_size ? null : ($user['profile_picture_thumbnail'] ?? null);
    $served_mime = $serve_full_size
        ? $mime_type
        : (string) ($user['profile_picture_thumbnail_mime'] ?? '');
    if (!is_string($picture) || $picture === '') {
        $picture_stmt = $conn->prepare('SELECT profile_picture FROM users WHERE id = ?');
        $picture_stmt->bind_param('i', $user_id);
        $picture_stmt->execute();
        $picture = $picture_stmt->get_result()->fetch_assoc()['profile_picture'] ?? null;
        $picture_stmt->close();
        $served_mime = $mime_type;
    }
    if (!is_string($picture) || $picture === '') {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $served_mime);
    header('Content-Length: ' . strlen($picture));
    echo $picture;
    exit;
}

$svg = profileInitialsSvg($user);
header('Cache-Control: private, no-cache');
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Length: ' . strlen($svg));
echo $svg;
