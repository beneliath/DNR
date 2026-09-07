<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation_export_helpers.php';
startSecureSession();
requireLogin();
releaseApplicationSessionLock();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
$presentation_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'presentation_id');
if ($presentation_id === null) {
    http_response_code(400);
    exit('A valid presentation ID is required.');
}

try {
    $stmt = $conn->prepare(
        'SELECT e.event_title, o.organization_name,
                e.event_address_line_1, e.event_address_line_2, e.event_city,
                e.event_state, e.event_zipcode, e.event_country,
                p.id, p.topic_title, p.presentation_date, p.presentation_time,
                p.duration_minutes, p.expected_attendance, p.actual_attendance,
                s.name AS speaker_name
         FROM presentations p
         JOIN engagements e ON e.id = p.engagement_id
         JOIN speakers s ON s.id = p.speaker_id
         LEFT JOIN organizations o ON o.id = e.organization_id
         WHERE p.id = ? AND p.is_archived = 0'
    );
    $stmt->bind_param('i', $presentation_id);
    $stmt->execute();
    $presentation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$presentation) {
        http_response_code(404);
        exit('Presentation not found.');
    }
    if (!class_exists('TCPDF')) {
        throw new RuntimeException('TCPDF is unavailable.');
    }
    require_once __DIR__ . '/engagement_pdf.php';
    $pdf_contents = renderEngagementPdf(buildPresentationExport($presentation, $presentation));
} catch (Throwable $exception) {
    applicationLog('error', 'Unable to generate presentation PDF', [
        'presentation_id' => $presentation_id,
        'error' => $exception->getMessage(),
    ]);
    http_response_code(503);
    exit('The presentation PDF is temporarily unavailable.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . presentationPdfFilename($presentation) . '"');
header('Content-Length: ' . strlen($pdf_contents));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    echo $pdf_contents;
}
