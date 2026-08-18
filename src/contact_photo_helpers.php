<?php

const CONTACT_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const CONTACT_PHOTO_MAX_PIXELS = 40000000;

function contactInitials(array $contact) {
    $parts = array_values(array_filter([
        trim((string) ($contact['contact_first_name'] ?? '')),
        trim((string) ($contact['contact_last_name'] ?? '')),
    ], static fn($part) => $part !== ''));

    if (!$parts) {
        return 'C';
    }

    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if (preg_match('/\A./us', $part, $matches) === 1) {
            $initials .= $matches[0];
        }
    }

    return strtoupper($initials !== '' ? $initials : 'C');
}

function contactInitialsSvg(array $contact) {
    $initials = htmlspecialchars(contactInitials($contact), ENT_QUOTES | ENT_XML1, 'UTF-8');

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" role="img">'
        . '<rect width="128" height="128" rx="64" fill="#1f57e7"/>'
        . '<text x="64" y="68" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="46" font-weight="700" text-anchor="middle" dominant-baseline="middle">'
        . $initials
        . '</text></svg>';
}

function validatedContactPhotoFile($path) {
    if (!is_string($path) || $path === '' || !is_file($path)) {
        throw new InvalidArgumentException('Choose a contact photo to upload.');
    }

    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new InvalidArgumentException('The selected contact photo is empty.');
    }
    if ($size > CONTACT_PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('Contact photos must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = (string) $finfo->file($path);
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime_type, $allowed_mime_types, true)) {
        throw new InvalidArgumentException('Upload a JPEG, PNG, or WebP contact photo.');
    }

    $dimensions = @getimagesize($path);
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    $detected_mime_type = (string) ($dimensions['mime'] ?? '');
    if ($width < 1 || $height < 1 || $detected_mime_type !== $mime_type) {
        throw new InvalidArgumentException('The selected file is not a valid image.');
    }
    if ($width * $height > CONTACT_PHOTO_MAX_PIXELS) {
        throw new InvalidArgumentException('The selected contact photo has dimensions that are too large.');
    }

    $contents = file_get_contents($path);
    if ($contents === false || strlen($contents) !== $size) {
        throw new RuntimeException('The contact photo could not be read.');
    }

    return [
        'mime_type' => $mime_type,
        'data' => $contents,
        'size' => $size,
        'width' => $width,
        'height' => $height,
    ];
}

function contactPhotoFromUpload(array $upload) {
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Contact photos must be 5 MB or smaller.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The contact photo upload did not complete. Try again.');
    }

    $temporary_path = (string) ($upload['tmp_name'] ?? '');
    if ($temporary_path === '' || !is_uploaded_file($temporary_path)) {
        throw new InvalidArgumentException('The contact photo upload was not accepted.');
    }

    return validatedContactPhotoFile($temporary_path);
}
