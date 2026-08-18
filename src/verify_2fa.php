<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireTwoFactorSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$pending = getPendingAuthentication();
if (!$pending) {
    header('Location: login.php');
    exit();
}

$user = fetchAuthenticationUserById($conn, (int) $pending['user_id']);
if (!$user || empty($user['two_factor_enabled'])) {
    unset($_SESSION['_pending_auth']);
    header('Location: login.php');
    exit();
}
setDatabaseAuditContext($conn, (int) $user['id'], (string) $user['username']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $code = trim($_POST['authentication_code'] ?? '');
    $user = fetchAuthenticationUserById($conn, (int) $pending['user_id']);

    if (!$user || !empty($user['two_factor_is_locked'])) {
        recordFailedLoginAttempt(
            $conn,
            (string) $pending['username'],
            $user
                ? 'Two-factor authentication temporarily locked'
                : 'Account unavailable during two-factor authentication',
            $user
        );
        $error = 'The code could not be verified. Please wait and try again.';
    } else {
        try {
            $used_recovery_code = false;
            $is_totp_code = preg_match('/^\s*[0-9]{6}\s*$/', $code) === 1;
            $verified = $is_totp_code
                ? verifyAndConsumeTotp($conn, $user, $code)
                : false;

            if (!$verified && !$is_totp_code) {
                $used_recovery_code = consumeRecoveryCode($conn, (int) $user['id'], $code);
                $verified = $used_recovery_code;
            }

            if ($verified) {
                resetAuthenticationFailures($conn, (int) $user['id'], 'two_factor');
                logSecurityEvent(
                    $conn,
                    $used_recovery_code ? 'recovery_code_login' : 'two_factor_login',
                    (int) $user['id'],
                    (int) $user['id']
                );
                completeAuthentication($conn, $user, true);
                header('Location: ' . authenticationDestination($user));
                exit();
            }

            recordAuthenticationFailure($conn, (int) $user['id'], 'two_factor');
            recordFailedLoginAttempt(
                $conn,
                (string) $user['username'],
                'Incorrect authentication code',
                $user
            );
            $error = 'The code could not be verified. Check the code and try again.';
        } catch (Throwable $exception) {
            error_log('Two-factor verification error: ' . $exception->getMessage());
            $error = 'Two-factor verification is temporarily unavailable.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-Factor Verification - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
  <link rel="stylesheet" href="assets/css/modern.min.css?v=0.1.57">
    <script>
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') document.documentElement.classList.add('dark-mode');
    </script>
</head>
<body class="fullscreen-center">
    <button type="button" class="mobile-theme-button auth-theme-toggle" onclick="toggleTheme()" data-theme-toggle aria-label="Switch to dark theme">
        <svg class="theme-icon-light" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
        <svg class="theme-icon-dark" aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
    </button>
    <div class="login-container">
        <div class="auth-brand">
            <span class="auth-brand-copy"><strong>MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi></strong></span>
        </div>
        <h1>Verification</h1>
        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <p class="login-help">Enter the six-digit code from your authenticator app or one unused recovery code.</p>
        <form method="post" action="verify_2fa.php">
            <?php echo csrfInput(); ?>
            <div class="form-group">
                <label for="authentication_code">Authentication code</label>
                <input
                    type="text"
                    name="authentication_code"
                    id="authentication_code"
                    autocomplete="one-time-code"
                    autocapitalize="characters"
                    spellcheck="false"
                    required
                    autofocus
                >
            </div>
            <button type="submit" class="login-button">Verify</button>
        </form>
        <form method="post" action="logout.php" class="login-cancel-form">
            <?php echo csrfInput(); ?>
            <button type="submit" class="login-cancel-button">Cancel login</button>
        </form>
        <p class="auth-assurance">
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
            Protected with two-factor authentication
        </p>
    </div>
    <script src="assets/js/theme.min.js"></script>
</body>
</html>
