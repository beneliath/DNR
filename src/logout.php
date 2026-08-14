<?php
require_once __DIR__ . '/functions.php';
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrfToken();

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
