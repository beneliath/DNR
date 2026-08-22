<?php

declare(strict_types=1);

require_once __DIR__ . '/image_upload_helpers.php';

const CONTACT_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const CONTACT_PHOTO_MAX_PIXELS = 16000000;
const CONTACT_PHOTO_MAX_DIMENSION = 1024;

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
    return normalizedUploadedImage(
        $path,
        CONTACT_PHOTO_MAX_BYTES,
        CONTACT_PHOTO_MAX_PIXELS,
        CONTACT_PHOTO_MAX_DIMENSION,
        'contact photo'
    );
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
