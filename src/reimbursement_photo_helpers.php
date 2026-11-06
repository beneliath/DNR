<?php

declare(strict_types=1);

function reimbursementIsPhoto(array $receipt): bool
{
    return in_array($receipt['content_type'], ['image/jpeg', 'image/png', 'image/webp'], true);
}

/** Read only the bounded TIFF orientation entry; the application image does not require ext-exif. */
function reimbursementJpegOrientation(string $app1): int
{
    if (!str_starts_with($app1, "Exif\0\0")) return 1;
    $tiff = substr($app1, 6);
    $order = substr($tiff, 0, 2);
    if (!in_array($order, ['II', 'MM'], true) || strlen($tiff) < 8) return 1;
    $short = $order === 'II' ? 'v' : 'n';
    $long = $order === 'II' ? 'V' : 'N';
    if (unpack($short, substr($tiff, 2, 2))[1] !== 42) return 1;
    $offset = unpack($long, substr($tiff, 4, 4))[1];
    if ($offset < 8 || $offset > strlen($tiff) - 2) return 1;
    $count = unpack($short, substr($tiff, $offset, 2))[1];
    for ($i = 0; $i < $count; $i++) {
        $entry = $offset + 2 + 12 * $i;
        if ($entry > strlen($tiff) - 12) return 1;
        if (unpack($short, substr($tiff, $entry, 2))[1] !== 0x0112) continue;
        if (unpack($short, substr($tiff, $entry + 2, 2))[1] !== 3
            || unpack($long, substr($tiff, $entry + 4, 4))[1] !== 1) return 1;
        $value = unpack($short, substr($tiff, $entry + 8, 2))[1];
        return $value >= 1 && $value <= 8 ? $value : 1;
    }
    return 1;
}

/** Smaller, temporary email copies only. Original stored files and receipt rows never change. */
function reimbursementPreparePhotoCopies(array $context): array
{
    if (empty($context['reduce_photos']) || !empty($context['photos_prepared'])) return $context;
    reimbursementReceiptsReady($context['receipts'], false);
    $photos = array_filter($context['receipts'], 'reimbursementIsPhoto');
    $otherBytes = array_sum(array_map(static fn($r) => reimbursementIsPhoto($r) ? 0 : (int) $r['size'], $context['receipts']));
    // Reserve space for the report/CSV and a second copy of each image inside the PDF.
    $target = max(80_000, (int) ((REIMBURSEMENT_MAX_PACKAGE_BYTES - $otherBytes - 1_500_000) / max(1, count($photos) * 2)));
    try {
        foreach ($context['receipts'] as &$receipt) {
            if (!reimbursementIsPhoto($receipt) || (int) $receipt['size'] <= 100_000) continue;
            $handle = openPersistentFile($receipt, true);
            fclose($handle);
            $sourcePath = persistentFilePath($receipt['storage_key']);
            $info = [];
            $dimensions = @getimagesize($sourcePath, $info);
            if (!$dimensions || $dimensions[0] * $dimensions[1] > 24_000_000) {
                throw new InvalidArgumentException('A receipt photo cannot be reduced safely. Replace it with a JPEG, PNG, or WebP image of 24 megapixels or less.');
            }
            $source = match ($receipt['content_type']) {
                'image/jpeg' => @imagecreatefromjpeg($sourcePath),
                'image/png' => @imagecreatefrompng($sourcePath),
                'image/webp' => @imagecreatefromwebp($sourcePath),
            };
            if (!$source) throw new InvalidArgumentException('A receipt photo could not be read. Replace that photo and try again.');
            $orientation = reimbursementJpegOrientation($info['APP1'] ?? '');
            $path = tempnam(sys_get_temp_dir(), 'dnr-receipt-email-');
            if ($path === false) throw new RuntimeException('Unable to prepare smaller receipt photos.');
            // Also clean up on an aborted request; no temporary paths enter the saved submission.
            register_shutdown_function(static function () use ($path): void { if (is_file($path)) @unlink($path); });
            $receipt['package_path'] = $path;
            foreach ([[2400, 85], [2000, 82], [1600, 80], [1200, 78]] as [$edge, $quality]) {
                $ratio = min(1, $edge / max($dimensions[0], $dimensions[1]));
                $scaled = imagecreatetruecolor(max(1, (int) round($dimensions[0] * $ratio)), max(1, (int) round($dimensions[1] * $ratio)));
                imagefill($scaled, 0, 0, imagecolorallocate($scaled, 255, 255, 255));
                imagecopyresampled($scaled, $source, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), $dimensions[0], $dimensions[1]);
                if (in_array($orientation, [2, 5, 7], true)) imageflip($scaled, IMG_FLIP_HORIZONTAL);
                if ($orientation === 4) imageflip($scaled, IMG_FLIP_VERTICAL);
                $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
                if ($angle !== 0) {
                    $rotated = imagerotate($scaled, $angle, 0);
                    if (!$rotated) throw new RuntimeException('Unable to orient receipt photo.');
                    $scaled = $rotated;
                    unset($rotated);
                }
                if (!imagejpeg($scaled, $path, $quality)) throw new RuntimeException('Unable to save receipt photo.');
                clearstatcache(true, $path);
                $size = filesize($path);
                if (!$size) throw new RuntimeException('Unable to save receipt photo.');
                $width = imagesx($scaled); $height = imagesy($scaled);
                unset($scaled);
                if ($size <= $target) break;
            }
            unset($source);
            if ($size >= (int) $receipt['size']) {
                unlink($path);
                unset($receipt['package_path']);
                continue;
            }
            $receipt['package_size'] = $size;
            $receipt['package_content_type'] = 'image/jpeg';
            $receipt['package_width'] = $width;
            $receipt['package_height'] = $height;
            $receipt['package_checksum'] = hash_file('sha256', $path);
        }
        unset($receipt);
        $context['photos_prepared'] = true;
        return $context;
    } catch (Throwable $error) {
        unset($receipt);
        reimbursementCleanupPhotoCopies($context);
        throw $error;
    }
}

function reimbursementCleanupPhotoCopies(array $context): void
{
    foreach ($context['receipts'] as $receipt) {
        if (isset($receipt['package_path']) && is_file($receipt['package_path'])) @unlink($receipt['package_path']);
    }
}

function reimbursementPackageReceiptDescription(array $context): string
{
    return array_filter($context['receipts'], static fn($r) => isset($r['package_size']))
        ? 'receipt files with reduced photo copies; originals are retained in MOED'
        : 'all original receipts';
}
