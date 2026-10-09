<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/reimbursement_submission_helpers.php';
require_once __DIR__ . '/../src/reimbursement_email_helpers.php';

$root = sys_get_temp_dir() . '/dnr-receipt-package-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('Unable to create test storage.');
$previousRoot = getenv('DNR_FILE_STORAGE_PATH');
$previousBase = getenv('DNR_PUBLIC_BASE_URL');
putenv('DNR_PUBLIC_BASE_URL=https://example.test');
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
    $fixtures = [
        [11, 101, 'first.png', 'image/png', $pngBytes],
        [12, 102, 'second.pdf', 'application/pdf', $pdfBytes],
        [13, 103, 'third.png', 'image/png', $pngBytes],
    ];
    $compatibleTools = is_executable('/usr/bin/qpdf') && is_executable('/usr/bin/prlimit') && is_executable('/usr/bin/timeout');
    if ($compatibleTools) {
        $compressed = file_get_contents(__DIR__ . '/fixtures/compressed-receipt.pdf');
        $probe = new \setasign\Fpdi\Tcpdf\Fpdi();
        try {
            $probe->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($compressed));
            throw new RuntimeException('The regression fixture must need compressed-PDF compatibility.');
        } catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $error) {
            if ($error->getCode() !== $error::COMPRESSED_XREF) throw $error;
        }
        $fixtures[] = [12, 104, 'compressed.pdf', 'application/pdf', $compressed];
    } elseif (PHP_OS_FAMILY === 'Linux') {
        throw new RuntimeException('Install qpdf and util-linux to test receipt PDF compatibility.');
    } else {
        echo "Compressed receipt checks require the Linux application image.\n";
    }
    foreach ($fixtures as [$expenseId, $receiptId, $filename, $type, $bytes]) {
        $checksum = hash('sha256', $bytes);
        $key = persistentFileKey($checksum, $filename, $type);
        file_put_contents($root . '/' . $key, $bytes);
        $receipt = ['expense_id' => $expenseId, 'id' => $receiptId, 'storage_key' => $key,
            'filename' => $filename, 'content_type' => $type, 'size' => strlen($bytes),
            'checksum' => $checksum, 'scan_state' => 'unscanned'];
        if ($filename === 'compressed.pdf') {
            $reader = reimbursementCompatiblePdf($root . '/' . $key);
            try {
                rewind($reader->getStream());
                $compatible = stream_get_contents($reader->getStream());
            } finally { $reader->cleanUp(); }
            $reportKey = hash('sha256', $compatible);
            file_put_contents($root . '/' . $reportKey, $compatible);
            $receipt += ['report_key' => $reportKey, 'report_size' => strlen($compatible), 'report_checksum' => hash('sha256', $compatible)];
        }
        $receipts[] = $receipt;
    }
    $context = [
        'request' => ['request_hash' => 'receipt-package-test', 'start_date' => '2026-01-01',
            'end_date' => '2026-09-29', 'status' => 'submitted', 'bookkeeper_note' => ''],
        'owner' => ['display_name' => 'Test Owner'],
        'setup' => ['account_name' => 'Test Account', 'bookkeeper_first_name' => '',
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
    if ($pages !== ($compatibleTools ? 9 : 7)) throw new RuntimeException('The report must include every PDF page and image receipt.');
    if ($compatibleTools) {
        $reportPath = $root . '/report.pdf';
        $textPath = $root . '/report.txt';
        file_put_contents($reportPath, $report);
        $process = proc_open(['/usr/bin/pdftotext', $reportPath, $textPath], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Unable to inspect generated report text.');
        $text = file_get_contents($textPath);
        foreach (['Compressed receipt page one', 'Compressed receipt page two'] as $label) {
            if (!str_contains($text, $label)) throw new RuntimeException('Compressed receipt text was lost from the report.');
        }
        if (glob(sys_get_temp_dir() . '/dnr-receipt-pdf-*')) throw new RuntimeException('A compatible receipt temporary directory leaked.');
    }

    // Protected and damaged receipts must fail clearly, without exposing parser internals.
    $protected = new TCPDF();
    $protected->SetProtection(['print'], 'test-password', 'owner-password');
    $protected->AddPage();
    foreach (['protected' => $protected->Output('', 'S'), 'damaged' => '%PDF-1.4 broken'] as $kind => $bytes) {
        $badReceipt = $receipts[1];
        $badReceipt['filename'] = $kind . '.pdf';
        $badReceipt['storage_key'] = hash('sha256', $bytes);
        file_put_contents($root . '/' . $badReceipt['storage_key'], $bytes);
        try {
            renderReimbursementPdf($context['request'], $context['owner'], $context['items'], [12 => [$badReceipt]], $context['setup']);
            throw new RuntimeException('An unreadable receipt must not silently disappear from the report.');
        } catch (ReimbursementReceiptPdfException $error) {
            $hint = $kind === 'protected' ? 'Upload an unprotected PDF copy.' : 'Save a new PDF copy';
            if (!str_contains($error->getMessage(), $kind . '.pdf') || !str_contains($error->getMessage(), $hint)
                || str_contains($error->getMessage(), $root)) throw new RuntimeException('Receipt error must identify the file and recovery action only.');
        }
    }

    $base = reimbursementPackageBase($context['request']);
    $packagePath = createReimbursementZip($report, reimbursementExpenseCsv($context['items'], $receipts), $receipts, $base);
    try {
        $context['owner'] += ['email' => 'owner@example.test', 'reimbursement_reviewer_email' => ''];
        $context['setup']['bookkeeper_email'] = 'bookkeeper@example.test';
        $context['setup']['reviewer_email'] = '';
        $message = reimbursementSubmissionMessage($context, true);
        $recipients = reimbursementSubmissionRecipients($context);
        $mime = smtpMessageData(
            (string) (getenv('DNR_MAIL_FROM') ?: $context['owner']['email']), deploymentConfig()->string('brand.mail_name'),
            $recipients['to'][0], $message['subject'], $message['body'], $context['owner']['email'], $message['html_body'],
            ['to' => $recipients['to'], 'cc' => $recipients['cc']],
            attachments: [['filename' => $base . '.zip', 'content_type' => 'application/zip', 'data' => file_get_contents($packagePath)]]
        );
        if (reimbursementEmailSize($context, $packagePath) !== strlen($mime) + 2) {
            throw new RuntimeException('The review meter must exactly match the full encoded SMTP message size.');
        }
        $attachments = reimbursementAttachmentsFromVerifiedZip(
            ['filename' => $base . '.zip', 'content_type' => 'application/zip', 'data' => file_get_contents($packagePath)],
            reimbursementIndividualAttachmentManifest($context)
        );
        if (count($attachments) !== 1) throw new RuntimeException('The email must attach only the ZIP.');
        $zip = new ZipArchive();
        $zip->open($packagePath);
        if ($zip->numFiles !== 2 + count($receipts) || $zip->getFromName($base . '.pdf') !== $report
            || $zip->getFromName($base . '.csv') !== reimbursementExpenseCsv($context['items'], $receipts)) {
            throw new RuntimeException('The ZIP must retain the report, CSV, and three receipts.');
        }
        foreach ($receipts as $receipt) {
            if ($zip->getFromName('receipts/' . reimbursementReceiptPackageFilename($receipt))
                !== file_get_contents($root . '/' . $receipt['storage_key'])) {
                throw new RuntimeException('A ZIP receipt differs from the original.');
            }
        }
        $zip->close();
        $mime = smtpMessageContent('Test report', '<p>Test report</p>', $attachments);
        if (substr_count($mime['body'], 'Content-Disposition: attachment;') !== 1) {
            throw new RuntimeException('The SMTP message must include only one ZIP file part.');
        }
    } finally {
        unlink($packagePath);
    }
    echo "Reimbursement receipt package: report, ZIP contents, and single MIME attachment verified.\n";
} finally {
    putenv($previousBase === false ? 'DNR_PUBLIC_BASE_URL' : 'DNR_PUBLIC_BASE_URL=' . $previousBase);
    foreach (glob($root . '/*') ?: [] as $path) unlink($path);
    if (is_file($root . '/.lifecycle.lock')) unlink($root . '/.lifecycle.lock');
    rmdir($root);
    if ($previousRoot === false) putenv('DNR_FILE_STORAGE_PATH');
    else putenv('DNR_FILE_STORAGE_PATH=' . $previousRoot);
}
