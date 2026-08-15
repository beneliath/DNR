<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireTwoFactorSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if (isLoggedIn()) {
    header('Location: two_factor_settings.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'cancel') {
        clearPasswordRecovery();
        session_regenerate_id(true);
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        header('Location: login.php');
        exit();
    }

    if ($action === 'start') {
        $username_input = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
        $username = trim($username_input);
        $user = $username !== '' ? fetchAuthenticationUserByUsername($conn, $username) : null;
        $eligible_user = $user && !empty($user['two_factor_enabled']) ? $user : null;

        beginPasswordRecovery($eligible_user);
        if ($eligible_user) {
            logSecurityEvent($conn, 'password_recovery_started', (int) $user['id']);
        }

        header('Location: recover_password.php');
        exit();
    }

    if ($action === 'verify') {
        $recovery = getPasswordRecovery('verify');
        if (!$recovery) {
            $error = 'The recovery request expired. Start again.';
        } else {
            $user = !empty($recovery['user_id'])
                ? fetchAuthenticationUserById($conn, (int) $recovery['user_id'])
                : null;
            $code_input = is_string($_POST['authentication_code'] ?? null)
                ? $_POST['authentication_code']
                : '';
            $code = trim($code_input);
            $verified = false;
            $used_recovery_code = false;

            try {
                if ($user
                    && (int) $user['auth_version'] === (int) $recovery['auth_version']
                    && !empty($user['two_factor_enabled'])
                    && empty($user['two_factor_is_locked'])
                ) {
                    $is_totp_code = preg_match('/^[0-9]{6}$/', $code) === 1;
                    $verified = $is_totp_code
                        ? verifyAndConsumeTotp($conn, $user, $code)
                        : consumeRecoveryCode($conn, (int) $user['id'], $code);
                    $used_recovery_code = $verified && !$is_totp_code;
                }

                if ($verified) {
                    resetAuthenticationFailures($conn, (int) $user['id'], 'two_factor');
                    logSecurityEvent(
                        $conn,
                        $used_recovery_code
                            ? 'password_recovery_code_verified'
                            : 'password_recovery_totp_verified',
                        (int) $user['id'],
                        (int) $user['id']
                    );
                    markPasswordRecoveryVerified();
                    header('Location: recover_password.php');
                    exit();
                }

                if ($user && empty($user['two_factor_is_locked'])) {
                    recordAuthenticationFailure($conn, (int) $user['id'], 'two_factor');
                    logSecurityEvent($conn, 'password_recovery_factor_failed', (int) $user['id']);
                }

                $_SESSION['_password_recovery']['attempts'] =
                    (int) ($_SESSION['_password_recovery']['attempts'] ?? 0) + 1;
                if ($_SESSION['_password_recovery']['attempts'] >= 5) {
                    clearPasswordRecovery();
                    $error = 'Recovery is temporarily unavailable. Wait 15 minutes and try again.';
                } else {
                    $error = 'The authentication or recovery code was not accepted.';
                }
            } catch (Throwable $exception) {
                error_log('Password recovery verification error: ' . $exception->getMessage());
                $error = 'Password recovery is temporarily unavailable.';
            }
        }
    }

    if ($action === 'reset') {
        $recovery = getPasswordRecovery('reset');
        $new_password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmation = is_string($_POST['new_password_confirmation'] ?? null)
            ? $_POST['new_password_confirmation']
            : '';
        $user = $recovery && !empty($recovery['user_id'])
            ? fetchAuthenticationUserById($conn, (int) $recovery['user_id'])
            : null;

        if (!$recovery
            || !$user
            || (int) $user['auth_version'] !== (int) $recovery['auth_version']
            || empty($user['two_factor_enabled'])
        ) {
            clearPasswordRecovery();
            $error = 'The recovery request expired or the account changed. Start again.';
        } elseif (strlen($new_password) < 12) {
            $error = 'The new password must contain at least 12 characters.';
        } elseif (!hash_equals($new_password, $confirmation)) {
            $error = 'The new passwords do not match.';
        } elseif (password_verify($new_password, $user['password'])) {
            $error = 'The new password must be different from the current password.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id = (int) $user['id'];
            $expected_auth_version = (int) $recovery['auth_version'];
            $stmt = $conn->prepare(
                'UPDATE users
                 SET password = ?,
                     auth_version = auth_version + 1,
                     must_change_password = 0,
                     login_failed_attempts = 0,
                     login_locked_until = NULL,
                     two_factor_failed_attempts = 0,
                     two_factor_locked_until = NULL
                 WHERE id = ? AND auth_version = ?'
            );
            $stmt->bind_param('sii', $password_hash, $user_id, $expected_auth_version);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                clearPasswordRecovery();
                $error = 'The account changed during recovery. Start again.';
            } else {
                logSecurityEvent($conn, 'password_recovered', $user_id, $user_id);
                clearPasswordRecovery();
                $user = fetchAuthenticationUserById($conn, $user_id);
                completeAuthentication($conn, $user, true);
                header('Location: two_factor_settings.php?password_recovered=1');
                exit();
            }
        }
    }
}

$recovery = getPasswordRecovery();
$stage = $recovery['stage'] ?? 'start';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Recover Password - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.1">
    <script>
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') document.documentElement.classList.add('dark-mode');
    </script>
</head>
<body class="fullscreen-center">
    <div class="login-container recovery-container">
        <h1>Recover Password</h1>

        <?php if (isset($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <?php if ($stage === 'start'): ?>
            <p class="login-help">
                Recovery requires an account with two-factor authentication enabled.
            </p>
            <form method="post" action="recover_password.php">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="start">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" autocomplete="username" required autofocus>
                </div>
                <button type="submit" class="login-button">Continue</button>
            </form>
            <p class="login-help recovery-help">
                If 2FA is not enabled or you lost all recovery methods, contact an administrator.
            </p>
            <p class="login-secondary-link"><a href="login.php">Back to login</a></p>
        <?php elseif ($stage === 'verify'): ?>
            <p class="login-help">
                Enter the six-digit code from your authenticator app or one unused recovery code.
            </p>
            <form method="post" action="recover_password.php">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="verify">
                <div class="form-group">
                    <label for="authentication_code">Authentication code</label>
                    <input type="text" name="authentication_code" id="authentication_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required autofocus>
                </div>
                <button type="submit" class="login-button">Verify identity</button>
            </form>
            <form method="post" action="recover_password.php" class="login-cancel-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="login-cancel-button">Cancel recovery</button>
            </form>
        <?php else: ?>
            <p class="login-help">Choose a new password containing at least 12 characters.</p>
            <form method="post" action="recover_password.php">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="reset">
                <div class="form-group">
                    <label for="new_password">New password</label>
                    <input type="password" name="new_password" id="new_password" autocomplete="new-password" minlength="12" required autofocus>
                </div>
                <div class="form-group">
                    <label for="new_password_confirmation">Confirm new password</label>
                    <input type="password" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" minlength="12" required>
                </div>
                <button type="submit" class="login-button">Reset password</button>
            </form>
            <form method="post" action="recover_password.php" class="login-cancel-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="login-cancel-button">Cancel recovery</button>
            </form>
        <?php endif; ?>
    </div>
    <script src="assets/js/theme.js"></script>
</body>
</html>
