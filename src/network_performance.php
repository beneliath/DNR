<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/network_diagnostics_helpers.php';
startSecureSession();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($method === 'GET') {
    requireAdmin();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(fetchNetworkPerformanceSummary($conn), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

requireLogin();
requireValidCsrfToken();
$sample = normalizeNetworkPerformanceSample($_POST);
if ($sample === null) {
    http_response_code(422);
    exit;
}

$addressFamily = networkPerformanceAddressFamily(requestIpAddress());
$lastSampleAt = (float) ($_SESSION['_network_performance_sample_at'] ?? 0.0);
if ($addressFamily === null || microtime(true) - $lastSampleAt < 2.0) {
    http_response_code(204);
    exit;
}
$_SESSION['_network_performance_sample_at'] = microtime(true);
$cloudflareColo = networkPerformanceCloudflareColo($_SERVER);
session_write_close();

storeNetworkPerformanceSample($conn, $sample, $addressFamily, $cloudflareColo);
http_response_code(204);
