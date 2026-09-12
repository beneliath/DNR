<?php

declare(strict_types=1);

require_once __DIR__ . '/contact_photo_helpers.php';
require_once __DIR__ . '/functions.php';

const SPEAKER_PHOTO_MAX_BYTES = CONTACT_PHOTO_MAX_BYTES;
const SPEAKER_URL_MAX_LENGTH = 2048;
const SPEAKER_CUSTOM_LINK_MAX_COUNT = 50;
const SPEAKER_URL_FIELDS = [
    'website_url' => 'Website URL',
    'bio_url' => 'Bio URL',
    'donation_url' => 'Donate URL',
    'connection_url' => 'Connection URL',
    'blog_url' => 'Blog URL',
    'books_url' => 'Books URL',
];

function speakerPhotoIdentity(array $speaker): array
{
    $parts = preg_split('/\s+/u', trim((string) ($speaker['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return ['contact_first_name' => $parts[0] ?? 'S',
        'contact_last_name' => count($parts) > 1 ? $parts[count($parts) - 1] : ''];
}

function speakerInitials(array $speaker): string
{
    return contactInitials(speakerPhotoIdentity($speaker));
}

function speakerInitialsSvg(array $speaker): string
{
    return contactInitialsSvg(speakerPhotoIdentity($speaker));
}

function speakerPhotoFromUpload(array $upload): ?array
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('Speaker photos must be 5 MB or smaller.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The speaker photo upload did not complete. Try again.');
    }
    $path = $upload['tmp_name'] ?? null;
    if (!is_string($path) || $path === '' || !is_uploaded_file($path)) {
        throw new InvalidArgumentException('The speaker photo upload was not accepted.');
    }
    return normalizedUploadedImage($path, SPEAKER_PHOTO_MAX_BYTES,
        CONTACT_PHOTO_MAX_PIXELS, CONTACT_PHOTO_MAX_DIMENSION, 'speaker photo');
}

/** @return array<int, array<string, mixed>> */
function fetchSpeakerOptions(mysqli $conn): array
{
    $result = $conn->query('SELECT id, name, email FROM speakers ORDER BY name, id');
    if (!$result) {
        throw new RuntimeException('Unable to load speakers.');
    }
    $speakers = [];
    while ($speaker = $result->fetch_assoc()) {
        $speakers[(int) $speaker['id']] = $speaker;
    }
    return $speakers;
}

function defaultSpeakerId(array $speakers, string $preferredName = ''): int
{
    foreach ($speakers as $speaker) {
        if ($preferredName !== '' && $speaker['name'] === $preferredName) {
            return (int) $speaker['id'];
        }
    }
    if (!$speakers) {
        throw new RuntimeException('Add a speaker before creating presentations.');
    }
    // The first-created speaker remains the fallback even after a name change.
    return (int) min(array_keys($speakers));
}

function fetchSpeaker(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT id, name, email, phone, bio, version, photo_mime, photo_updated_at, custom_links, '
        . implode(', ', array_keys(SPEAKER_URL_FIELDS)) . ' FROM speakers WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to load the speaker.');
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $speaker = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($speaker) $speaker['custom_links'] = speakerCustomLinks($speaker);
    return $speaker ?: null;
}

/** @return list<array{key: string, label: string, url: string}> */
function speakerCustomLinks(array $speaker): array
{
    $links = $speaker['custom_links'] ?? [];
    return is_string($links) ? json_decode($links, true, 512, JSON_THROW_ON_ERROR) : $links;
}

/** @return list<array{key: string, label: string, url: string}> */
function normalizeSpeakerCustomLinks(mixed $input, array $existing = []): array
{
    if (!is_array($input) || count($input) > SPEAKER_CUSTOM_LINK_MAX_COUNT) {
        throw new InvalidArgumentException('Add up to ' . SPEAKER_CUSTOM_LINK_MAX_COUNT . ' custom links.');
    }
    $knownKeys = array_column($existing, 'key');
    $links = [];
    $seen = [];
    foreach ($input as $row) {
        if (!is_array($row) || !is_string($row['label'] ?? null) || !is_string($row['url'] ?? null)
            || !is_string($row['key'] ?? '')) {
            throw new InvalidArgumentException('Enter a name and URL for each custom link.');
        }
        $key = $row['key'] ?? '';
        $label = trim($row['label']);
        $url = trim($row['url']);
        if ($key === '' && $label === '' && $url === '') continue;
        if ($label === '' || mb_strlen($label, 'UTF-8') > 255 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
            throw new InvalidArgumentException('Custom link names must contain 1 to 255 characters on a single line.');
        }
        require_once __DIR__ . '/short_link_helpers.php';
        $url = shortLinkTarget($url);
        if ($key !== '' && (!in_array($key, $knownKeys, true) || isset($seen[$key]))) {
            throw new InvalidArgumentException('A custom link changed in another session. Reload the page before saving.');
        }
        $key = $key === '' ? bin2hex(random_bytes(8)) : $key;
        $seen[$key] = true;
        $links[] = ['key' => $key, 'label' => $label, 'url' => $url];
    }
    return $links;
}

/** @return array<string, string> */
function normalizeSpeakerInput(array $input): array
{
    $data = [];
    foreach (['name', 'email', 'phone'] as $field) {
        if (!is_string($input[$field] ?? null)) {
            throw new InvalidArgumentException('Enter the speaker name, email address, and telephone number.');
        }
        $data[$field] = trim($input[$field]);
        if ($data[$field] === '' || preg_match('/[\x00-\x1F\x7F]/', $data[$field])) {
            throw new InvalidArgumentException('Enter the speaker name, email address, and telephone number.');
        }
    }
    if (mb_strlen($data['name'], 'UTF-8') > 255) {
        throw new InvalidArgumentException('Speaker name must be 255 characters or fewer.');
    }
    if (strlen($data['email']) > 254 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid speaker email address.');
    }
    $data['bio'] = \Dnr\Domain\InputText::value($input, 'bio');
    $bio_error = \Dnr\Domain\InputText::textStorageError($data['bio'], 'Speaker bio');
    if ($bio_error !== null) {
        throw new InvalidArgumentException($bio_error);
    }
    foreach (SPEAKER_URL_FIELDS as $field => $label) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('Enter a valid ' . $label . ' beginning with https:// or http://.');
        }
        $url = normalizedHttpUrl($value);
        if ($url === null) {
            throw new InvalidArgumentException('Enter a valid ' . $label . ' beginning with https:// or http://.');
        }
        if (strlen($url) > SPEAKER_URL_MAX_LENGTH) {
            throw new InvalidArgumentException($label . ' must be ' . SPEAKER_URL_MAX_LENGTH . ' characters or fewer.');
        }
        $data[$field] = $url;
    }
    if (array_key_exists('phone_country_code', $input)) {
        $data['phone'] = normalizePhoneNumber($input['phone_country_code'], $data['phone'], 'Phone number');
    }
    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
    try {
        $phone = $phoneUtil->parse($data['phone'], null);
        if (!str_starts_with($data['phone'], '+') || !$phoneUtil->isValidNumber($phone) || $phone->hasExtension()) {
            throw new InvalidArgumentException('Enter a valid telephone number including its country code, such as +1 949 400 2892.');
        }
        $data['phone'] = $phoneUtil->format($phone, \libphonenumber\PhoneNumberFormat::E164);
    } catch (\libphonenumber\NumberParseException $exception) {
        throw new InvalidArgumentException('Enter a valid telephone number including its country code, such as +1 949 400 2892.');
    }
    return $data;
}

function saveSpeaker(mysqli $conn, array $input, ?int $id = null, ?int $version = null, ?array $photo = null, bool $removePhoto = false): int
{
    $speaker = normalizeSpeakerInput($input);
    if ($photo !== null && $removePhoto) {
        throw new InvalidArgumentException('Choose a new photo or remove the current photo, not both.');
    }
    $columns = ['name', 'email', 'phone', 'bio'];
    $values = [$speaker['name'], $speaker['email'], $speaker['phone'], $speaker['bio']];
    $types = 'ssss';
    if (array_key_exists('custom_links', $input)) {
        $existing = $id === null ? [] : speakerCustomLinks(fetchSpeaker($conn, $id) ?? []);
        $columns[] = 'custom_links';
        $values[] = json_encode(normalizeSpeakerCustomLinks($input['custom_links'], $existing), JSON_THROW_ON_ERROR);
        $types .= 's';
    }
    foreach (SPEAKER_URL_FIELDS as $field => $label) {
        $columns[] = $field;
        $values[] = $speaker[$field];
        $types .= 's';
    }
    if ($photo !== null || $removePhoto) {
        $columns = array_merge($columns, ['photo', 'photo_thumbnail', 'photo_mime', 'photo_thumbnail_mime', 'photo_sha256', 'photo_updated_at']);
        $values = array_merge($values, [$photo['data'] ?? null, $photo['thumbnail_data'] ?? null,
            $photo['mime_type'] ?? null, $photo['thumbnail_mime_type'] ?? null, $photo['sha256'] ?? null,
            gmdate('Y-m-d H:i:s')]);
        $types .= 'ssssss';
    }
    if ($id === null) {
        $sql = 'INSERT INTO speakers (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
    } else {
        $sql = 'UPDATE speakers SET ' . implode(', ', array_map(static fn(string $column): string => $column . ' = ?', $columns))
            . ', version = version + 1 WHERE id = ? AND version = ?';
        $values[] = $id;
        $values[] = $version;
        $types .= 'ii';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to save the speaker.');
    }
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save the speaker.');
    }
    $changed = $stmt->affected_rows;
    $savedId = $id ?? (int) $conn->insert_id;
    $stmt->close();
    if ($changed !== 1) {
        throw new InvalidArgumentException('This speaker changed in another session. Reload the page and review the latest details before saving.');
    }
    return $savedId;
}
