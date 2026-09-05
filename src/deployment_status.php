<?php

declare(strict_types=1);

// This intentionally public, read-only endpoint contains only a site-wide notice.
// Polling cannot prolong login sessions or contend with users saving their work.
require_once __DIR__ . '/deployment_notice_helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
echo json_encode(deploymentNoticeStatus(), JSON_UNESCAPED_SLASHES);
