<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
header('Cache-Control: no-store, max-age=0');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
requireValidCsrfToken();

unset($_SESSION['_admin_elevated_at']);
releaseApplicationSessionLock();
if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['locked' => true]);
    exit;
}
header('Location: ' . safeAdminElevationReturnUrl($_POST['return_to'] ?? 'dashboard.php'), true, 303);
