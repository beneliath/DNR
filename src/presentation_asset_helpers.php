<?php

declare(strict_types=1);

require_once __DIR__ . '/image_upload_helpers.php';

const PRESENTATION_SLIDE_DECK_MAX_BYTES = 100 * 1024 * 1024;
const PRESENTATION_QR_MAX_BYTES = 5 * 1024 * 1024;
const PRESENTATION_QR_MAX_PIXELS = 16000000;
const PRESENTATION_QR_MAX_DIMENSION = 1600;

/**
 * Parse one RFC 7233 byte range. Multiple ranges are intentionally rejected
 * because these authenticated assets are served directly by PHP.
 *
 * @return array{start: int, end: int, length: int}|null
 */
function presentationAssetByteRange(string $header, int $size): ?array
{
    $header = trim($header);
    if ($header === '') {
        return null;
    }
    if ($size < 1 || preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $matches) !== 1) {
        throw new OutOfRangeException('The requested byte range is not satisfiable.');
    }

    $start_text = $matches[1];
    $end_text = $matches[2];
    if ($start_text === '' && $end_text === '') {
        throw new OutOfRangeException('The requested byte range is not satisfiable.');
    }
    if ($start_text === '') {
        $suffix_length = (int) $end_text;
        if ($suffix_length < 1) {
            throw new OutOfRangeException('The requested byte range is not satisfiable.');
        }
        $start = max(0, $size - $suffix_length);
        $end = $size - 1;
    } else {
        $start = (int) $start_text;
        $end = $end_text === '' ? $size - 1 : min((int) $end_text, $size - 1);
        if ($start >= $size || $end < $start) {
            throw new OutOfRangeException('The requested byte range is not satisfiable.');
        }
    }

    return ['start' => $start, 'end' => $end, 'length' => $end - $start + 1];
}

function presentationAssetDefinitions(): array
{
    return [
        'slide_deck' => [
            'kind' => 'pdf',
            'label' => 'PDF slide deck',
            'query_type' => 'slides',
            'data_column' => 'slide_deck_pdf',
            'mime_column' => null,
            'filename_column' => 'slide_deck_filename',
            'size_column' => 'slide_deck_size',
            'sha_column' => 'slide_deck_sha256',
            'updated_column' => 'slide_deck_updated_at',
        ],
        'speaker_notes_qr' => [
            'kind' => 'image',
            'label' => 'speaker notes QR code',
            'query_type' => 'notes_qr',
            'data_column' => 'speaker_notes_qr_image',
            'mime_column' => 'speaker_notes_qr_mime',
            'filename_column' => null,
            'size_column' => null,
            'sha_column' => 'speaker_notes_qr_sha256',
            'updated_column' => 'speaker_notes_qr_updated_at',
        ],
        'speaker_website_qr' => [
            'kind' => 'image',
            'label' => 'speaker website QR code',
            'query_type' => 'website_qr',
            'data_column' => 'speaker_website_qr_image',
            'mime_column' => 'speaker_website_qr_mime',
            'filename_column' => null,
            'size_column' => null,
            'sha_column' => 'speaker_website_qr_sha256',
            'updated_column' => 'speaker_website_qr_updated_at',
        ],
        'speaker_donation_qr' => [
            'kind' => 'image',
            'label' => 'speaker donation QR code',
            'query_type' => 'donation_qr',
            'data_column' => 'speaker_donation_qr_image',
            'mime_column' => 'speaker_donation_qr_mime',
            'filename_column' => null,
            'size_column' => null,
            'sha_column' => 'speaker_donation_qr_sha256',
            'updated_column' => 'speaker_donation_qr_updated_at',
        ],
    ];
}

function presentationAssetDefinitionForQueryType(string $query_type): ?array
{
    foreach (presentationAssetDefinitions() as $form_key => $definition) {
        if ($definition['query_type'] === $query_type) {
            $definition['form_key'] = $form_key;
            return $definition;
        }
    }
    return null;
}

function presentationUploadEntry(array $files, $row_key, string $asset_key): ?array
{
    $entry = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $property) {
        if (!isset($files[$property]) || !is_array($files[$property])) {
            return null;
        }
        $row = $files[$property][$row_key] ?? null;
        if (!is_array($row) || !array_key_exists($asset_key, $row) || !is_scalar($row[$asset_key])) {
            return null;
        }
        $entry[$property] = $row[$asset_key];
    }

    $entry['name'] = (string) $entry['name'];
    $entry['type'] = (string) $entry['type'];
    $entry['tmp_name'] = (string) $entry['tmp_name'];
    $entry['error'] = (int) $entry['error'];
    $entry['size'] = (int) $entry['size'];
    return $entry;
}

function presentationAssetUploadMap(array $files): array
{
    $uploads = [];
    $names = $files['name'] ?? null;
    if (!is_array($names)) {
        return $uploads;
    }

    $definitions = presentationAssetDefinitions();
    foreach (array_keys($names) as $row_key) {
        foreach ($definitions as $asset_key => $_definition) {
            $upload = presentationUploadEntry($files, $row_key, $asset_key);
            if ($upload === null || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploads[(string) $row_key][$asset_key] = $upload;
        }
    }
    return $uploads;
}

function requireSuccessfulPresentationUpload(array $upload, string $label, int $maximum_bytes): void
{
    $upload_error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upload_error !== UPLOAD_ERR_OK) {
        if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException(ucfirst($label) . ' is larger than the allowed upload size.');
        }
        throw new InvalidArgumentException('The ' . $label . ' upload did not complete. Please try again.');
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1) {
        throw new InvalidArgumentException('The selected ' . $label . ' is empty.');
    }
    if ($size > $maximum_bytes) {
        $maximum_megabytes = (int) floor($maximum_bytes / 1024 / 1024);
        throw new InvalidArgumentException(
            ucfirst($label) . ' must be ' . $maximum_megabytes . ' MB or smaller.'
        );
    }

    $path = (string) ($upload['tmp_name'] ?? '');
    if ($path === '' || !is_uploaded_file($path)) {
        throw new InvalidArgumentException('The ' . $label . ' upload could not be verified.');
    }
}

function presentationSlideDeckFromPath(
    string $path,
    string $original_name,
    bool $require_uploaded_file = true
): array {
    if ($path === '' || !is_file($path) || ($require_uploaded_file && !is_uploaded_file($path))) {
        throw new InvalidArgumentException('The PDF slide deck upload could not be verified.');
    }

    $size = filesize($path);
    if ($size === false || $size < 1) {
        throw new InvalidArgumentException('The selected PDF slide deck is empty.');
    }
    if ($size > PRESENTATION_SLIDE_DECK_MAX_BYTES) {
        throw new InvalidArgumentException('PDF slide decks must be 100 MB or smaller.');
    }

    $contents = file_get_contents($path);
    if ($contents === false || strlen($contents) !== $size) {
        throw new RuntimeException('The PDF slide deck could not be read.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $tail = substr($contents, -4096);
    $hasValidStructure = preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)(?:\r\n|\r|\n)/', $contents) === 1
        && preg_match('/startxref\s+\d+\s+%%EOF[\x09\x0A\x0C\x0D\x20]*\z/D', $tail) === 1;
    if ((string) $finfo->file($path) !== 'application/pdf' || !$hasValidStructure) {
        throw new InvalidArgumentException('Upload a valid PDF slide deck.');
    }

    $filename = basename(str_replace('\\', '/', trim($original_name)));
    $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?: 'slide-deck.pdf';
    if (!str_ends_with(strtolower($filename), '.pdf')) {
        $filename .= '.pdf';
    }
    if (strlen($filename) > 255) {
        $filename = substr($filename, 0, 251) . '.pdf';
    }

    return [
        'data' => $contents,
        'filename' => $filename,
        'size' => strlen($contents),
        'sha256' => hash('sha256', $contents, true),
        'mime_type' => 'application/pdf',
    ];
}

function presentationSlideDeckFromUpload(array $upload): array
{
    requireSuccessfulPresentationUpload(
        $upload,
        'PDF slide deck',
        PRESENTATION_SLIDE_DECK_MAX_BYTES
    );
    return presentationSlideDeckFromPath(
        (string) $upload['tmp_name'],
        (string) $upload['name']
    );
}

function presentationQrImageFromUpload(array $upload, string $label): array
{
    requireSuccessfulPresentationUpload($upload, $label, PRESENTATION_QR_MAX_BYTES);
    return normalizedUploadedImage(
        (string) $upload['tmp_name'],
        PRESENTATION_QR_MAX_BYTES,
        PRESENTATION_QR_MAX_PIXELS,
        PRESENTATION_QR_MAX_DIMENSION,
        $label
    );
}

function presentationAssetRemovalRequested(array $submitted_row, string $asset_key): bool
{
    $value = $submitted_row['remove_' . $asset_key] ?? null;
    if ($value === null) {
        return false;
    }
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('Invalid presentation asset submission.');
    }
    return (string) $value === '1';
}

function attachPresentationAssetChanges(
    array $presentations,
    $submitted_presentations,
    array $files
): array {
    $submitted_presentations = is_array($submitted_presentations) ? $submitted_presentations : [];
    $upload_map = presentationAssetUploadMap($files);
    $definitions = presentationAssetDefinitions();

    foreach ($presentations as &$presentation) {
        $form_key = (string) ($presentation['_form_key'] ?? '');
        $submitted_row = $submitted_presentations[$form_key] ?? [];
        if (!is_array($submitted_row)) {
            throw new InvalidArgumentException('Invalid presentation submission.');
        }
        $changes = [];

        foreach ($definitions as $asset_key => $definition) {
            $upload = $upload_map[$form_key][$asset_key] ?? null;
            $remove = presentationAssetRemovalRequested($submitted_row, $asset_key);
            if ($upload !== null && $remove) {
                throw new InvalidArgumentException(
                    'Choose either a replacement or removal for the ' . $definition['label'] . ', not both.'
                );
            }
            if ($upload !== null) {
                $asset = $definition['kind'] === 'pdf'
                    ? presentationSlideDeckFromUpload($upload)
                    : presentationQrImageFromUpload($upload, $definition['label']);
                $changes[$asset_key] = ['action' => 'replace', 'asset' => $asset];
            } elseif ($remove) {
                $changes[$asset_key] = ['action' => 'remove'];
            }
        }

        $presentation['asset_changes'] = $changes;
    }
    unset($presentation);
    return $presentations;
}

function applyPresentationAssetChanges(
    mysqli $conn,
    int $engagement_id,
    int $presentation_id,
    array $changes
): bool {
    if (!$changes) {
        return false;
    }

    $definitions = presentationAssetDefinitions();
    foreach ($changes as $asset_key => $change) {
        if (!isset($definitions[$asset_key]) || !is_array($change)) {
            throw new InvalidArgumentException('Invalid presentation asset submission.');
        }
        $definition = $definitions[$asset_key];
        $action = (string) ($change['action'] ?? '');

        if ($action === 'remove') {
            $columns = [$definition['data_column'], $definition['sha_column'], $definition['updated_column']];
            if ($definition['mime_column']) {
                $columns[] = $definition['mime_column'];
            }
            if ($definition['filename_column']) {
                $columns[] = $definition['filename_column'];
            }
            if ($definition['size_column']) {
                $columns[] = $definition['size_column'];
            }
            $assignments = implode(' = NULL, ', $columns) . ' = NULL';
            $stmt = $conn->prepare(
                'UPDATE presentations SET ' . $assignments . ' WHERE id = ? AND engagement_id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare presentation asset removal.');
            }
            $stmt->bind_param('ii', $presentation_id, $engagement_id);
        } elseif ($action === 'replace' && is_array($change['asset'] ?? null)) {
            $asset = $change['asset'];
            if ($definition['kind'] === 'pdf') {
                $stmt = $conn->prepare(
                    'UPDATE presentations
                     SET slide_deck_pdf = ?, slide_deck_filename = ?, slide_deck_size = ?,
                         slide_deck_sha256 = ?, slide_deck_updated_at = UTC_TIMESTAMP(6)
                     WHERE id = ? AND engagement_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare the PDF slide deck update.');
                }
                $blob = null;
                $stmt->bind_param(
                    'bsisii',
                    $blob,
                    $asset['filename'],
                    $asset['size'],
                    $asset['sha256'],
                    $presentation_id,
                    $engagement_id
                );
                $stmt->send_long_data(0, $asset['data']);
            } else {
                $stmt = $conn->prepare(
                    'UPDATE presentations
                     SET ' . $definition['data_column'] . ' = ?, '
                         . $definition['mime_column'] . ' = ?, '
                         . $definition['sha_column'] . ' = ?, '
                         . $definition['updated_column'] . ' = UTC_TIMESTAMP(6)
                     WHERE id = ? AND engagement_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare the QR code update.');
                }
                $blob = null;
                $stmt->bind_param(
                    'bssii',
                    $blob,
                    $asset['mime_type'],
                    $asset['sha256'],
                    $presentation_id,
                    $engagement_id
                );
                $stmt->send_long_data(0, $asset['data']);
            }
        } else {
            throw new InvalidArgumentException('Invalid presentation asset submission.');
        }

        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Unable to update a presentation asset.');
        }
        $stmt->close();
    }

    return true;
}

function mergeStoredPresentationAssetMetadata(array $submitted_rows, array $stored_presentations): array
{
    $stored_by_id = [];
    foreach ($stored_presentations as $stored_presentation) {
        if (!empty($stored_presentation['id'])) {
            $stored_by_id[(int) $stored_presentation['id']] = $stored_presentation;
        }
    }

    foreach ($submitted_rows as &$submitted_row) {
        if (!is_array($submitted_row) || empty($submitted_row['id'])) {
            continue;
        }
        $stored = $stored_by_id[(int) $submitted_row['id']] ?? null;
        if (!$stored) {
            continue;
        }
        foreach ([
            'has_slide_deck', 'slide_deck_filename', 'slide_deck_size', 'slide_deck_updated_at',
            'has_speaker_notes_qr', 'speaker_notes_qr_updated_at',
            'has_speaker_website_qr', 'speaker_website_qr_updated_at',
            'has_speaker_donation_qr', 'speaker_donation_qr_updated_at',
        ] as $metadata_key) {
            if (array_key_exists($metadata_key, $stored)) {
                $submitted_row[$metadata_key] = $stored[$metadata_key];
            }
        }
    }
    unset($submitted_row);
    return $submitted_rows;
}
