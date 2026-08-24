<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$return_url = safeAdminElevationReturnUrl($_POST['return'] ?? $_GET['return'] ?? 'users.php');
$error = $_SESSION['_admin_elevation_error'] ?? '';
unset($_SESSION['_admin_elevation_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $password = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
    $code = is_string($_POST['admin_code'] ?? null) ? $_POST['admin_code'] : '';
    if (attemptAdminElevation($conn, $password, $code)) {
        header('Location: ' . $return_url);
        exit();
    }
    $error = 'Your administrator password or fresh authentication code was not accepted.';
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Confirm Administrator Access - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container security-container">
    <h1>Confirm Administrator Access</h1>
    <?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <section class="security-card">
        <p>Enter your current password and a fresh authenticator or recovery code. Sensitive administrator actions remain unlocked for five minutes.</p>
        <form method="post" action="admin_elevation.php" class="security-form" autocomplete="off">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="admin_password">Administrator password</label>
            <input type="password" name="admin_password" id="admin_password" autocomplete="current-password" maxlength="72" required autofocus>
            <label for="admin_code">Fresh authenticator code or recovery code</label>
            <input type="text" name="admin_code" id="admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>
            <button type="submit" class="security-button">Unlock sensitive actions</button>
            <a href="<?php echo htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Cancel</a>
        </form>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
