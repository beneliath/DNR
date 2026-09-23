<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/presentation_chunk_helpers.php';
startSecureSession();
requireLogin();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(); }
if (!in_array($_SESSION['role'] ?? '', ['admin', 'editor'], true)) { http_response_code(403); exit(); }
requireValidCsrfToken();
try {
    $engagementId = filter_var($_POST['engagement_id'] ?? null, FILTER_VALIDATE_INT);
    $row = $_POST['row'] ?? null;
    if (!$engagementId || !is_string($row)
        || !$conn->execute_query('SELECT id FROM engagements WHERE id = ? AND is_deleted = 0', [$engagementId])->fetch_row()) {
        throw new InvalidArgumentException('Engagement is unavailable.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'start') {
        $size = filter_var($_POST['size'] ?? null, FILTER_VALIDATE_INT);
        $name = $_POST['name'] ?? null;
        $assetKey = $_POST['asset_key'] ?? 'ppt_slidedeck';
        if (!$size || !is_string($name) || !is_string($assetKey)) throw new InvalidArgumentException('Invalid upload.');
        $result = ['token' => startPresentationChunk($engagementId, $row, $name, $size, $assetKey)];
    } else {
        $token = $_POST['token'] ?? '';
        if (!is_string($token)) throw new InvalidArgumentException('Invalid upload reference.');
        ownedPresentationChunk($token, $engagementId, $row);
        if ($action === 'cancel') {
            discardPresentationChunk($token);
            $result = ['canceled' => true];
        } elseif ($action === 'chunk') {
            $offset = filter_var($_POST['offset'] ?? null, FILTER_VALIDATE_INT);
            $file = $_FILES['chunk'] ?? [];
            if ($offset === false || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Invalid upload chunk.');
            $result = ['offset' => appendPresentationChunk($token, $engagementId, $row, $offset, $file['tmp_name'])];
        } else throw new InvalidArgumentException('Invalid upload action.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    applicationLog('error', 'Presentation chunk upload failed', ['exception' => $error->getMessage()]);
    http_response_code(503);
    echo json_encode(['error' => 'Upload storage is temporarily unavailable. Please try again.']);
}
