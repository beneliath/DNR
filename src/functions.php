<?php
// Start a cookie-only session with safe defaults for HTTP development and HTTPS deployment.
function startSecureSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $forwarded_proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $forwarded_proto === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Check if the user is logged in by verifying if 'user_id' is set in the session
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Ensure that the user is logged in; if not, redirect to the login page
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php"); // Redirect to login page if not logged in
        exit(); // Ensure no further code is executed after the redirect
    }
}

// Check if the logged-in user has a specific role
function checkRole($role) {
    return (isset($_SESSION['role']) && $_SESSION['role'] === $role);
}

// Check if the logged-in user has any of the specified roles
function hasRole($roles = []) {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true));
}

// Generate one CSRF token per session for state-changing requests.
function generateCsrfToken() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

// Render the hidden token field used by POST forms.
function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Validate a submitted CSRF token without leaking the session token.
function validateCsrfToken($token) {
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

// Stop a state-changing request before it reaches the database.
function requireValidCsrfToken() {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid or expired request token. Please reload the page and try again.');
    }
}
?>
