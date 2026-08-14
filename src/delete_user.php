<?php
include 'config.php';
include 'functions.php';
startSecureSession();

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Redirect to login page if not logged in as admin
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();
$user_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

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
