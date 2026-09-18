<?php

declare(strict_types=1);

require_once __DIR__ . '/persistent_file_helpers.php';

// Every stored portrait is displayed as a circle. Keep a 2x-density list
// variant for the largest 52px avatar and a 2x-density detail variant for the
// largest 152px profile/contact/speaker portrait, with a little rounding room.
const UPLOADED_IMAGE_DETAIL_DIMENSION = 320;
const UPLOADED_IMAGE_THUMBNAIL_DIMENSION = 112;
const UPLOADED_IMAGE_THUMBNAIL_MAX_BYTES = 60000;

function encodeUploadedGdImage(GdImage $image, string $mime_type, int $quality): bool
{
    if ($mime_type === 'image/jpeg') {
        return imagejpeg($image, null, $quality);
    }
    if ($mime_type === 'image/png') {
        return imagepng($image, null, max(0, min(9, $quality)));
    }
    if ($mime_type === 'image/webp' && function_exists('imagewebp')) {
        return imagewebp($image, null, $quality);
    }
    return false;
}

function uploadedImageDataUrl($mime_type, $data) {
    if (!is_string($data) || $data === '') {
        return '';
    }
    return 'data:' . (string) $mime_type . ';base64,' . base64_encode($data);
}

function normalizedUploadedImage(
    $path,
    $maximum_bytes,
    $maximum_pixels,
    $maximum_dimension,
    $label
) {
    if (!is_string($path) || $path === '' || !is_file($path)) {
        throw new InvalidArgumentException("Choose a {$label} to upload.");
    }

    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new InvalidArgumentException("The selected {$label} is empty.");
    }
    if ($size > $maximum_bytes) {
        throw new InvalidArgumentException(ucfirst($label) . 's must be 5 MB or smaller.');
    }

    $contents = file_get_contents($path);
    if ($contents === false || strlen($contents) !== $size) {
        throw new RuntimeException("The {$label} could not be read.");
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = (string) $finfo->file($path);
    if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new InvalidArgumentException('Upload a JPEG, PNG, or WebP ' . $label . '.');
    }

    $dimensions = @getimagesizefromstring($contents);
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    if ($width < 1 || $height < 1 || (string) ($dimensions['mime'] ?? '') !== $mime_type) {
        throw new InvalidArgumentException('The selected file is not a valid image.');
    }
    if ($width * $height > $maximum_pixels) {
        throw new InvalidArgumentException('The selected ' . $label . ' has dimensions that are too large.');
    }
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Image processing is unavailable. Rebuild the application container.');
    }

    $source = @imagecreatefromstring($contents);
    if (!$source instanceof GdImage) {
        throw new InvalidArgumentException('The selected file could not be decoded as an image.');
    }
    // The UI always renders these images as square, object-fit portraits.
    // Cropping once at save time avoids transferring pixels the browser will
    // discard and guarantees equal detail in both axes at the rendered size.
    $source_dimension = min($width, $height);
    $source_x = intdiv($width - $source_dimension, 2);
    $source_y = intdiv($height - $source_dimension, 2);
    $target_dimension = min((int) $maximum_dimension, $source_dimension);
    $target_width = max(1, $target_dimension);
    $target_height = $target_width;
    $target = imagecreatetruecolor($target_width, $target_height);
    if (!$target instanceof GdImage) {
        throw new RuntimeException('The image could not be resized.');
    }
    if (in_array($mime_type, ['image/png', 'image/webp'], true)) {
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $target_width, $target_height, $transparent);
    }
    imagecopyresampled(
        $target,
        $source,
        0,
        0,
        $source_x,
        $source_y,
        $target_width,
        $target_height,
        $source_dimension,
        $source_dimension
    );

    // WebP materially reduces photo payloads while retaining alpha support.
    // Fall back to the validated source format only when WebP is unavailable.
    $normalized_mime = function_exists('imagewebp') ? 'image/webp' : $mime_type;
    ob_start();
    $encoded = encodeUploadedGdImage(
        $target,
        $normalized_mime,
        $normalized_mime === 'image/png' ? 6 : 85
    );
    $normalized = ob_get_clean();
    if (!$encoded || !is_string($normalized) || $normalized === '') {
        throw new RuntimeException('The image could not be safely re-encoded.');
    }
    if (strlen($normalized) > $maximum_bytes) {
        throw new InvalidArgumentException('The processed ' . $label . ' is still too large.');
    }

    $thumbnail_scale = min(
        1,
        UPLOADED_IMAGE_THUMBNAIL_DIMENSION / max($target_width, $target_height)
    );
    $thumbnail_width = max(1, (int) round($target_width * $thumbnail_scale));
    $thumbnail_height = max(1, (int) round($target_height * $thumbnail_scale));
    $thumbnail = imagecreatetruecolor($thumbnail_width, $thumbnail_height);
    if (!$thumbnail instanceof GdImage) {
        throw new RuntimeException('The image thumbnail could not be created.');
    }
    imagealphablending($thumbnail, false);
    imagesavealpha($thumbnail, true);
    $thumbnail_transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
    imagefilledrectangle(
        $thumbnail,
        0,
        0,
        $thumbnail_width,
        $thumbnail_height,
        $thumbnail_transparent
    );
    imagecopyresampled(
        $thumbnail,
        $target,
        0,
        0,
        0,
        0,
        $thumbnail_width,
        $thumbnail_height,
        $target_width,
        $target_height
    );
    $thumbnail_mime = function_exists('imagewebp') ? 'image/webp' : $mime_type;
    ob_start();
    $thumbnail_encoded = encodeUploadedGdImage(
        $thumbnail,
        $thumbnail_mime,
        $thumbnail_mime === 'image/png' ? 6 : 82
    );
    $thumbnail_data = ob_get_clean();
    if (!$thumbnail_encoded || !is_string($thumbnail_data) || $thumbnail_data === '') {
        throw new RuntimeException('The image thumbnail could not be encoded.');
    }
    if (strlen($thumbnail_data) > UPLOADED_IMAGE_THUMBNAIL_MAX_BYTES) {
        // Keep thumbnails within MySQL BLOB limits and keep list-page payloads
        // bounded even for high-entropy images that compress poorly.
        $fallback_scale = min(1, 112 / max($thumbnail_width, $thumbnail_height));
        $fallback_width = max(1, (int) round($thumbnail_width * $fallback_scale));
        $fallback_height = max(1, (int) round($thumbnail_height * $fallback_scale));
        $fallback = imagecreatetruecolor($fallback_width, $fallback_height);
        if (!$fallback instanceof GdImage) {
            throw new RuntimeException('The image thumbnail could not be compacted.');
        }
        imagealphablending($fallback, false);
        imagesavealpha($fallback, true);
        $fallback_transparent = imagecolorallocatealpha($fallback, 0, 0, 0, 127);
        imagefilledrectangle(
            $fallback,
            0,
            0,
            $fallback_width,
            $fallback_height,
            $fallback_transparent
        );
        imagecopyresampled(
            $fallback,
            $thumbnail,
            0,
            0,
            0,
            0,
            $fallback_width,
            $fallback_height,
            $thumbnail_width,
            $thumbnail_height
        );
        ob_start();
        $fallback_encoded = encodeUploadedGdImage(
            $fallback,
            $thumbnail_mime,
            $thumbnail_mime === 'image/png' ? 7 : 70
        );
        $thumbnail_data = ob_get_clean();
        if (!$fallback_encoded
            || !is_string($thumbnail_data)
            || $thumbnail_data === ''
            || strlen($thumbnail_data) > UPLOADED_IMAGE_THUMBNAIL_MAX_BYTES
        ) {
            throw new RuntimeException('The image thumbnail could not be compacted.');
        }
    }

    return [
        'mime_type' => $normalized_mime,
        'data' => $normalized,
        'sha256' => hash('sha256', $normalized, true),
        'size' => strlen($normalized),
        'width' => $target_width,
        'height' => $target_height,
        'thumbnail_mime_type' => $thumbnail_mime,
        'thumbnail_data' => $thumbnail_data,
        'thumbnail_sha256' => hash('sha256', $thumbnail_data, true),
    ];
}
