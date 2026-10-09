<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
$json_request = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
if ($json_request) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$return_url = safeAdminElevationReturnUrl($_POST['return'] ?? $_GET['return'] ?? 'dashboard.php', 'dashboard.php');
if (!$json_request && $_SERVER['REQUEST_METHOD'] === 'GET' && hasRecentAdminElevation()) {
    header('Location: ' . $return_url);
    exit();
}
$error = $_SESSION['_admin_elevation_error'] ?? '';
unset($_SESSION['_admin_elevation_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $password = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
    $code = is_string($_POST['admin_code'] ?? null) ? $_POST['admin_code'] : '';
    if (attemptAdminElevation($conn, $password, $code)) {
        $error = '';
        if (!$json_request) {
            header('Location: ' . $return_url);
            exit();
        }
    } else {
        $error = 'Your administrator password or fresh authentication code was not accepted.';
        if ($json_request) http_response_code(422);
    }
}
if ($json_request) {
    $status = ['unlocked' => hasRecentAdminElevation(), 'expires_at' => adminElevationExpiresAt(),
        'server_now' => microtime(true), 'csrf_token' => generateCsrfToken(), 'error' => $error];
    releaseApplicationSessionLock();
    echo json_encode($status);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Confirm Administrator Access'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/admin_elevation.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container security-container admin-elevation-container">
    <h1>Confirm Administrator Access</h1>
    <?php if ($error !== ''): ?><p class="error admin-elevation-notice"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <section class="security-card">
        <p>Enter your current password and a fresh authenticator or recovery code. Sensitive administrator actions remain unlocked for five minutes.</p>
        <form method="post" action="admin_elevation.php" class="security-form" autocomplete="off">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="admin_password">Administrator Password</label>
            <input type="password" name="admin_password" id="admin_password" autocomplete="current-password" maxlength="72" required autofocus>
            <label for="admin_code">Fresh Authenticator Code or Recovery Code</label>
            <input type="text" name="admin_code" id="admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>
            <div class="action-buttons create-form-actions">
                <a href="<?php echo htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Cancel</a>
                <button type="submit" class="security-button">Unlock Sensitive Actions</button>
            </div>
        </form>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
