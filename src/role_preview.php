<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();

if (authenticatedRole() !== 'admin') {
    http_response_code(403);
    exit('Forbidden.');
}

$requested_role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
$requested_return_url = $_POST['return_to'] ?? 'dashboard.php';
$previous_role = activeRolePreview() ?? 'admin';
if (!setRolePreview($requested_role)) {
    http_response_code(400);
    exit('Invalid role preview.');
}

$current_role = activeRolePreview() ?? 'admin';
if ($current_role !== $previous_role) {
    $user_id = (int) $_SESSION['user_id'];
    $username = (string) ($_SESSION['username'] ?? '');
    recordAuditEvent($conn, [
        'event_category' => 'security',
        'event_type' => $current_role === 'admin'
            ? 'admin_role_preview_stopped'
            : 'admin_role_preview_started',
        'actor_user_id' => $user_id,
        'actor_username' => $username,
        'target_user_id' => $user_id,
        'target_username' => $username,
        'entity_type' => 'users',
        'entity_id' => $user_id,
        'entity_label' => $username,
        'details' => $current_role === 'admin'
            ? 'Returned to Administrator access from ' . ucfirst($previous_role) . ' preview'
            : 'Viewing application with ' . ucfirst($current_role) . ' access',
    ]);
}

unset($_SESSION['_admin_elevated_at']);
session_regenerate_id(true);
$_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

header('Cache-Control: no-store, max-age=0');
header('Location: ' . safeRolePreviewReturnUrl($requested_return_url, $current_role));
exit();
