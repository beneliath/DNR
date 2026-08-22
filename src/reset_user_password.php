<?php
require_once __DIR__ . '/bootstrap.php';
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

requireRecentAdminElevation('reset_user_password.php?id=' . $target_user_id);

$target_user = fetchAuthenticationUserById($conn, $target_user_id);

if (!$target_user) {
    header('Location: users.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $new_password = is_string($_POST['new_password'] ?? null)
        ? $_POST['new_password']
        : '';
    $confirmation = is_string($_POST['new_password_confirmation'] ?? null)
        ? $_POST['new_password_confirmation']
        : '';

    $password_error = \Dnr\Security\PasswordPolicy::validationError(
        $new_password,
        'The temporary password'
    );
    if ($password_error !== null) {
        $error = $password_error;
    } elseif (!hash_equals($new_password, $confirmation)) {
        $error = 'The temporary passwords do not match.';
    } elseif (\Dnr\Security\PasswordPolicy::verify($new_password, $target_user['password'])) {
        $error = 'The temporary password must be different from the target user’s current password.';
    } else {
        try {
            $password_hash = \Dnr\Security\PasswordPolicy::hash($new_password);
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
        } catch (Throwable $exception) {
            applicationLog('error', 'Administrator password reset failed unexpectedly', ['error' => $exception->getMessage()]);
            $error = 'The user’s password could not be reset.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Reset User Password - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
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
        <p>Your fresh administrator elevation is active. The target user’s two-factor authentication status will not be changed.</p>

        <form method="post" action="reset_user_password.php?id=<?php echo $target_user_id; ?>" class="security-form">
            <?php echo csrfInput(); ?>

            <label for="new_password">Temporary password</label>
            <input type="password" name="new_password" id="new_password" autocomplete="new-password" minlength="12" maxlength="72" required>

            <label for="new_password_confirmation">Confirm temporary password</label>
            <input type="password" name="new_password_confirmation" id="new_password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required>

            <button type="submit" class="security-button">Set temporary password</button>
            <a href="users.php" class="danger-button cancel-button">Cancel</a>
        </form>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
