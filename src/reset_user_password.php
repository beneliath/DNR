<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$target_user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$actor_user_id = (int) $_SESSION['user_id'];

if (!$target_user_id || $target_user_id === $actor_user_id) {
    header('Location: users.php');
    exit();
}

$target_user = fetchAuthenticationUserById($conn, $target_user_id);
$actor_user = fetchAuthenticationUserById($conn, $actor_user_id);

if (!$target_user || !$actor_user) {
    header('Location: users.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $admin_password = is_string($_POST['admin_password'] ?? null)
        ? $_POST['admin_password']
        : '';
    $admin_code_input = is_string($_POST['admin_code'] ?? null)
        ? $_POST['admin_code']
        : '';
    $admin_code = trim($admin_code_input);
    $new_password = is_string($_POST['new_password'] ?? null)
        ? $_POST['new_password']
        : '';
    $confirmation = is_string($_POST['new_password_confirmation'] ?? null)
        ? $_POST['new_password_confirmation']
        : '';

    if (strlen($new_password) < 12) {
        $error = 'The temporary password must contain at least 12 characters.';
    } elseif (!hash_equals($new_password, $confirmation)) {
        $error = 'The temporary passwords do not match.';
    } elseif (password_verify($new_password, $target_user['password'])) {
        $error = 'The temporary password must be different from the target user’s current password.';
    } elseif (!empty($actor_user['login_is_locked'])
        || !password_verify($admin_password, $actor_user['password'])
    ) {
        if (empty($actor_user['login_is_locked'])) {
            recordAuthenticationFailure($conn, $actor_user_id, 'password');
        }
        logSecurityEvent($conn, 'admin_password_reset_auth_failed', $target_user_id, $actor_user_id);
        $error = 'Your administrator password or authentication code was not accepted.';
    } elseif (empty($actor_user['two_factor_enabled'])
        || !empty($actor_user['two_factor_is_locked'])
    ) {
        $error = 'Administrator two-factor verification is temporarily unavailable.';
    } else {
        resetAuthenticationFailures($conn, $actor_user_id, 'password');

        try {
            $is_totp_code = preg_match('/^[0-9]{6}$/', $admin_code) === 1;
            $factor_verified = $is_totp_code
                ? verifyAndConsumeTotp($conn, $actor_user, $admin_code)
                : consumeRecoveryCode($conn, $actor_user_id, $admin_code);

            if (!$factor_verified) {
                recordAuthenticationFailure($conn, $actor_user_id, 'two_factor');
                logSecurityEvent($conn, 'admin_password_reset_auth_failed', $target_user_id, $actor_user_id);
                $error = 'Your administrator password or authentication code was not accepted.';
            } else {
                resetAuthenticationFailures($conn, $actor_user_id, 'two_factor');
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    'UPDATE users
                     SET password = ?,
                         must_change_password = 1,
                         auth_version = auth_version + 1,
                         login_failed_attempts = 0,
                         login_locked_until = NULL,
                         two_factor_failed_attempts = 0,
                         two_factor_locked_until = NULL
                     WHERE id = ? AND id <> ?'
                );
                $stmt->bind_param('sii', $password_hash, $target_user_id, $actor_user_id);
                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    $error = 'The user’s password could not be reset.';
                } else {
                    logSecurityEvent($conn, 'admin_password_reset', $target_user_id, $actor_user_id);
                    header('Location: users.php?password_reset=1');
                    exit();
                }
            }
        } catch (Throwable $exception) {
            error_log('Administrator password reset error: ' . $exception->getMessage());
            $error = 'The user’s password could not be reset.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset User Password - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.11">
</head>
<body>
<?php include 'templates/header.php'; ?>
<main class="container security-container">
    <h1>Reset User Password</h1>

    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <section class="security-card danger-card">
        <h2><?php echo htmlspecialchars($target_user['username']); ?></h2>
        <p>
            Set a temporary password for this user. Their existing sessions will be invalidated,
            and they must replace the temporary password after signing in.
        </p>
        <p>The target user’s two-factor authentication status will not be changed.</p>

        <form method="post" action="reset_user_password.php?id=<?php echo $target_user_id; ?>" class="security-form">
            <?php echo csrfInput(); ?>

            <label for="new_password">Temporary password</label>
            <input type="password" name="new_password" id="new_password" autocomplete="new-password" minlength="12" required>

            <label for="new_password_confirmation">Confirm temporary password</label>
            <input type="password" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" minlength="12" required>

            <label for="admin_password">Your administrator password</label>
            <input type="password" name="admin_password" id="admin_password" autocomplete="current-password" required>

            <label for="admin_code">Your fresh authenticator code or recovery code</label>
            <input type="text" name="admin_code" id="admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>

            <button type="submit" class="security-button">Set temporary password</button>
            <a href="users.php" class="danger-button cancel-button">Cancel</a>
        </form>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
