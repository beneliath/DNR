<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
startSecureSession();
requireLogin();

$codes = $_SESSION['_new_recovery_codes'] ?? null;
unset($_SESSION['_new_recovery_codes']);

if (!is_array($codes) || !$codes) {
    header('Location: two_factor_settings.php');
    exit();
}

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Two-Factor Recovery Codes - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.2.2">
</head>
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
    <p><a href="two_factor_settings.php" class="security-button">I saved these codes</a></p>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
