<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/short_link_helpers.php';
require_once __DIR__ . '/notes_cache_helpers.php';
header('Cache-Control: private, no-store');
header('Cloudflare-CDN-Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit;
}
$code = \Dnr\Http\RequestInput::string($_GET, 'code');
if (!preg_match('/\A[a-f0-9]{16}\z/', $code)) { http_response_code(404); exit('Link not found.'); }
$conn = applicationDatabaseConnection();
$stmt = $conn->prepare('SELECT l.id, l.presentation_id, l.speaker_id, l.link_type, l.target_url, l.is_enabled,
    q.code IS NULL AS edge_cache_ready FROM short_links l
    LEFT JOIN notes_cache_purge_queue q ON q.code = l.code WHERE l.code = ?');
$stmt->bind_param('s', $code);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
if (!$link) { http_response_code(404); exit('Link not found.'); }
if (!$link['is_enabled']) { http_response_code(410); exit('This link is no longer available.'); }
$download = \Dnr\Http\RequestInput::string($_GET, 'download') === '1';
if ($download && $link['link_type'] !== 'notes') { http_response_code(404); exit('Notes not found.'); }
if ($link['link_type'] === 'notes') {
    if ($download) {
        $edgeTtl = $link['edge_cache_ready'] ? notesEdgeCacheTtl($code, $_SERVER) : 0;
        deliverPresentationNotes($conn, (int) $link['presentation_id'], (int) $link['speaker_id'], (int) $link['id'], $edgeTtl);
        exit;
    }
    $notes = $conn->prepare('SELECT 1 FROM presentation_notes
        WHERE presentation_id = ? AND speaker_id = ? AND pdf IS NOT NULL');
    $notes->bind_param('ii', $link['presentation_id'], $link['speaker_id']);
    $notes->execute();
    if (!$notes->get_result()->fetch_row()) { http_response_code(404); exit('Notes are not available yet.'); }
    // Count the uncached navigation, including when Cloudflare serves the PDF.
    // These are link visits, not proof that the PDF was downloaded completely.
    recordShortLinkVisit($conn, (int) $link['id'], $_SERVER);
    header('Location: /surls/' . $code . '/speaker-notes.pdf', true, 302);
    exit;
}
try { $target = shortLinkTarget($link['target_url']); }
catch (InvalidArgumentException $exception) { http_response_code(410); exit('This destination is unavailable.'); }
recordShortLinkVisit($conn, (int) $link['id'], $_SERVER);
header('Location: ' . $target, true, 302);
