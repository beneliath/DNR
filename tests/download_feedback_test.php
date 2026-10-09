<?php
require_once __DIR__ . '/../src/download_feedback_helpers.php';
foreach ([
    [200, ['Content-Disposition: attachment; filename="report.pdf"'], 'started'],
    [206, ['content-disposition: inline; filename="notes.pdf"'], 'started'],
    [200, ['Content-Type: text/html'], 'failed'],
    [302, ['Content-Disposition: attachment; filename="report.pdf"'], 'failed'],
    [404, ['Content-Disposition: attachment; filename="report.pdf"'], 'failed'],
    [500, [], 'failed'],
] as [$status, $headers, $expected]) {
    if (downloadFeedbackState($status, $headers) !== $expected) {
        fwrite(STDERR, "Download feedback failed for HTTP $status\n"); exit(1);
    }
}
foreach ([null, '', '../cookie', str_repeat('a', 31), ['array']] as $token) {
    sendDownloadFeedback(['_download_feedback' => $token]);
}
echo "Download feedback tests passed.\n";
