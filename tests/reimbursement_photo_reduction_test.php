<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/reimbursement_submission_helpers.php';

$checks = 0;
function expectPhotoReduction(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejectPhotoReduction(callable $action): void
{
    try { $action(); } catch (InvalidArgumentException) { expectPhotoReduction(true, 'Rejected'); return; }
    throw new RuntimeException('Unsafe or oversized receipts were accepted.');
}
$root = sys_get_temp_dir() . '/dnr-photo-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$oldRoot = getenv('DNR_FILE_STORAGE_PATH');
putenv('DNR_FILE_STORAGE_PATH=' . $root);
putenv('DNR_PUBLIC_BASE_URL=https://example.test');
$prepared = null; $package = null;
try {
    // A real, uncompressed PNG makes a >25 MB request without unrelated private fixtures.
    $image = imagecreatetruecolor(1200, 1800);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    $ink = imagecolorallocate($image, 20, 30, 45);
    for ($y = 80; $y < 1700; $y += 35) imagestring($image, 5, 60, $y, 'RECEIPT: Office supplies   TOTAL $12.34', $ink);
    imagepng($image, $root . '/source.png', 0);
    unset($image);
    $size = filesize($root . '/source.png');
    $checksum = hash_file('sha256', $root . '/source.png');
    $key = persistentFileKey($checksum, 'receipt.png', 'image/png');
    rename($root . '/source.png', $root . '/' . $key);
    $context = [
        'request' => ['request_hash' => 'photo-test', 'start_date' => '2026-01-01', 'end_date' => '2026-11-06', 'status' => 'draft', 'bookkeeper_note' => ''],
        'owner' => ['display_name' => 'Test Owner', 'email' => 'owner@example.test', 'reimbursement_reviewer_email' => ''],
        'setup' => ['account_name' => 'Test Account', 'bookkeeper_first_name' => 'Test', 'bookkeeper_last_name' => 'Bookkeeper', 'bookkeeper_email' => 'bookkeeper@example.test', 'bookkeeper_phone' => '', 'cc_email' => '', 'reviewer_email' => ''],
        'items' => [], 'receipts' => [],
    ];
    for ($i = 1; $i <= 5; $i++) {
        $context['items'][] = ['expense_id' => $i, 'expense_date' => '2026-11-01', 'merchant' => 'Fixture Store', 'description' => 'Supplies', 'amount_cents' => 1234, 'coa_number' => '5000', 'coa_description' => 'Supplies'];
        $context['receipts'][] = ['id' => $i, 'expense_id' => $i, 'storage_key' => $key, 'filename' => 'receipt.png', 'content_type' => 'image/png', 'size' => $size, 'checksum' => $checksum, 'scan_state' => 'clean'];
    }
    expectPhotoReduction($size * 5 > REIMBURSEMENT_MAX_RECEIPT_BYTES, 'Fixture must reproduce the original size failure.');
    rejectPhotoReduction(static fn() => reimbursementBuildPackage($context));
    expectPhotoReduction(reimbursementPreparePhotoCopies($context) === $context, 'Reduction must be opt-in.');
    $originalFingerprint = reimbursementReviewFingerprint($context);
    $context['reduce_photos'] = true;
    $fingerprint = reimbursementReviewFingerprint($context);
    expectPhotoReduction($fingerprint !== $originalFingerprint, 'Changing photo mode requires a fresh review.');
    $prepared = reimbursementPreparePhotoCopies($context);
    expectPhotoReduction(reimbursementReviewFingerprint($prepared) === $fingerprint, 'Temporary paths must not invalidate the approved review.');
    expectPhotoReduction(hash_file('sha256', $root . '/' . $key) === $checksum, 'The uploaded original must remain byte-for-byte intact.');
    foreach ($prepared['receipts'] as $receipt) {
        expectPhotoReduction($receipt['package_size'] < $size && $receipt['package_content_type'] === 'image/jpeg', 'PNG must become a smaller JPEG email copy.');
        expectPhotoReduction(str_ends_with(reimbursementReceiptPackageFilename($receipt), '-email.jpg'), 'Filename must identify the actual JPEG copy.');
    }
    $package = reimbursementSubmissionPackage($prepared);
    expectPhotoReduction(filesize($package) <= REIMBURSEMENT_MAX_PACKAGE_BYTES && reimbursementEmailSize($prepared, $package) < SMTP_MAX_MESSAGE_BYTES, 'The finished ZIP and encoded email must fit.');
    $zip = new ZipArchive(); $zip->open($package);
    $csv = $zip->getFromName(reimbursementPackageBase($context['request']) . '.csv');
    $message = reimbursementSubmissionMessage($prepared);
    foreach ($prepared['receipts'] as $receipt) {
        $name = reimbursementReceiptPackageFilename($receipt);
        expectPhotoReduction(hash('sha256', $zip->getFromName('receipts/' . $name)) === $receipt['package_checksum'], 'ZIP must contain the reviewed photo copy.');
        expectPhotoReduction(str_contains($csv, $name) && str_contains($message['html_body'], $name), 'CSV and email must match ZIP filenames.');
    }
    $zip->close();
    expectPhotoReduction(str_contains($message['body'], 'reduced photo copies') && !str_contains($message['body'], 'all original receipts'), 'Delivery must describe the smaller copies truthfully.');
    foreach (['queued', 'rejected', 'scanning', 'scan_failed'] as $state) {
        $blocked = $context; $blocked['receipts'][0]['scan_state'] = $state;
        rejectPhotoReduction(static fn() => reimbursementPreparePhotoCopies($blocked));
    }
    $many = $context; $many['receipts'] = array_fill(0, 101, $context['receipts'][0]);
    rejectPhotoReduction(static fn() => reimbursementPreparePhotoCopies($many));
    $changed = $prepared; $changed['receipts'][0]['checksum'] = str_repeat('0', 64);
    expectPhotoReduction(reimbursementReviewFingerprint($changed) !== $fingerprint, 'Changed originals must require re-review.');
    foreach (['II', 'MM'] as $order) foreach (range(1, 8) as $orientation) {
        $short = $order === 'II' ? 'v' : 'n'; $long = $order === 'II' ? 'V' : 'N';
        $app1 = "Exif\0\0" . $order . pack($short, 42) . pack($long, 8) . pack($short, 1)
            . pack($short, 0x0112) . pack($short, 3) . pack($long, 1) . pack($short, $orientation) . "\0\0" . pack($long, 0);
        expectPhotoReduction(reimbursementJpegOrientation($app1) === $orientation, 'Both EXIF byte orders and all phone orientations are supported.');
    }
    expectPhotoReduction(reimbursementJpegOrientation("Exif\0\0II") === 1, 'Truncated EXIF is safely ignored.');
    $pdfOnly = $context; $pdfOnly['receipts'] = [['content_type' => 'application/pdf', 'scan_state' => 'clean', 'size' => 26_000_000]];
    $pdfPrepared = reimbursementPreparePhotoCopies($pdfOnly);
    expectPhotoReduction($pdfPrepared['receipts'] === $pdfOnly['receipts'], 'PDFs must remain unchanged.');
    rejectPhotoReduction(static fn() => reimbursementReceiptsReady($pdfPrepared['receipts']));
    $paths = array_column($prepared['receipts'], 'package_path');
    reimbursementCleanupPhotoCopies($prepared);
    expectPhotoReduction(!array_filter($paths, 'file_exists'), 'Temporary photo copies must be removed.');
    echo "Reimbursement photo reduction: {$checks} checks passed.\n";
} finally {
    if ($prepared !== null) reimbursementCleanupPhotoCopies($prepared);
    if ($package !== null) @unlink($package);
    foreach (glob($root . '/*') as $path) unlink($path);
    if (is_file($root . '/.lifecycle.lock')) unlink($root . '/.lifecycle.lock');
    rmdir($root);
    putenv($oldRoot === false ? 'DNR_FILE_STORAGE_PATH' : 'DNR_FILE_STORAGE_PATH=' . $oldRoot);
}
