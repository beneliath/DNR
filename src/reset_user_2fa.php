<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();
requireRecentAdminElevation('users.php');
$target_user_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$confirmation = $_POST['reset_confirmation'] ?? '';

if (!is_string($confirmation) || !hash_equals('RESET 2FA', $confirmation)) {
    http_response_code(400);
    exit('Two-factor authentication not reset: confirmation phrase must be RESET 2FA.');
}

if (!$target_user_id || $target_user_id === (int) $_SESSION['user_id']) {
    http_response_code(400);
    exit('Use Account Security to replace your own authenticator.');
}

$target_user = fetchAuthenticationUserById($conn, $target_user_id);
if (!$target_user) {
    header('Location: users.php');
    exit();
}

disableTwoFactorForUser($conn, $target_user_id);
logSecurityEvent(
    $conn,
    'two_factor_admin_reset',
    $target_user_id,
    (int) $_SESSION['user_id']
);

header('Location: users.php?two_factor_reset=1');
exit();
?>
