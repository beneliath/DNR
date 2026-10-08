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
$assigned_role = authenticatedSuperAdmin() ? 'superadmin' : 'admin';
$role_labels = ['superadmin' => 'SuperAdmin', 'admin' => 'Administrator', 'editor' => 'Editor', 'reviewer' => 'Reviewer'];
$previous_role = activeRolePreview() ?? $assigned_role;
if (!setRolePreview($requested_role)) {
    http_response_code(400);
    exit('Invalid role preview.');
}

$preview_role = activeRolePreview();
$current_role = $preview_role ?? $assigned_role;
if ($current_role !== $previous_role) {
    $user_id = (int) $_SESSION['user_id'];
    $username = (string) ($_SESSION['username'] ?? '');
    recordAuditEvent($conn, [
        'event_category' => 'security',
        'event_type' => $preview_role === null
            ? 'admin_role_preview_stopped'
            : 'admin_role_preview_started',
        'actor_user_id' => $user_id,
        'actor_username' => $username,
        'target_user_id' => $user_id,
        'target_username' => $username,
        'entity_type' => 'users',
        'entity_id' => $user_id,
        'entity_label' => $username,
        'details' => $preview_role === null
            ? 'Returned to ' . $role_labels[$current_role] . ' access from ' . $role_labels[$previous_role] . ' preview'
            : 'Viewing application with ' . $role_labels[$current_role] . ' access',
    ]);
}

unset($_SESSION['_admin_elevated_at'], $_SESSION['_admin_elevation_expires_at'], $_SESSION['_platform_admin_elevation']);
session_regenerate_id(true);
$_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

header('Cache-Control: no-store, max-age=0');
header('Location: ' . safeRolePreviewReturnUrl($requested_return_url, $current_role));
exit();
