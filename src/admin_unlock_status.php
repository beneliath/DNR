<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$unlocked = hasRecentAdminElevation();
$status = [
    'unlocked' => $unlocked,
    'expires_at' => adminElevationExpiresAt(),
    'server_now' => microtime(true),
    // An unlock in another tab rotates the session's CSRF token.
    'csrf_token' => $unlocked ? generateCsrfToken() : null,
];
releaseApplicationSessionLock();
echo json_encode($status);
