<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation_asset_helpers.php';
startSecureSession();
requireLogin();

$presentation_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$query_type = \Dnr\Http\RequestInput::string($_GET, 'type');
$definition = presentationAssetDefinitionForQueryType((string) $query_type);
if (!$presentation_id || $presentation_id < 1 || $definition === null) {
    http_response_code(400);
    exit;
}

$select_columns = [
    $definition['data_column'] . ' AS asset_data',
    'HEX(' . $definition['sha_column'] . ') AS asset_sha256',
];
if ($definition['mime_column']) {
    $select_columns[] = $definition['mime_column'] . ' AS asset_mime';
}
if ($definition['filename_column']) {
    $select_columns[] = $definition['filename_column'] . ' AS asset_filename';
}

$stmt = $conn->prepare(
    'SELECT ' . implode(', ', $select_columns) . '
     FROM presentations
     WHERE id = ?'
);
if (!$stmt) {
    http_response_code(503);
    exit;
}
$stmt->bind_param('i', $presentation_id);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(503);
    exit;
}
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

$data = $asset['asset_data'] ?? null;
if (!is_string($data) || $data === '') {
    http_response_code(404);
    exit;
}

$mime_type = $definition['kind'] === 'pdf'
    ? 'application/pdf'
    : (string) ($asset['asset_mime'] ?? '');
if ($definition['kind'] === 'image'
    && !in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)
) {
    http_response_code(404);
    exit;
}

$asset_hash = strtolower((string) ($asset['asset_sha256'] ?? ''));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
if (preg_match('/^[0-9a-f]{64}$/', $asset_hash) === 1) {
    $etag = '"presentation-' . $presentation_id . '-' . $definition['query_type'] . '-' . $asset_hash . '"';
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
}

$filename = $definition['kind'] === 'pdf'
    ? (string) ($asset['asset_filename'] ?? 'slide-deck.pdf')
    : $definition['query_type'] . '.png';
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($filename)) ?: 'presentation-asset';

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . strlen($data));
header('Content-Disposition: inline; filename="' . addcslashes($filename, '"\\') . '"');
echo $data;
