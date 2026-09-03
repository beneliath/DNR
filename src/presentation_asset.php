<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation_asset_helpers.php';
startSecureSession();
requireLogin();
releaseApplicationSessionLock();

$presentation_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$query_type = \Dnr\Http\RequestInput::string($_GET, 'type');
$definition = presentationAssetDefinitionForQueryType((string) $query_type);
if (!$presentation_id || $presentation_id < 1 || $definition === null) {
    http_response_code(400);
    exit;
}

$select_columns = ['HEX(' . $definition['sha_column'] . ') AS asset_sha256'];
if ($definition['mime_column']) {
    $select_columns[] = $definition['mime_column'] . ' AS asset_mime';
}
if ($definition['filename_column']) {
    $select_columns[] = $definition['filename_column'] . ' AS asset_filename';
}
$asset_size_expression = $definition['size_column']
    ? $definition['size_column'] . ' AS asset_size'
    : 'OCTET_LENGTH(' . $definition['data_column'] . ') AS asset_size';
$select_columns[] = $asset_size_expression;

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

$asset_size = (int) ($asset['asset_size'] ?? 0);
if ($asset_size < 1) {
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
if (preg_match('/^[0-9a-f]{64}$/', $asset_hash) !== 1) {
    http_response_code(404);
    exit;
}
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
$etag = '"presentation-' . $presentation_id . '-' . $definition['query_type'] . '-' . $asset_hash . '"';
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$filename = $definition['kind'] === 'pdf'
    ? (string) ($asset['asset_filename'] ?? 'slide-deck.pdf')
    : $definition['query_type'] . '.png';
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($filename)) ?: 'presentation-asset';

$range = null;
$range_header = (string) ($_SERVER['HTTP_RANGE'] ?? '');
$if_range = trim((string) ($_SERVER['HTTP_IF_RANGE'] ?? ''));
if ($range_header !== '' && $if_range !== '' && !hash_equals($etag, $if_range)) {
    // Without a matching strong validator, RFC 9110 requires the complete
    // current representation instead of combining old and new partial bytes.
    $range_header = '';
}
try {
    $range = presentationAssetByteRange($range_header, $asset_size);
} catch (OutOfRangeException $exception) {
    header('Accept-Ranges: bytes');
    header('Content-Range: bytes */' . $asset_size);
    http_response_code(416);
    exit;
}

$representation_condition = 'id = ? AND ' . $definition['sha_column']
    . ' = UNHEX(?) AND ' . str_replace(' AS asset_size', '', $asset_size_expression) . ' = ?';
$data_sql = $range === null
    ? 'SELECT ' . $definition['data_column'] . ' AS asset_data FROM presentations WHERE '
        . $representation_condition
    : 'SELECT SUBSTRING(' . $definition['data_column'] . ', ?, ?) AS asset_data '
        . 'FROM presentations WHERE ' . $representation_condition;
$data_stmt = $conn->prepare($data_sql);
if (!$data_stmt) {
    http_response_code(503);
    exit;
}
if ($range === null) {
    $data_stmt->bind_param('isi', $presentation_id, $asset_hash, $asset_size);
} else {
    $range_position = $range['start'] + 1;
    $range_length = $range['length'];
    $data_stmt->bind_param(
        'iiisi',
        $range_position,
        $range_length,
        $presentation_id,
        $asset_hash,
        $asset_size
    );
}
if (!$data_stmt->execute()) {
    $data_stmt->close();
    http_response_code(503);
    exit;
}
$data = $data_stmt->get_result()->fetch_assoc()['asset_data'] ?? null;
$data_stmt->close();
if (!is_string($data) || strlen($data) !== ($range['length'] ?? $asset_size)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime_type);
header('Accept-Ranges: bytes');
if ($range !== null) {
    http_response_code(206);
    header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $asset_size);
}
header('Content-Length: ' . strlen($data));
if ($definition['kind'] === 'pdf') {
    // Uploaded PDFs are opaque active-content containers. Downloading them
    // avoids running viewer features in the authenticated application origin.
    header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
} else {
    header('Content-Disposition: inline; filename="' . addcslashes($filename, '"\\') . '"');
}
echo $data;
