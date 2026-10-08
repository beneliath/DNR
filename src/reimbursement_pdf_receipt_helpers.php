<?php

declare(strict_types=1);

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\StreamReader;

/** Safe to show to the authorized request owner; never contains parser output or storage paths. */
class ReimbursementReceiptPdfException extends InvalidArgumentException {}

/**
 * Expand compressed PDF object/xref streams without rasterizing or changing the stored receipt.
 * The returned reader owns an open, unlinked temporary file until FPDI releases it.
 */
function reimbursementCompatiblePdf(string $source): StreamReader
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Receipt conversion requires the isolated worker.');
    foreach (['/usr/bin/qpdf', '/usr/bin/timeout', '/usr/bin/prlimit'] as $tool) {
        if (!is_executable($tool)) throw new RuntimeException('PDF compatibility tools unavailable.');
    }
    if (!function_exists('proc_open')) throw new RuntimeException('PDF compatibility tools unavailable.');
    $directory = sys_get_temp_dir() . '/dnr-receipt-pdf-' . bin2hex(random_bytes(16));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Unable to stage compatible receipt.');
    $output = $directory . '/compatible.pdf';
    try {
        $process = proc_open([
            '/usr/bin/timeout', '--signal=KILL', '20',
            '/usr/bin/prlimit', '--as=268435456', '--cpu=15', '--fsize=33554432', '--core=0', '--',
            '/usr/bin/qpdf', '--object-streams=disable', '--stream-data=preserve', $source, $output,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        // qpdf exit 3 indicates recovered warnings. Do not silently repair a damaged receipt.
        if (!is_resource($process) || proc_close($process) !== 0
            || !is_file($output) || filesize($output) < 1 || filesize($output) > 33554432) {
            throw new RuntimeException('Unable to prepare compatible receipt.');
        }
        return StreamReader::createByFile($output);
    } finally {
        if (is_file($output)) unlink($output);
        rmdir($directory);
    }
}

/** Prepare a separate immutable derivative; the original remains the ZIP attachment. */
function prepareReimbursementPdfReceipt(mysqli $conn, array $receipt): void
{
    if ($receipt['content_type'] !== 'application/pdf') return;
    $parser = new \setasign\Fpdi\Tcpdf\Fpdi();
    $reader = null;
    try {
        $handle = openPersistentFile($receipt, true);
        fclose($handle);
        $key = $receipt['storage_key'];
        try {
            $parser->setSourceFile(persistentFilePath($key));
        } catch (CrossReferenceException $error) {
            if ($error->getCode() !== CrossReferenceException::COMPRESSED_XREF) throw $error;
            $reader = reimbursementCompatiblePdf(persistentFilePath($key));
            $pages = $parser->setSourceFile($reader);
            for ($page = 1; $page <= $pages; $page++) $parser->importPage($page);
            $stream = $reader->getStream();
            rewind($stream);
            $bytes = stream_get_contents($stream);
            if ($bytes === false) throw new RuntimeException('Unable to read compatible receipt.');
            $key = storePersistentFile($conn, $bytes, 'receipt-report-' . $receipt['checksum'] . '.pdf', 'application/pdf');
        }
        $conn->execute_query("UPDATE reimbursement_receipts SET report_key=?,report_status='ready' WHERE id=? AND storage_key=?",
            [$key, $receipt['id'], $receipt['storage_key']]);
    } catch (Throwable $error) {
        $conn->execute_query("UPDATE reimbursement_receipts SET report_status='failed' WHERE id=? AND storage_key=?",
            [$receipt['id'], $receipt['storage_key']]);
        applicationLog('warning', 'Receipt PDF preparation failed', ['receipt_id' => (int) $receipt['id'], 'type' => get_class($error)]);
    } finally {
        $parser->cleanUp(true);
        if ($reader !== null) $reader->cleanUp();
    }
}

function reimbursementPdfReceiptPageCount(DnrEngagementPdf $pdf, array $receipt): int
{
    try {
        $source = persistentFilePath($receipt['storage_key']);
        try {
            return $pdf->setSourceFile($source);
        } catch (CrossReferenceException $error) {
            if ($error->getCode() !== CrossReferenceException::COMPRESSED_XREF) throw $error;
            if (empty($receipt['report_key'])) {
                if (($receipt['report_status'] ?? 'pending') === 'pending') {
                    throw new ReimbursementReceiptPdfException('Receipt ' . reimbursementReceiptPackageFilename($receipt)
                        . ' is still being prepared for the report. Try downloading again shortly.');
                }
                throw $error;
            }
            $handle = openPersistentFile(['storage_key' => $receipt['report_key'],
                'size' => $receipt['report_size'], 'checksum' => $receipt['report_checksum']], true);
            fclose($handle);
            return $pdf->setSourceFile(persistentFilePath($receipt['report_key']));
        }
    } catch (ReimbursementReceiptPdfException $error) {
        throw $error;
    } catch (Throwable $error) {
        $instruction = $error instanceof CrossReferenceException && $error->getCode() === CrossReferenceException::ENCRYPTED
            ? 'Upload an unprotected PDF copy.'
            : 'Save a new PDF copy of this receipt and replace the attachment, then try again.';
        throw new ReimbursementReceiptPdfException('Receipt ' . reimbursementReceiptPackageFilename($receipt)
            . ' could not be included in the report. ' . $instruction, 0, $error);
    }
}
