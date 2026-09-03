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
releaseApplicationSessionLock();
$stmt = $conn->prepare(
    'SELECT username, first_name, last_name, profile_picture_mime,
            HEX(profile_picture_sha256) AS profile_picture_sha256,
            profile_picture_thumbnail_mime,
            OCTET_LENGTH(profile_picture_thumbnail) AS profile_picture_thumbnail_size,
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
    $has_thumbnail = !$serve_full_size
        && (int) ($user['profile_picture_thumbnail_size'] ?? 0) > 0
        && in_array(
            (string) ($user['profile_picture_thumbnail_mime'] ?? ''),
            $allowed_mime_types,
            true
        );
    $picture_column = $has_thumbnail ? 'profile_picture_thumbnail' : 'profile_picture';
    $served_mime = $has_thumbnail
        ? (string) $user['profile_picture_thumbnail_mime']
        : $mime_type;
    $picture_sql = "SELECT {$picture_column} AS picture FROM users
                    WHERE id = ? AND profile_picture_sha256 = UNHEX(?)
                      AND profile_picture_mime = ?";
    if ($has_thumbnail) {
        $picture_sql .= ' AND profile_picture_thumbnail_mime = ?'
            . ' AND OCTET_LENGTH(profile_picture_thumbnail) = ?';
    }
    $picture_stmt = $conn->prepare($picture_sql);
    if (!$picture_stmt) {
        http_response_code(503);
        exit;
    }
    if ($has_thumbnail) {
        $thumbnail_size = (int) $user['profile_picture_thumbnail_size'];
        $picture_stmt->bind_param(
            'isssi',
            $user_id,
            $picture_hash,
            $mime_type,
            $served_mime,
            $thumbnail_size
        );
    } else {
        $picture_stmt->bind_param('iss', $user_id, $picture_hash, $mime_type);
    }
    $picture_stmt->execute();
    $picture = $picture_stmt->get_result()->fetch_assoc()['picture'] ?? null;
    $picture_stmt->close();
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
