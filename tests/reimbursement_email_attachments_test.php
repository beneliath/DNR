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
    $zip->addFromString('request.csv', "Date of Expense,Amount (USD)\r\n9/29/2026,$12.34\r\n");
    $zip->addFromString('receipts/receipt.jpg', 'JPEG-receipt');
    $zip->addFromString('receipts/second.png', 'PNG-receipt');
    $zip->close();
    $attachment = ['filename' => 'request.zip', 'content_type' => 'application/zip', 'data' => file_get_contents($path)];
    $manifest = [
        ['archive_path' => 'request.pdf', 'filename' => 'request.pdf', 'content_type' => 'application/pdf'],
        ['archive_path' => 'request.csv', 'filename' => 'request.csv', 'content_type' => 'text/csv'],
        ['archive_path' => 'receipts/receipt.jpg', 'filename' => 'receipt.jpg', 'content_type' => 'image/jpeg'],
        ['archive_path' => 'receipts/second.png', 'filename' => 'second.png', 'content_type' => 'image/png'],
    ];
    $files = reimbursementAttachmentsFromVerifiedZip($attachment, $manifest);
    expectReimbursementAttachment($files === [$attachment], 'Only the immutable ZIP is attached.');
    $legacyFiles = reimbursementAttachmentsFromVerifiedZip($attachment, []);
    expectReimbursementAttachment($legacyFiles === [$attachment], 'Older queued messages also attach only the ZIP.');
    $wrongCsv = $manifest;
    $wrongCsv[1]['filename'] = 'other.csv';
    try {
        reimbursementAttachmentsFromVerifiedZip($attachment, $wrongCsv);
        throw new RuntimeException('Mismatched CSV filename was accepted.');
    } catch (DomainException $exception) {
        expectReimbursementAttachment($exception->getMessage() !== '', 'CSV name must match the report.');
    }
    $manifest[2]['archive_path'] = 'receipts/missing.jpg';
    $manifest[2]['filename'] = 'missing.jpg';
    try {
        reimbursementAttachmentsFromVerifiedZip($attachment, $manifest);
        throw new RuntimeException('Missing receipt was accepted.');
    } catch (DomainException $exception) {
        expectReimbursementAttachment($exception->getMessage() !== '', 'Missing receipt must stop delivery.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to replace test ZIP.');
    $zip->addFromString('request.pdf', '%PDF-report');
    $zip->addFromString('receipts/receipt.jpg', 'JPEG-receipt');
    $zip->close();
    $oldAttachment = [...$attachment, 'data' => file_get_contents($path)];
    $oldFiles = reimbursementAttachmentsFromVerifiedZip($oldAttachment, []);
    expectReimbursementAttachment($oldFiles === [$oldAttachment], 'Packages created before CSV support still deliver as one ZIP.');
    $oldManifest = [$manifest[0], ['archive_path' => 'receipts/receipt.jpg', 'filename' => 'receipt.jpg', 'content_type' => 'image/jpeg']];
    expectReimbursementAttachment(count(reimbursementAttachmentsFromVerifiedZip($oldAttachment, $oldManifest)) === 1,
        'Previously approved attachment manifests still deliver without a CSV.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to replace test ZIP.');
    $zip->addFromString('request.pdf', str_repeat('A', 26 * 1024 * 1024));
    $zip->close();
    $attachment['data'] = file_get_contents($path);
    expectReimbursementAttachment(count(reimbursementAttachmentsFromVerifiedZip($attachment, [$manifest[0]])) === 1,
        'The limit applies to the compressed ZIP, not its uncompressed contents.');
    // STORE makes the archive size predictable and exercises the exact limit.
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('request.pdf', 'A');
    $zip->setCompressionName('request.pdf', ZipArchive::CM_STORE);
    $zip->close();
    $overhead = strlen(file_get_contents($path)) - 1;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('request.pdf', str_repeat('A', REIMBURSEMENT_MAX_PACKAGE_BYTES - $overhead));
    $zip->setCompressionName('request.pdf', ZipArchive::CM_STORE);
    $zip->close();
    $attachment['data'] = file_get_contents($path);
    expectReimbursementAttachment(strlen($attachment['data']) === REIMBURSEMENT_MAX_PACKAGE_BYTES,
        'Boundary fixture must be exactly 18 MB.');
    expectReimbursementAttachment(count(reimbursementAttachmentsFromVerifiedZip($attachment, [$manifest[0]])) === 1,
        'A ZIP exactly at 18 MB is accepted.');
    $attachment['data'] .= 'X';
    try {
        reimbursementAttachmentsFromVerifiedZip($attachment, [$manifest[0]]);
        throw new RuntimeException('Oversized ZIP was accepted.');
    } catch (DomainException $exception) {
        expectReimbursementAttachment(str_contains($exception->getMessage(), '18 MB'),
            'A ZIP one byte over 18 MB must be rejected.');
    }

} finally {
    unlink($path);
}

echo "Reimbursement email attachment tests passed.\n";
