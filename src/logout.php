<?php
require_once __DIR__ . '/bootstrap.php';
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();

if (isLoggedIn()) {
    $user_id = (int) $_SESSION['user_id'];
    $username = (string) ($_SESSION['username'] ?? '');
    setDatabaseAuditContext($conn, $user_id, $username);
    recordAuditEvent($conn, [
        'event_category' => 'login',
        'event_type' => 'logout',
        'actor_user_id' => $user_id,
        'actor_username' => $username,
        'target_user_id' => $user_id,
        'target_username' => $username,
        'entity_type' => 'users',
        'entity_id' => $user_id,
        'entity_label' => $username,
        'details' => 'Session ended by user',
    ]);
}

// Unset all session variables
session_unset();

// Destroy the session
session_destroy();

if (ini_get('session.use_cookies')) {
    $cookie_params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookie_params['path'],
        $cookie_params['domain'],
        $cookie_params['secure'],
        $cookie_params['httponly']
    );
}

// Redirect to login page after logout
header("Location: login.php");
exit();
?>
