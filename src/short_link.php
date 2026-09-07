<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/short_link_helpers.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit;
}
$code = \Dnr\Http\RequestInput::string($_GET, 'code');
if (!preg_match('/\A[a-f0-9]{16}\z/', $code)) { http_response_code(404); exit('Link not found.'); }
$conn = applicationDatabaseConnection();
$stmt = $conn->prepare('SELECT id, presentation_id, speaker_id, link_type, target_url, is_enabled FROM short_links WHERE code = ?');
$stmt->bind_param('s', $code);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
if (!$link) { http_response_code(404); exit('Link not found.'); }
if (!$link['is_enabled']) { http_response_code(410); exit('This link is no longer available.'); }
if ($link['link_type'] === 'notes') {
    deliverPresentationNotes($conn, (int) $link['presentation_id'], (int) $link['speaker_id'], (int) $link['id']);
    exit;
}
try { $target = shortLinkTarget($link['target_url']); }
catch (InvalidArgumentException $exception) { http_response_code(410); exit('This destination is unavailable.'); }
recordShortLinkVisit($conn, (int) $link['id'], $_SERVER);
header('Location: ' . $target, true, 302);
