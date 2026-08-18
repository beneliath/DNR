<?php

const PROFILE_PICTURE_MAX_BYTES = 5 * 1024 * 1024;
const PROFILE_PICTURE_MAX_PIXELS = 40000000;

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
    if (!is_string($path) || $path === '' || !is_file($path)) {
        throw new InvalidArgumentException('Choose a profile picture to upload.');
    }

    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new InvalidArgumentException('The selected profile picture is empty.');
    }
    if ($size > PROFILE_PICTURE_MAX_BYTES) {
        throw new InvalidArgumentException('Profile pictures must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = (string) $finfo->file($path);
    $allowed_mime_types = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime_type, $allowed_mime_types, true)) {
        throw new InvalidArgumentException('Upload a JPEG, PNG, or WebP profile picture.');
    }

    $dimensions = @getimagesize($path);
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    $detected_mime_type = (string) ($dimensions['mime'] ?? '');
    if ($width < 1 || $height < 1 || $detected_mime_type !== $mime_type) {
        throw new InvalidArgumentException('The selected file is not a valid image.');
    }
    if ($width * $height > PROFILE_PICTURE_MAX_PIXELS) {
        throw new InvalidArgumentException('The selected profile picture has dimensions that are too large.');
    }

    $contents = file_get_contents($path);
    if ($contents === false || strlen($contents) !== $size) {
        throw new RuntimeException('The profile picture could not be read.');
    }

    return [
        'mime_type' => $mime_type,
        'data' => $contents,
        'size' => $size,
        'width' => $width,
        'height' => $height,
    ];
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
