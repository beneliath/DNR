<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();
require_once __DIR__ . '/two_factor_helpers.php';
requireRecentAdminElevation('users.php');
$user_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$delete_confirmation = $_POST['delete_confirmation'] ?? '';

// Require the destructive-action phrase even if client-side validation is bypassed.
if (!is_string($delete_confirmation) || !hash_equals('DELETE USER', $delete_confirmation)) {
    http_response_code(400);
    exit('User not deleted: confirmation phrase must be DELETE USER.');
}

// Prevent an administrator from deleting the account backing the active session.
if (!$user_id || $user_id === (int) $_SESSION['user_id']) {
    header("Location: users.php");
    exit();
}

$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();

header("Location: users.php");
exit();
?>
