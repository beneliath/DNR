<?php
// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireTwoFactorSchema($conn);
requireLoginRateLimitSchema($conn);

if (isLoggedIn()) {
    header('Location: engagements.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (loginRateLimitIsBlocked($conn)) {
        http_response_code(429);
        header('Retry-After: 300');
        $error = 'Invalid username or password, or the account is temporarily unavailable.';
    } else {
        $user = fetchAuthenticationUserByUsername($conn, $username);
        $dummy_password_hash = '$2y$12$wTYbXn3kB2NAKPhZdVBniuzRdPySg8k3v67l4dxLCh7t3kGpifYI.';
        $password_valid = password_verify($password, $user['password'] ?? $dummy_password_hash);

        if ($user && empty($user['login_is_locked']) && $password_valid) {
            setDatabaseAuditContext($conn, (int) $user['id'], (string) $user['username']);
            resetAuthenticationFailures($conn, (int) $user['id'], 'password');
            clearLoginRateLimitForCurrentIp($conn, $username);
            beginPendingAuthentication($user);

            if (!empty($user['two_factor_enabled'])) {
                header('Location: verify_2fa.php');
                exit();
            }

            if (twoFactorRequiredForRole($user['role'])) {
                header('Location: setup_2fa.php');
                exit();
            }

            completeAuthentication($conn, $user, false);
            header('Location: ' . authenticationDestination($user));
            exit();
        }

        if ($user && empty($user['login_is_locked'])) {
            recordAuthenticationFailure($conn, (int) $user['id'], 'password');
        }

        if (!$user) {
            $failure_details = 'Unknown username';
        } elseif (!empty($user['login_is_locked'])) {
            $failure_details = 'Password authentication temporarily locked';
        } else {
            $failure_details = 'Incorrect password';
        }
        $rate_limit = recordLoginRateLimitFailure($conn, $username);
        if ($rate_limit['should_audit']) {
            recordFailedLoginAttempt($conn, $username, $failure_details, $user);
        }
        if ($rate_limit['blocked']) {
            http_response_code(429);
            header('Retry-After: 300');
        }

        $error = 'Invalid username or password, or the account is temporarily unavailable.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in - DNR</title>
  <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
  <link rel="stylesheet" href="assets/css/modern.min.css?v=0.1.59">
  <script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>">
    // Load theme before page renders
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
      document.documentElement.classList.add('dark-mode');
    }
  </script>
</head>
<body class="fullscreen-center">
  <button type="button" class="mobile-theme-button auth-theme-toggle" data-theme-toggle aria-label="Switch to dark theme">
    <svg class="theme-icon-light" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
    <svg class="theme-icon-dark" aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
  </button>
  <div class="login-container">
    <div class="auth-brand">
      <span class="auth-brand-copy"><strong>MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi></strong></span>
    </div>
    <h1>Welcome Back</h1>
    <p class="auth-intro">Sign in to manage engagements and contacts.</p>
    <?php if (isset($_GET['database_restored'])): ?>
      <p class="success">The database was restored successfully. All sessions were signed out.</p>
    <?php endif; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <form method="post" action="login.php">
      <?php echo csrfInput(); ?>
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" name="username" id="username" autocomplete="username" required autofocus>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" name="password" id="password" autocomplete="current-password" required>
      </div>

      <button type="submit" class="login-button">Sign in</button>
    </form>
    <p class="login-secondary-link"><a href="recover_password.php">Forgot your password?</a></p>
    <p class="auth-assurance">
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
      Protected with two-factor authentication
    </p>
  </div>
  <script src="assets/js/theme.min.js?v=1.1.0"></script>
</body>
</html>
