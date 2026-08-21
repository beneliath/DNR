<?php

require_once __DIR__ . '/image_upload_helpers.php';

const PROFILE_PICTURE_MAX_BYTES = 5 * 1024 * 1024;
const PROFILE_PICTURE_MAX_PIXELS = 16000000;
const PROFILE_PICTURE_MAX_DIMENSION = 1024;

function profileDisplayName(array $user) {
    $name = trim(implode(' ', array_filter([
        trim((string) ($user['first_name'] ?? '')),
        trim((string) ($user['last_name'] ?? '')),
    ], static fn($part) => $part !== '')));

    return $name !== '' ? $name : (string) ($user['username'] ?? 'Account');
}

function profileInitials(array $user) {
    $parts = array_values(array_filter([
        trim((string) ($user['first_name'] ?? '')),
        trim((string) ($user['last_name'] ?? '')),
    ], static fn($part) => $part !== ''));

    if (!$parts) {
        $parts = [trim((string) ($user['username'] ?? 'A'))];
    }

    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if (preg_match('/\A./us', $part, $matches) === 1) {
            $initials .= $matches[0];
        }
    }

    return strtoupper($initials !== '' ? $initials : 'A');
}

function validatedProfilePictureFile($path) {
    return normalizedUploadedImage(
        $path,
        PROFILE_PICTURE_MAX_BYTES,
        PROFILE_PICTURE_MAX_PIXELS,
        PROFILE_PICTURE_MAX_DIMENSION,
        'profile picture'
    );
}

function profilePictureFromUpload(array $upload) {
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Profile pictures must be 5 MB or smaller.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The profile picture upload did not complete. Try again.');
    }

    $temporary_path = (string) ($upload['tmp_name'] ?? '');
    if ($temporary_path === '' || !is_uploaded_file($temporary_path)) {
        throw new InvalidArgumentException('The profile picture upload was not accepted.');
    }

    return validatedProfilePictureFile($temporary_path);
}
