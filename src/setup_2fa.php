<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireTwoFactorSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$pending = getPendingAuthentication();
$is_pending_login = $pending !== null;

if ($is_pending_login) {
    $user_id = (int) $pending['user_id'];
} elseif (isLoggedIn()) {
    $user_id = (int) $_SESSION['user_id'];
} else {
    header('Location: login.php');
    exit();
}

$user = fetchAuthenticationUserById($conn, $user_id);
if (!$user) {
    session_unset();
    header('Location: login.php');
    exit();
}
setDatabaseAuditContext($conn, $user_id, (string) $user['username']);

if ($is_pending_login && !empty($user['two_factor_enabled'])) {
    header('Location: verify_2fa.php');
    exit();
}

function currentTwoFactorEnrollment($user_id) {
    $enrollment = $_SESSION['_two_factor_enrollment'] ?? null;

    if (!is_array($enrollment)
        || (int) ($enrollment['user_id'] ?? 0) !== $user_id
        || empty($enrollment['secret'])
        || !isset($enrollment['issued_at'])
        || (time() - (int) $enrollment['issued_at']) > 600
    ) {
        unset($_SESSION['_two_factor_enrollment']);
        return null;
    }

    return $enrollment;
}

function beginTwoFactorEnrollment($user_id, $mode) {
    $_SESSION['_two_factor_enrollment'] = [
        'user_id' => $user_id,
        'secret' => generateTotpSecret(),
        'mode' => $mode,
        'issued_at' => time(),
    ];
}

if ($is_pending_login && currentTwoFactorEnrollment($user_id) === null) {
    try {
        // Fail before showing a QR code if the deployment encryption key is missing.
        twoFactorEncryptionKey();
        beginTwoFactorEnrollment($user_id, 'enroll');
    } catch (Throwable $exception) {
        error_log('Two-factor enrollment configuration error: ' . $exception->getMessage());
        $error = 'Two-factor enrollment is not configured on this server. Contact an administrator.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel') {
        unset($_SESSION['_two_factor_enrollment']);
        if ($is_pending_login) {
            unset($_SESSION['_pending_auth']);
            header('Location: login.php');
        } else {
            header('Location: two_factor_settings.php');
        }
        exit();
    }

    if ($action === 'start' && !$is_pending_login) {
        $password = $_POST['password'] ?? '';
        $current_code = trim($_POST['current_code'] ?? '');

        if (!password_verify($password, $user['password'])) {
            $error = 'The password or authentication code was not accepted.';
        } elseif (!empty($user['two_factor_enabled'])) {
            try {
                if (!empty($user['two_factor_is_locked'])
                    || !verifyAndConsumeTotp($conn, $user, $current_code)
                ) {
                    recordAuthenticationFailure($conn, $user_id, 'two_factor');
                    $error = 'The password or authentication code was not accepted.';
                }
            } catch (Throwable $exception) {
                error_log('Two-factor replacement verification error: ' . $exception->getMessage());
                $error = 'Two-factor verification is temporarily unavailable.';
            }
        }

        if (!isset($error)) {
            try {
                twoFactorEncryptionKey();
                beginTwoFactorEnrollment(
                    $user_id,
                    !empty($user['two_factor_enabled']) ? 'replace' : 'enroll'
                );
            } catch (Throwable $exception) {
                error_log('Two-factor enrollment configuration error: ' . $exception->getMessage());
                $error = 'Two-factor enrollment is not configured on this server.';
            }
        }
    }

    if ($action === 'confirm') {
        $enrollment = currentTwoFactorEnrollment($user_id);
        $code = trim($_POST['authentication_code'] ?? '');

        if (!$enrollment) {
            $error = 'The enrollment session expired. Start again.';
        } else {
            $step = matchingTotpStep(
                $enrollment['secret'],
                $user['username'],
                $code
            );

            if ($step === null) {
                if ($is_pending_login) {
                    recordFailedLoginAttempt(
                        $conn,
                        (string) $user['username'],
                        'Incorrect two-factor enrollment code',
                        $user
                    );
                }
                $error = 'That code was not accepted. Check the device time and try again.';
            } else {
                try {
                    $codes = enableTwoFactorForUser(
                        $conn,
                        $user_id,
                        $enrollment['secret'],
                        $step
                    );
                    logSecurityEvent(
                        $conn,
                        $enrollment['mode'] === 'replace' ? 'two_factor_replaced' : 'two_factor_enabled',
                        $user_id,
                        $user_id
                    );

                    $user = fetchAuthenticationUserById($conn, $user_id);

                    if ($is_pending_login) {
                        completeAuthentication($conn, $user, true);
                    } else {
                        $_SESSION['auth_version'] = (int) $user['auth_version'];
                        $_SESSION['two_factor_verified_at'] = time();
                        unset($_SESSION['_two_factor_enrollment']);
                    }

                    $_SESSION['_new_recovery_codes'] = $codes;
                    $_SESSION['_new_recovery_codes_initial_login'] = $is_pending_login;
                    header('Location: two_factor_recovery_codes.php');
                    exit();
                } catch (Throwable $exception) {
                    error_log('Unable to enable two-factor authentication: ' . $exception->getMessage());
                    $error = 'Two-factor authentication could not be enabled.';
                }
            }
        }
    }
}

$enrollment = currentTwoFactorEnrollment($user_id);
$qr_data_uri = null;
if ($enrollment) {
    try {
        $qr_data_uri = createTotpQrDataUri($enrollment['secret'], $user['username']);
    } catch (Throwable $exception) {
        error_log('Unable to render the two-factor QR code: ' . $exception->getMessage());
        $error = 'The enrollment QR code could not be generated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set Up Two-Factor Authentication - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <link rel="stylesheet" href="assets/css/modern.min.css?v=0.1.40">
    <script>
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') document.documentElement.classList.add('dark-mode');
    </script>
</head>
<body>
<?php if (!$is_pending_login) include 'templates/header.php'; ?>
<main class="container security-container">
    <h1><?php echo !empty($user['two_factor_enabled']) ? 'Replace Authenticator' : 'Set Up Two-Factor Authentication'; ?></h1>

    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php if (!$enrollment): ?>
        <p>
            <?php echo !empty($user['two_factor_enabled'])
                ? 'Confirm your password and current authenticator code before replacing the enrolled authenticator.'
                : 'Confirm your password to begin enrolling an authenticator app.'; ?>
        </p>
        <?php if (!$is_pending_login): ?>
            <form method="post" action="setup_2fa.php" class="security-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="start">
                <label for="password">Current password</label>
                <input type="password" name="password" id="password" autocomplete="current-password" required>
                <?php if (!empty($user['two_factor_enabled'])): ?>
                    <label for="current_code">Current authenticator code</label>
                    <input type="text" name="current_code" id="current_code" autocomplete="one-time-code" inputmode="numeric" required>
                <?php endif; ?>
                <button type="submit" class="security-button">Continue</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <ol class="security-steps">
            <li>Open your authenticator app and add a new account.</li>
            <li>Scan this QR code, or enter the setup key manually.</li>
            <li>Enter the six-digit code generated by the app.</li>
        </ol>

        <?php if ($qr_data_uri): ?>
            <div class="qr-code-card">
                <img src="<?php echo htmlspecialchars($qr_data_uri, ENT_QUOTES, 'UTF-8'); ?>" alt="DNR authenticator QR code" width="280" height="280">
            </div>
        <?php endif; ?>

        <p><strong>Manual setup key:</strong></p>
        <code class="manual-secret"><?php echo htmlspecialchars($enrollment['secret']); ?></code>

        <form method="post" action="setup_2fa.php" class="security-form confirmation-form">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="confirm">
            <label for="authentication_code">Six-digit authentication code</label>
            <input type="text" name="authentication_code" id="authentication_code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
            <button type="submit" class="security-button setup-2fa-enable-button">Enable 2FA</button>
        </form>
    <?php endif; ?>

    <form method="post" action="setup_2fa.php" class="security-cancel-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="danger-button setup-2fa-cancel-button cancel-button">Cancel</button>
    </form>
</main>
<?php if (!$is_pending_login) include 'templates/footer.php'; ?>
<script src="assets/js/theme.min.js"></script>
</body>
</html>
