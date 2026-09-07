<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/short_link_helpers.php';
startSecureSession();
requireLogin();
if (!hasRole(['admin', 'editor', 'reviewer'])) { http_response_code(403); exit; }
releaseApplicationSessionLock();
$id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$format = \Dnr\Http\RequestInput::string($_GET, 'format', 'png');
if ($id === null || !in_array($format, ['png', 'svg'], true)) { http_response_code(400); exit; }
$stmt = $conn->prepare("SELECT l.code, l.link_type, l.presentation_id,
    q.$format AS bytes, HEX(q.{$format}_sha256) AS sha256
    FROM short_links l LEFT JOIN short_link_qr_images q ON q.link_id = l.id WHERE l.id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
if (!$link) { http_response_code(404); exit; }
$bytes = $link['bytes'];
if (!is_string($bytes) || $bytes === '') {
    http_response_code(503);
    header('Cache-Control: private, no-store');
    exit('QR images are not available yet. An administrator must complete the image backfill.');
}
$etag = '"' . strtolower($link['sha256']) . '"';
header('Content-Type: ' . ($format === 'png' ? 'image/png' : 'image/svg+xml'));
header('Cache-Control: private, max-age=300, must-revalidate');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="presentation-'
    . (int) $link['presentation_id'] . '-' . $link['link_type'] . '-' . $link['code'] . '.' . $format . '"');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . strlen($bytes));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $bytes;
