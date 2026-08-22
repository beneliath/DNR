<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireLogin();
requireTwoFactorSchema($conn);

$user_id = (int) $_SESSION['user_id'];
$user = fetchAuthenticationUserById($conn, $user_id);
if (!$user) {
    session_unset();
    header('Location: login.php');
    exit();
}
$must_change_password = !empty($user['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $password = $_POST['password'] ?? '';
    $current_code = trim($_POST['current_code'] ?? '');

    if ($must_change_password && $action !== 'change_password') {
        $error = 'You must replace the temporary password before making other security changes.';
    } elseif ($action === 'change_password') {
        $new_password = $_POST['new_password'] ?? '';
        $new_password_confirmation = $_POST['new_password_confirmation'] ?? '';

        if (!password_verify($password, $user['password'])) {
            $error = 'The current password was not accepted.';
        } elseif (!empty($user['two_factor_enabled']) && !hasRecentTwoFactorVerification()) {
            $error = 'Sign in with two-factor authentication again before changing the password.';
        } elseif (strlen($new_password) < 12) {
            $error = 'The new password must contain at least 12 characters.';
        } elseif (!hash_equals($new_password, $new_password_confirmation)) {
            $error = 'The new passwords do not match.';
        } elseif (hash_equals($password, $new_password)) {
            $error = 'The new password must be different from the current password.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'UPDATE users
                 SET password = ?, auth_version = auth_version + 1, must_change_password = 0
                 WHERE id = ?'
            );
            $stmt->bind_param('si', $password_hash, $user_id);
            $stmt->execute();
            $user = fetchAuthenticationUserById($conn, $user_id);
            $_SESSION['auth_version'] = (int) $user['auth_version'];
            $_SESSION['must_change_password'] = false;
            logSecurityEvent($conn, 'password_changed', $user_id, $user_id);
            header('Location: two_factor_settings.php?password_changed=1');
            exit();
        }
    } elseif (empty($user['two_factor_enabled'])) {
        $error = 'Two-factor authentication is not enabled.';
    } elseif (!password_verify($password, $user['password'])) {
        $error = 'The password or authentication code was not accepted.';
    } elseif (!empty($user['two_factor_is_locked'])) {
        $error = 'Two-factor verification is temporarily locked. Try again later.';
    } else {
        try {
            if (!verifyAndConsumeTotp($conn, $user, $current_code)) {
                recordAuthenticationFailure($conn, $user_id, 'two_factor');
                $error = 'The password or authentication code was not accepted.';
            } elseif ($action === 'regenerate_codes') {
                $codes = generateRecoveryCodes();
                $conn->begin_transaction();
                try {
                    replaceRecoveryCodes($conn, $user_id, $codes);
                    $conn->commit();
                } catch (Throwable $exception) {
                    $conn->rollback();
                    throw $exception;
                }
                logSecurityEvent($conn, 'recovery_codes_regenerated', $user_id, $user_id);
                $_SESSION['_new_recovery_codes'] = $codes;
                $_SESSION['two_factor_verified_at'] = time();
                header('Location: two_factor_recovery_codes.php');
                exit();
            } elseif ($action === 'disable' && !twoFactorRequiredForRole($user['role'])) {
                disableTwoFactorForUser($conn, $user_id);
                logSecurityEvent($conn, 'two_factor_disabled', $user_id, $user_id);
                $user = fetchAuthenticationUserById($conn, $user_id);
                $_SESSION['auth_version'] = (int) $user['auth_version'];
                $_SESSION['two_factor_verified_at'] = null;
                header('Location: two_factor_settings.php?disabled=1');
                exit();
            } else {
                $error = 'That security action is not permitted.';
            }
        } catch (Throwable $exception) {
            applicationLog('error', 'Two-factor settings failed unexpectedly', ['error' => $exception->getMessage()]);
            $error = 'The security change could not be completed.';
        }
    }
}

$user = fetchAuthenticationUserById($conn, $user_id);
$must_change_password = !empty($user['must_change_password']);
$remaining_codes = !empty($user['two_factor_enabled'])
    ? countUnusedRecoveryCodes($conn, $user_id)
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Account Security - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body class="two-factor-settings-page">
<?php include 'templates/header.php'; ?>
<main class="container security-container">
    <div class="page-heading"><div><h1>Account Security</h1><p class="page-intro">Manage your password, authenticator, and recovery options.</p></div></div>

    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php elseif ($must_change_password): ?>
        <p class="error">An administrator issued a temporary password for this account. Replace it before continuing.</p>
    <?php elseif (isset($_GET['password_recovered'])): ?>
        <p class="success">Your password was reset, other sessions were signed out, and you are now signed in.</p>
    <?php elseif (isset($_GET['password_changed'])): ?>
        <p class="success">Your password was changed and your other sessions were signed out.</p>
    <?php elseif (isset($_GET['disabled'])): ?>
        <p class="success">Two-factor authentication was disabled.</p>
    <?php endif; ?>

    <?php if (!$must_change_password): ?>
    <section class="security-card">
        <h2>Two-Factor Authentication</h2>
        <?php if (empty($user['two_factor_enabled'])): ?>
            <p><strong>Status:</strong> Not enabled</p>
            <?php if (twoFactorRequiredForRole($user['role'])): ?>
                <p>Two-factor authentication is required for administrators.</p>
            <?php endif; ?>
            <p><a href="setup_2fa.php" class="security-button">Set up 2FA</a></p>
        <?php else: ?>
            <p><strong>Status:</strong> Enabled</p>
            <p><strong>Enrolled:</strong> <?php echo htmlspecialchars($user['totp_confirmed_at'] ?? 'Unknown'); ?></p>
            <p><strong>Unused recovery codes:</strong> <?php echo $remaining_codes; ?></p>
            <p><a href="setup_2fa.php" class="security-button">Replace authenticator</a></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="security-card">
        <h2>Change Password</h2>
        <p>Changing your password signs this account out on every other device.</p>
        <form method="post" action="two_factor_settings.php" class="security-form">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="change_password">
            <label for="password_change_current">Current password</label>
            <input type="password" name="password" id="password_change_current" autocomplete="current-password" required>
            <label for="password_change_new">New password</label>
            <input type="password" name="new_password" id="password_change_new" autocomplete="new-password" minlength="12" required>
            <label for="password_change_confirmation">Confirm new password</label>
            <input type="password" name="new_password_confirmation" id="password_change_confirmation" autocomplete="new-password" minlength="12" required>
            <button type="submit" class="security-button">Change password</button>
        </form>
    </section>

    <?php if (!$must_change_password && !empty($user['two_factor_enabled'])): ?>
        <section class="security-card">
            <h2>Generate New Recovery Codes</h2>
            <p>This invalidates every existing recovery code.</p>
            <form method="post" action="two_factor_settings.php" class="security-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="regenerate_codes">
                <label for="recovery_password">Current password</label>
                <input type="password" name="password" id="recovery_password" autocomplete="current-password" required>
                <label for="recovery_current_code">Current authenticator code</label>
                <input type="text" name="current_code" id="recovery_current_code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                <button type="submit" class="security-button">Generate new codes</button>
            </form>
        </section>

        <?php if (!twoFactorRequiredForRole($user['role'])): ?>
            <section class="security-card danger-card">
                <h2>Disable Two-Factor Authentication</h2>
                <p>This makes the account less secure.</p>
                <form method="post" action="two_factor_settings.php" class="security-form" data-confirm="Disable two-factor authentication for this account?">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="disable">
                    <label for="disable_password">Current password</label>
                    <input type="password" name="password" id="disable_password" autocomplete="current-password" required>
                    <label for="disable_current_code">Current authenticator code</label>
                    <input type="text" name="current_code" id="disable_current_code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    <button type="submit" class="danger-button">Disable 2FA</button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
