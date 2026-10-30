<?php

declare(strict_types=1);

// Ingress-only, sessionless catch-all. The normal app never activates this mode.
$identifier = getenv('DNR_MAINTENANCE_GUARD_ID');
if (!is_string($identifier) || preg_match('/\A[a-f0-9]{32}\z/', $identifier) !== 1) {
    http_response_code(404);
    exit;
}
header('Cache-Control: no-store, max-age=0');
header('X-DNR-Maintenance-Guard: ' . $identifier);
header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'");

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
preg_match('~\A(/a/[a-z][a-z0-9-]{2,63})(?:/|$)~', $path, $match);
// This responder receives the original URL, without Account StripPrefix.
$_SERVER['HTTP_X_FORWARDED_PREFIX'] = $match[1] ?? '';
$route = substr($path, strlen($match[1] ?? ''));
if ($route === '/deployment_status.php') {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }
    require_once __DIR__ . '/deployment_notice_helpers.php';
    $status = deploymentNoticeStatus();
    // Even a stale/in-flight guard response cannot release a waiting browser.
    // Only the restored Account route can report completion and readiness.
    $status['deployment_complete'] = false;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($status, JSON_THROW_ON_ERROR);
    exit;
}
if ($route === '/ready.php' || $route === '/health.php') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 30');
    echo '{"status":"not_ready"}';
    exit;
}
require __DIR__ . '/maintenance.php';
