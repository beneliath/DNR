<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inbound_email_helpers.php';

$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (!hasRole(['admin', 'editor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

try {
    echo json_encode([
        'engagements' => searchInboundEmailEngagements(
            $conn,
            \Dnr\Http\RequestInput::string($_GET, 'q', '', 100),
            30
        ),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    applicationLog('error', 'Inbound Engagement search failed', [
        'error' => $exception->getMessage(),
    ]);
    http_response_code(503);
    echo json_encode(['error' => 'Engagement search is temporarily unavailable.']);
}
