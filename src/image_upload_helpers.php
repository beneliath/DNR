<?php

const UPLOADED_IMAGE_THUMBNAIL_DIMENSION = 256;
const UPLOADED_IMAGE_THUMBNAIL_MAX_BYTES = 60000;

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
    $scale = min(1, $maximum_dimension / max($width, $height));
    $target_width = max(1, (int) round($width * $scale));
    $target_height = max(1, (int) round($height * $scale));
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
        0,
        0,
        $target_width,
        $target_height,
        $width,
        $height
    );

    ob_start();
    $encoded = match ($mime_type) {
        'image/jpeg' => imagejpeg($target, null, 85),
        'image/png' => imagepng($target, null, 6),
        'image/webp' => function_exists('imagewebp') && imagewebp($target, null, 85),
        default => false,
    };
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
    $thumbnail_encoded = match ($thumbnail_mime) {
        'image/webp' => imagewebp($thumbnail, null, 82),
        'image/jpeg' => imagejpeg($thumbnail, null, 82),
        'image/png' => imagepng($thumbnail, null, 6),
        default => false,
    };
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
        $fallback_encoded = match ($thumbnail_mime) {
            'image/webp' => imagewebp($fallback, null, 70),
            'image/jpeg' => imagejpeg($fallback, null, 70),
            'image/png' => imagepng($fallback, null, 7),
            default => false,
        };
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
        'mime_type' => $mime_type,
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
