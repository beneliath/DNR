<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/reimbursement_submission_helpers.php';
require_once __DIR__ . '/../src/reimbursement_email_helpers.php';

$root = sys_get_temp_dir() . '/dnr-receipt-package-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Unable to create test storage.');
$previousRoot = getenv('DNR_FILE_STORAGE_PATH');
putenv('DNR_FILE_STORAGE_PATH=' . $root);

try {
    $sourcePdf = new TCPDF();
    $sourcePdf->AddPage();
    $sourcePdf->Write(8, 'PDF receipt page one');
    $sourcePdf->AddPage();
    $sourcePdf->Write(8, 'PDF receipt page two');
    $pdfBytes = $sourcePdf->Output('', 'S');
    $image = imagecreatetruecolor(60, 40);
    imagefill($image, 0, 0, imagecolorallocate($image, 240, 245, 255));
    ob_start();
    imagepng($image);
    $pngBytes = ob_get_clean();
    if (!is_string($pngBytes)) throw new RuntimeException('Unable to create test image.');

    $receipts = [];
    foreach ([
        [11, 101, 'first.png', 'image/png', $pngBytes],
        [12, 102, 'second.pdf', 'application/pdf', $pdfBytes],
        [13, 103, 'third.png', 'image/png', $pngBytes],
    ] as [$expenseId, $receiptId, $filename, $type, $bytes]) {
        $checksum = hash('sha256', $bytes);
        $key = persistentFileKey($checksum, $filename, $type);
        file_put_contents($root . '/' . $key, $bytes);
        $receipts[] = ['expense_id' => $expenseId, 'id' => $receiptId, 'storage_key' => $key,
            'filename' => $filename, 'content_type' => $type, 'size' => strlen($bytes),
            'checksum' => $checksum, 'scan_state' => 'unscanned'];
    }
    $context = [
        'request' => ['request_hash' => 'receipt-package-test', 'start_date' => '2026-01-01',
            'end_date' => '2026-09-29', 'status' => 'submitted', 'bookkeeper_note' => ''],
        'owner' => ['display_name' => 'Test Owner'],
        'setup' => ['organization_name' => 'Test Organization', 'bookkeeper_first_name' => '',
            'bookkeeper_last_name' => '', 'bookkeeper_email' => '', 'bookkeeper_phone' => '', 'cc_email' => ''],
        'items' => array_map(static fn($id) => ['expense_id' => $id, 'expense_date' => '2026-09-29',
            'merchant' => 'Test Merchant', 'description' => 'Test expense', 'amount_cents' => 1234,
            'coa_number' => '5291', 'coa_description' => 'Equipment'], [11, 12, 13]),
        'receipts' => $receipts,
    ];
    $byExpense = [];
    foreach ($receipts as $receipt) $byExpense[$receipt['expense_id']][] = $receipt;
    $report = renderReimbursementPdf($context['request'], $context['owner'], $context['items'], $byExpense, $context['setup']);
    $reader = new \setasign\Fpdi\Tcpdf\Fpdi();
    $pages = $reader->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($report));
    if ($pages !== 7) throw new RuntimeException('The report must include both PDF receipt pages and both image receipts.');

    $base = reimbursementPackageBase($context['request']);
    $packagePath = createReimbursementZip($report, reimbursementExpenseCsv($context['items'], $receipts), $receipts, $base);
    try {
        $attachments = reimbursementAttachmentsFromVerifiedZip(
            ['filename' => $base . '.zip', 'content_type' => 'application/zip', 'data' => file_get_contents($packagePath)],
            reimbursementIndividualAttachmentManifest($context)
        );
        if (count($attachments) !== 6) throw new RuntimeException('The email must attach the ZIP, PDF report, CSV, and three receipts.');
        foreach ($receipts as $index => $receipt) {
            $attachment = $attachments[$index + 3];
            if ($attachment['filename'] !== reimbursementReceiptPackageFilename($receipt)
                || $attachment['content_type'] !== $receipt['content_type']
                || $attachment['data'] !== file_get_contents($root . '/' . $receipt['storage_key'])) {
                throw new RuntimeException('A separate receipt differs from the ZIP original.');
            }
        }
        $mime = smtpMessageContent('Test report', '<p>Test report</p>', $attachments);
        if (substr_count($mime['body'], 'Content-Disposition: attachment;') !== 6) {
            throw new RuntimeException('The SMTP message must include all six file parts.');
        }
    } finally {
        unlink($packagePath);
    }
    echo "Reimbursement receipt package: report and all six MIME attachments verified.\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) unlink($path);
    if (is_file($root . '/.lifecycle.lock')) unlink($root . '/.lifecycle.lock');
    rmdir($root);
    if ($previousRoot === false) putenv('DNR_FILE_STORAGE_PATH');
    else putenv('DNR_FILE_STORAGE_PATH=' . $previousRoot);
}
