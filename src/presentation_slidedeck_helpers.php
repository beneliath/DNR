<?php

declare(strict_types=1);

require_once __DIR__ . '/presentation_asset_helpers.php';
require_once __DIR__ . '/persistent_file_helpers.php';
require_once __DIR__ . '/legacy_powerpoint_helpers.php';

const PRESENTATION_SLIDEDECK_MAX_BYTES = 500 * 1024 * 1024;
const PRESENTATION_SLIDEDECK_MIMES = [
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

function presentationSlidedeckFromPath(string $path, string $originalName, bool $requireUploadedFile = true): array
{
    if (!is_file($path) || ($requireUploadedFile && !is_uploaded_file($path))) {
        throw new InvalidArgumentException('The PPT Slidedeck upload could not be verified.');
    }
    $size = filesize($path);
    if (!$size) throw new InvalidArgumentException('The selected PPT Slidedeck is empty.');
    if ($size > PRESENTATION_SLIDEDECK_MAX_BYTES) {
        throw new InvalidArgumentException('PPT Slidedeck must be 500 MB or smaller.');
    }
    $filename = basename(str_replace('\\', '/', trim($originalName)));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!isset(PRESENTATION_SLIDEDECK_MIMES[$extension])) {
        throw new InvalidArgumentException('Upload a PowerPoint .ppt or .pptx file.');
    }
    $valid = false;
    if ($extension === 'ppt') {
        $valid = isValidLegacyPowerPointPath($path);
    } else {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) === true) {
            try {
                // Inspect bounded XML parts in place; never extract uploaded archives.
                $types = $zip->statName('[Content_Types].xml');
                $presentation = $zip->statName('ppt/presentation.xml');
                if ($types && $presentation && $types['size'] <= 1048576 && $presentation['size'] <= 4194304) {
                    $typeXml = new DOMDocument();
                    $deckXml = new DOMDocument();
                    $previous = libxml_use_internal_errors(true);
                    try {
                        $typesLoaded = $typeXml->loadXML($zip->getFromName('[Content_Types].xml') ?: '<invalid/>', LIBXML_NONET);
                        $deckLoaded = $deckXml->loadXML($zip->getFromName('ppt/presentation.xml') ?: '<invalid/>', LIBXML_NONET);
                    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
                    if ($typesLoaded && $deckLoaded && !$typeXml->doctype && !$deckXml->doctype) {
                        $xpath = new DOMXPath($typeXml);
                        $xpath->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');
                        $valid = $xpath->query('/ct:Types/ct:Override[@PartName="/ppt/presentation.xml" and @ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"]')->length === 1
                            && $deckXml->documentElement?->localName === 'presentation'
                            && in_array($deckXml->documentElement->namespaceURI, [
                                'http://schemas.openxmlformats.org/presentationml/2006/main',
                                'http://purl.oclc.org/ooxml/presentationml/main',
                            ], true);
                    }
                }
            } finally { $zip->close(); }
        }
    }
    if (!$valid) throw new InvalidArgumentException('Upload a valid PowerPoint .ppt or .pptx file.');
    $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', pathinfo($filename, PATHINFO_FILENAME)) ?: 'slidedeck';
    $filename = substr($filename, 0, 254 - strlen($extension)) . '.' . $extension;
    $checksum = hash_file('sha256', $path, true);
    if ($checksum === false) throw new RuntimeException('The PPT Slidedeck could not be read.');
    return ['path' => $path, 'filename' => $filename, 'size' => $size,
        'sha256' => $checksum, 'mime_type' => PRESENTATION_SLIDEDECK_MIMES[$extension]];
}

function presentationSlidedeckFromUpload(array $upload): array
{
    requireSuccessfulPresentationUpload($upload, 'PPT Slidedeck', PRESENTATION_SLIDEDECK_MAX_BYTES);
    return presentationSlidedeckFromPath((string) $upload['tmp_name'], (string) $upload['name']);
}

/** Called in the presentation transaction, matching the notes' speaker attribution. */
function applyPresentationSlidedeckChange(mysqli $conn, int $presentationId, int $engagementId, array $change, ?int $uploadedBy = null): void
{
    $presentation = $conn->execute_query('SELECT speaker_id FROM presentations WHERE id = ? AND engagement_id = ? FOR UPDATE',
        [$presentationId, $engagementId])->fetch_assoc();
    if (!$presentation) throw new InvalidArgumentException('Presentation not found.');
    $speakerId = (int) $presentation['speaker_id'];
    if (($change['action'] ?? '') === 'remove') {
        $conn->execute_query('UPDATE presentation_slidedecks SET storage_key = NULL, filename = NULL, mime_type = NULL,
            size = NULL, sha256 = NULL, uploaded_by = NULL, uploaded_by_username_snapshot = NULL, updated_at = UTC_TIMESTAMP(6)
            WHERE presentation_id = ? AND speaker_id = ?', [$presentationId, $speakerId]);
    } elseif (($change['action'] ?? '') === 'replace' && is_array($change['asset'] ?? null)) {
        $asset = $change['asset'];
        $key = storePersistentFileFromPath($conn, $asset['path'], $asset['filename'], $asset['mime_type'], $asset['size'], bin2hex($asset['sha256']));
        $conn->execute_query('INSERT INTO presentation_slidedecks
            (presentation_id, speaker_id, storage_key, filename, mime_type, size, sha256, uploaded_by, uploaded_by_username_snapshot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, (SELECT username FROM users WHERE id = ?))
            ON DUPLICATE KEY UPDATE storage_key = VALUES(storage_key), filename = VALUES(filename), mime_type = VALUES(mime_type),
            size = VALUES(size), sha256 = VALUES(sha256), uploaded_by = VALUES(uploaded_by),
            uploaded_by_username_snapshot = VALUES(uploaded_by_username_snapshot), updated_at = UTC_TIMESTAMP(6)',
            [$presentationId, $speakerId, $key, $asset['filename'], $asset['mime_type'], $asset['size'], $asset['sha256'], $uploadedBy, $uploadedBy]);
    } else throw new InvalidArgumentException('Invalid PPT Slidedeck change.');
}

/** Stream the immutable file; database snapshots and downloads never load its bytes from SQL. */
function deliverPresentationSlidedeck(mysqli $conn, int $presentationId, int $speakerId): void
{
    header('Cache-Control: private, no-store');
    header('Cloudflare-CDN-Cache-Control: no-store');
    $metadata = $conn->execute_query('SELECT f.* FROM presentation_slidedecks d
        JOIN stored_files f ON f.storage_key = d.storage_key WHERE d.presentation_id = ? AND d.speaker_id = ?',
        [$presentationId, $speakerId])->fetch_assoc();
    if (!$metadata) { http_response_code(404); return; }
    try {
        if (!in_array($metadata['content_type'], PRESENTATION_SLIDEDECK_MIMES, true)) {
            throw new RuntimeException('Unsupported slidedeck format.');
        }
        $file = openPersistentFile($metadata);
    } catch (Throwable $exception) { http_response_code(503); return; }
    try {
        $size = (int) $metadata['size'];
        $etag = '"slidedeck-' . $metadata['storage_key'] . '"';
        header('ETag: ' . $etag);
        header('Accept-Ranges: bytes');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); return; }
        $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if (isset($_SERVER['HTTP_IF_RANGE']) && trim((string) $_SERVER['HTTP_IF_RANGE']) !== $etag) $rangeHeader = '';
        try { $range = presentationAssetByteRange($rangeHeader, $size); }
        catch (OutOfRangeException $exception) {
            http_response_code(416); header('Content-Range: bytes */' . $size); return;
        }
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($metadata['filename'])) ?: 'slidedeck';
        header('Content-Type: ' . $metadata['content_type']);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        $remaining = $range['length'] ?? $size;
        header('Content-Length: ' . $remaining);
        if ($range !== null) {
            http_response_code(206);
            header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
        if (fseek($file, $range['start'] ?? 0) !== 0) throw new RuntimeException('Unable to seek slidedeck.');
        while ($remaining > 0 && !connection_aborted()) {
            $chunk = fread($file, min(1048576, $remaining));
            if ($chunk === false || $chunk === '') throw new RuntimeException('Slidedeck read failed.');
            echo $chunk;
            $remaining -= strlen($chunk);
        }
    } finally { fclose($file); }
}
