<?php
require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireLogin();

$codes = $_SESSION['_new_recovery_codes'] ?? null;
$initial_login = !empty($_SESSION['_new_recovery_codes_initial_login']);
unset($_SESSION['_new_recovery_codes'], $_SESSION['_new_recovery_codes_initial_login']);

if (!is_array($codes) || !$codes) {
    header('Location: two_factor_settings.php');
    exit();
}

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
$destination = twoFactorRecoveryCodesDestination($initial_login);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Two-Factor Recovery Codes - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container security-container">
    <h1>Save Your Recovery Codes</h1>
    <p class="success">Two-factor authentication is enabled.</p>
    <p>Store these codes somewhere safe. Each code can be used once if your authenticator is unavailable. They will not be shown again.</p>
    <div class="recovery-code-card">
        <?php foreach ($codes as $code): ?>
            <code><?php echo htmlspecialchars($code); ?></code>
        <?php endforeach; ?>
    </div>
    <p><a href="<?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>" class="security-button save-button">I saved these codes</a></p>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
