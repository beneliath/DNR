<?php
require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
requireRecentAdminElevation('users.php');
$user_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$delete_confirmation = $_POST['delete_confirmation'] ?? '';

// Require the destructive-action phrase even if client-side validation is bypassed.
if (!is_string($delete_confirmation) || !hash_equals('DELETE USER', $delete_confirmation)) {
    http_response_code(400);
    exit('User not deleted: confirmation phrase must be DELETE USER.');
}

try {
    if (!$user_id) {
        throw new InvalidArgumentException('Select a valid user account.');
    }
    deleteInactiveUserAccount($conn, $user_id, (int) $_SESSION['user_id']);
    $_SESSION['_user_lifecycle_message'] = 'The inactive account was permanently deleted.';
} catch (InvalidArgumentException $exception) {
    $_SESSION['_user_lifecycle_error'] = $exception->getMessage();
} catch (Throwable $exception) {
    applicationLog('error', 'User deletion failed', [
        'target_user_id' => $user_id,
        'error' => $exception->getMessage(),
    ]);
    $_SESSION['_user_lifecycle_error'] = 'The account could not be deleted.';
}

unset($_SESSION['_admin_elevated_at']);
header('Location: users.php');
exit();
