<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/inquiry_relationship_helpers.php';
startSecureSession();
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (!hasRole(['admin', 'editor'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden.']));
}
releaseApplicationSessionLock();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit(json_encode(['error' => 'Method not allowed.']));
}
try {
    $result = searchInquiryRelationships($conn,
        \Dnr\Http\RequestInput::string($_GET, 'kind'),
        \Dnr\Http\RequestInput::string($_GET, 'q'),
        \Dnr\Http\RequestInput::positiveInt($_GET, 'organization_id'),
        \Dnr\Http\RequestInput::positiveInt($_GET, 'selected_id'));
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    applicationLog('error', 'Inquiry relationship search failed', ['error' => $exception->getMessage()]);
    http_response_code(503);
    echo json_encode(['error' => 'Search is temporarily unavailable. Try again.']);
}
