<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation_qr_pdf.php';
startSecureSession();
requireLogin();
if (!hasRole(['admin', 'editor', 'reviewer'])) { http_response_code(403); exit('Forbidden.'); }
releaseApplicationSessionLock();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit;
}
$engagementId = \Dnr\Http\RequestInput::positiveInt($_GET, 'engagement_id');
$presentationId = \Dnr\Http\RequestInput::positiveInt($_GET, 'presentation_id');
if ((isset($_GET['engagement_id']) && $engagementId === null)
    || (isset($_GET['presentation_id']) && $presentationId === null)
    || (($engagementId === null) === ($presentationId === null))) {
    http_response_code(400); exit('Choose a valid engagement or presentation.');
}
if ($presentationId !== null) {
    $stmt = $conn->prepare('SELECT e.id AS engagement_id, e.event_title, p.id AS presentation_id, p.topic_title, s.name AS speaker_name
        FROM presentations p JOIN engagements e ON e.id = p.engagement_id JOIN speakers s ON s.id = p.speaker_id
        WHERE p.id = ? AND p.is_archived = 0');
    $stmt->bind_param('i', $presentationId);
} else {
    $stmt = $conn->prepare('SELECT id AS engagement_id, event_title FROM engagements WHERE id = ?');
    $stmt->bind_param('i', $engagementId);
}
$stmt->execute();
$context = $stmt->get_result()->fetch_assoc();
if (!$context) { http_response_code(404); exit('Engagement or presentation not found.'); }
try {
    $links = fetchPresentationQrPdfLinks($conn, (int) $context['engagement_id'], $presentationId);
    $contents = renderPresentationQrPdf($context, $links);
} catch (Throwable $exception) {
    applicationLog('error', 'Unable to render presentation QR PDF', ['error' => $exception->getMessage()]);
    http_response_code(503); exit('The QR code PDF is unavailable. Check that all QR images have been generated and try again.');
}
$filename = $presentationId !== null ? 'presentation-' . $presentationId : 'engagement-' . $engagementId;
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '-qr-codes.pdf"');
header('Content-Length: ' . strlen($contents));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo $contents;
