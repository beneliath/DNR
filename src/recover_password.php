<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/email_helpers.php';
startSecureSession();
requireTwoFactorSchema($conn);
requireLoginRateLimitSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

if (isLoggedIn()) {
    header('Location: two_factor_settings.php');
    exit();
}

$token = is_string($_POST['token'] ?? null)
    ? $_POST['token']
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$recovery = $token !== '' ? findUserEmailToken($conn, 'recovery', $token) : null;
if ($token !== '' && (!$recovery
    || $recovery['account_status'] !== 'active'
    || empty($recovery['email_verified_at'])
)) {
    $token_error = 'This recovery link is invalid, expired, or already used.';
    $recovery = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'request') {
        $email_input = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $email = strtolower(trim($email_input));
        if (authenticationSourceIsBlocked($conn, 'recovery')) {
            http_response_code(429);
            header('Retry-After: 1800');
        } else {
            $rate_limits = recordAuthenticationRateLimitFailure($conn, 'recovery', $email);
            $rate_limited = !empty($rate_limits['recovery_ip']['blocked'])
                || !empty($rate_limits['recovery_account']['blocked']);
            $user = null;
            if (!$rate_limited && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stmt = $conn->prepare(
                    "SELECT id, username, email FROM users
                     WHERE verified_email = LOWER(?) AND account_status = 'active'
                     LIMIT 1"
                );
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            if ($user) {
                try {
                    $issued = issueUserEmailToken(
                        $conn,
                        (int) $user['id'],
                        'recovery',
                        (string) $user['email'],
                        null
                    );
                    sendPasswordRecoveryEmail(
                        (string) $user['email'],
                        (string) $user['username'],
                        $issued['token']
                    );
                    logSecurityEvent($conn, 'password_recovery_email_sent', (int) $user['id']);
                } catch (Throwable $exception) {
                    applicationLog('error', 'Password recovery email delivery failed', [
                        'target_user_id' => (int) $user['id'],
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
        $requested = true;
        $recovery = null;
        $token = '';
    } elseif ($action === 'reset') {
        $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmation = is_string($_POST['new_password_confirmation'] ?? null)
            ? $_POST['new_password_confirmation']
            : '';
        $password_error = \Dnr\Security\PasswordPolicy::validationError($password, 'The new password');
        if (!$recovery) {
            $token_error = 'This recovery link is invalid, expired, or already used.';
        } elseif ($password_error !== null) {
            $error = $password_error;
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'The new passwords do not match.';
        } elseif (\Dnr\Security\PasswordPolicy::verify($password, $recovery['password'])) {
            $error = 'The new password must be different from the current password.';
        } else {
            $conn->begin_transaction();
            try {
                $recovery = findUserEmailToken($conn, 'recovery', $token, true);
                if (!$recovery
                    || $recovery['account_status'] !== 'active'
                    || empty($recovery['email_verified_at'])
                    || !hash_equals(
                        normalizeAccountEmail($recovery['email']),
                        normalizeAccountEmail($recovery['token_email'])
                    )
                ) {
                    throw new InvalidArgumentException('The account changed after this recovery link was issued.');
                }
                $password_hash = \Dnr\Security\PasswordPolicy::hash($password);
                $user_id = (int) $recovery['user_id'];
                $update = $conn->prepare(
                    "UPDATE users
                     SET password = ?, auth_version = auth_version + 1,
                         must_change_password = 0,
                         login_failed_attempts = 0, login_locked_until = NULL,
                         two_factor_failed_attempts = 0, two_factor_locked_until = NULL
                     WHERE id = ? AND account_status = 'active'"
                );
                $update->bind_param('si', $password_hash, $user_id);
                $update->execute();
                if ($update->affected_rows !== 1
                    || !consumeUserEmailToken($conn, (int) $recovery['token_id'])
                ) {
                    throw new RuntimeException('Unable to reset the password.');
                }
                $update->close();
                $consume_others = $conn->prepare(
                    "UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND purpose = 'recovery' AND consumed_at IS NULL"
                );
                $consume_others->bind_param('i', $user_id);
                $consume_others->execute();
                $consume_others->close();
                if (!recordAuditEvent($conn, [
                    'event_category' => 'security',
                    'event_type' => 'password_recovered_by_email',
                    'target_user_id' => $user_id,
                    'target_username' => (string) $recovery['username'],
                    'entity_type' => 'users',
                    'entity_id' => $user_id,
                    'entity_label' => (string) $recovery['username'],
                    'details' => 'All existing sessions invalidated',
                ])) {
                    throw new RuntimeException('Unable to audit password recovery.');
                }
                $conn->commit();
                clearAuthenticationRateLimits($conn, 'recovery', (string) $recovery['token_email']);
                session_unset();
                session_regenerate_id(true);
                header('Location: login.php?password_recovered=1');
                exit();
            } catch (InvalidArgumentException $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                $conn->rollback();
                applicationLog('error', 'Email password recovery failed', ['error' => $exception->getMessage()]);
                $error = 'The password could not be reset. Request a new recovery link.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Recover Password - DNR', [
    'styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css'],
    'scripts' => [['path' => 'assets/js/theme-init.min.js', 'defer' => false]],
]); ?>
<body class="fullscreen-center">
<button type="button" class="mobile-theme-button auth-theme-toggle" data-theme-toggle aria-label="Switch to dark theme">
    <svg class="theme-icon-light" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
    <svg class="theme-icon-dark" aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
</button>
<div class="login-container recovery-container">
    <div class="auth-brand"><strong>MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi></strong></div>
    <h1>Recover Password</h1>
    <?php if (isset($requested)): ?>
        <p class="success">If an active account has that verified email address, a single-use recovery link has been sent.</p>
    <?php elseif (isset($token_error)): ?>
        <p class="error"><?php echo htmlspecialchars($token_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($recovery): ?>
        <p class="login-help">Choose a new password for <?php echo htmlspecialchars($recovery['username'], ENT_QUOTES, 'UTF-8'); ?>. This will sign out every existing session.</p>
        <form method="post" action="recover_password.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group"><label for="new_password">New password</label><input type="password" name="new_password" id="new_password" autocomplete="new-password" minlength="12" maxlength="72" required autofocus></div>
            <div class="form-group"><label for="new_password_confirmation">Confirm new password</label><input type="password" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required></div>
            <button type="submit" class="login-button">Reset password</button>
        </form>
    <?php else: ?>
        <?php if (!isset($requested)): ?><p class="login-help">Enter the verified email address on your active account.</p><?php endif; ?>
        <form method="post" action="recover_password.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="request">
            <div class="form-group"><label for="email">Email address</label><input type="email" name="email" id="email" maxlength="254" autocomplete="email" required autofocus></div>
            <button type="submit" class="login-button">Send recovery link</button>
        </form>
    <?php endif; ?>
    <p class="login-secondary-link"><a href="login.php">Back to login</a></p>
</div>
<?php renderScript('assets/js/theme.min.js', false); ?>
</body>
</html>
