<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/reimbursement_email_helpers.php';

function expectReimbursementAttachment(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$path = tempnam(sys_get_temp_dir(), 'reimbursement-attachment-test-');
if ($path === false) throw new RuntimeException('Unable to stage test ZIP.');
try {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create test ZIP.');
    $zip->addFromString('request.pdf', '%PDF-report');
    $zip->addFromString('receipts/receipt.jpg', 'JPEG-receipt');
    $zip->addFromString('receipts/second.png', 'PNG-receipt');
    $zip->close();
    $attachment = ['filename' => 'request.zip', 'content_type' => 'application/zip', 'data' => file_get_contents($path)];
    $manifest = [
        ['archive_path' => 'request.pdf', 'filename' => 'request.pdf', 'content_type' => 'application/pdf'],
        ['archive_path' => 'receipts/receipt.jpg', 'filename' => 'receipt.jpg', 'content_type' => 'image/jpeg'],
        ['archive_path' => 'receipts/second.png', 'filename' => 'second.png', 'content_type' => 'image/png'],
    ];
    $files = reimbursementAttachmentsFromVerifiedZip($attachment, $manifest);
    expectReimbursementAttachment(count($files) === 4 && $files[0]['data'] === $attachment['data']
        && $files[1]['data'] === '%PDF-report' && $files[2]['data'] === 'JPEG-receipt'
        && $files[3]['data'] === 'PNG-receipt',
        'Separate attachments must match the immutable ZIP entries.');
    $legacyFiles = reimbursementAttachmentsFromVerifiedZip($attachment, []);
    expectReimbursementAttachment(count($legacyFiles) === 4 && $legacyFiles[1]['data'] === '%PDF-report'
        && $legacyFiles[2]['filename'] === 'receipt.jpg' && $legacyFiles[2]['data'] === 'JPEG-receipt'
        && $legacyFiles[3]['filename'] === 'second.png' && $legacyFiles[3]['data'] === 'PNG-receipt',
        'Older queued messages also attach the report and each receipt from the saved ZIP.');
    $manifest[1]['archive_path'] = 'receipts/missing.jpg';
    $manifest[1]['filename'] = 'missing.jpg';
    try {
        reimbursementAttachmentsFromVerifiedZip($attachment, $manifest);
        throw new RuntimeException('Missing receipt was accepted.');
    } catch (DomainException $exception) {
        expectReimbursementAttachment($exception->getMessage() !== '', 'Missing receipt must stop delivery.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to replace test ZIP.');
    $zip->addFromString('request.pdf', str_repeat('A', 15 * 1024 * 1024));
    $zip->close();
    $attachment['data'] = file_get_contents($path);
    try {
        reimbursementAttachmentsFromVerifiedZip($attachment, [$manifest[0]]);
        throw new RuntimeException('Oversized attachments were accepted.');
    } catch (DomainException $exception) {
        expectReimbursementAttachment(str_contains($exception->getMessage(), '15 MB'),
            'Combined attachment size must be checked before extracting large files.');
    }
} finally {
    unlink($path);
}

echo "Reimbursement email attachment tests passed.\n";
