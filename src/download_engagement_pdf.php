<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/engagement_export_helpers.php';
require_once __DIR__ . '/engagement_contact_helpers.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';

startSecureSession();
requireLogin();
releaseApplicationSessionLock();

$engagement_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!$engagement_id) {
    http_response_code(400);
    exit('A valid engagement ID is required.');
}

$engagement_stmt = $conn->prepare(
    'SELECT e.*, COALESCE(caller.username, e.caller_name) AS caller_name,
            o.organization_name
     FROM engagements e
     LEFT JOIN organizations o ON e.organization_id = o.id
     LEFT JOIN users caller ON caller.id = e.caller_user_id
     WHERE e.id = ?'
);
if (!$engagement_stmt) {
    http_response_code(500);
    exit('Unable to prepare the engagement export.');
}
$engagement_stmt->bind_param('i', $engagement_id);
$engagement_stmt->execute();
$engagement = $engagement_stmt->get_result()->fetch_assoc();
$engagement_stmt->close();

if (!$engagement) {
    http_response_code(404);
    exit('Engagement not found.');
}

try {
    $rescheduled_target = fetchEngagementRescheduleTarget($conn, (int) $engagement_id);
    if ($rescheduled_target !== null) {
        $engagement['rescheduled_event_label'] = engagementReferenceLabel($rescheduled_target);
    }
} catch (Throwable $exception) {
    applicationLog('error', 'Unable to load the rescheduled-event PDF link', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
    http_response_code(500);
    exit('Unable to prepare the engagement lifecycle export.');
}

try {
    $contacts = fetchEngagementContacts($conn, $engagement_id);
} catch (Throwable $exception) {
    applicationLog('error', 'Unable to load engagement contacts for PDF export', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
    http_response_code(500);
    exit('Unable to prepare the engagement contacts export.');
}

$presentation_stmt = $conn->prepare(
    'SELECT topic_title, presentation_date, presentation_time, speaker_name, duration_minutes,
            expected_attendance, actual_attendance
     FROM presentations
     WHERE engagement_id = ? AND is_archived = 0
     ORDER BY presentation_date, presentation_time, id'
);
if (!$presentation_stmt) {
    http_response_code(500);
    exit('Unable to prepare the engagement presentations export.');
}
$presentation_stmt->bind_param('i', $engagement_id);
$presentation_stmt->execute();
$presentations = $presentation_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$presentation_stmt->close();

try {
    $pdf_chron_limit = applicationWorkflowSetting('pdf_max_chron_entries');
    $chron_entries = fetchChronLogEntries($conn, $engagement_id, false, $pdf_chron_limit, 0);
} catch (Throwable $exception) {
    applicationLog('error', 'Unable to generate engagement PDF', ['error' => $exception->getMessage()]);
    http_response_code(500);
    exit('Unable to prepare the engagement Chron export.');
}

$autoload_paths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    '/opt/dnr/vendor/autoload.php',
];
foreach ($autoload_paths as $autoload_path) {
    if (is_file($autoload_path)) {
        require_once $autoload_path;
        break;
    }
}
if (!class_exists('TCPDF')) {
    applicationLog('critical', 'TCPDF is unavailable; Composer dependencies are incomplete');
    http_response_code(503);
    exit('PDF downloads are temporarily unavailable.');
}

require_once __DIR__ . '/engagement_pdf.php';

$pdf_contents = renderEngagementPdf(
    buildEngagementExport($engagement, $contacts, $presentations, $chron_entries)
);
$filename = engagementPdfFilename($engagement);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf_contents));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $pdf_contents;
