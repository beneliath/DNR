<?php
// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireTwoFactorSchema($conn);

if (isLoggedIn()) {
    header('Location: engagements.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = fetchAuthenticationUserByUsername($conn, $username);
    $dummy_password_hash = '$2y$12$wTYbXn3kB2NAKPhZdVBniuzRdPySg8k3v67l4dxLCh7t3kGpifYI.';
    $password_valid = password_verify($password, $user['password'] ?? $dummy_password_hash);

    if ($user && empty($user['login_is_locked']) && $password_valid) {
        setDatabaseAuditContext($conn, (int) $user['id'], (string) $user['username']);
        resetAuthenticationFailures($conn, (int) $user['id'], 'password');
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

    $error = 'Invalid username or password, or the account is temporarily unavailable.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DNR - Login</title>
  <link rel="stylesheet" href="assets/css/style.css?v=0.0.9">
  <script>
    // Load theme before page renders
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
      document.documentElement.classList.add('dark-mode');
    }
  </script>
</head>
<body class="fullscreen-center">
  <div class="login-container">
    <h1>Login</h1>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <form method="post" action="login.php">
      <?php echo csrfInput(); ?>
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" name="username" id="username" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" name="password" id="password" required>
      </div>

      <button type="submit" class="login-button">Login</button>
    </form>
    <p class="login-secondary-link"><a href="recover_password.php">Forgot your password?</a></p>
  </div>
  <script src="assets/js/theme.js"></script>
</body>
</html>
