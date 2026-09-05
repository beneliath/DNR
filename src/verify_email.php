<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/account_email_change_helpers.php';
startSecureSession();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$token = is_string($_POST['token'] ?? null)
    ? $_POST['token']
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$verification = findUserEmailToken($conn, 'verification', $token);
if (!$verification || $verification['account_status'] !== 'active') {
    $error = 'This verification link is invalid, expired, or already used.';
    $verification = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $verification) {
    requireValidCsrfToken();
    try {
        $email_changed = verifyAccountEmail($conn, $token);
        $verified = true;
        $verification = null;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Email verification failed', ['error' => $exception->getMessage()]);
        $error = 'The email address could not be verified.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Verify Email'), [
    'styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css'],
    'scripts' => [['path' => 'assets/js/theme-init.min.js', 'defer' => false]],
]); ?>
<body class="fullscreen-center">
<div class="login-container recovery-container" role="main">
    <div class="auth-brand"><strong><?php echo htmlspecialchars(applicationBrandName(), ENT_QUOTES, 'UTF-8'); ?><?php if (applicationBrandNativeName() !== ''): ?> <bdi dir="auto"><?php echo htmlspecialchars(applicationBrandNativeName(), ENT_QUOTES, 'UTF-8'); ?></bdi><?php endif; ?></strong></div>
    <h1>Verify Email</h1>
    <?php if (isset($verified)): ?>
        <p class="success">Your email address is verified and can now be used for password recovery. If you changed your address, sign in again; all previous sessions have been revoked.</p>
    <?php elseif (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($verification): ?>
        <p class="login-help">Confirm <?php echo htmlspecialchars($verification['token_email'], ENT_QUOTES, 'UTF-8'); ?> as the recovery address for <?php echo htmlspecialchars($verification['username'], ENT_QUOTES, 'UTF-8'); ?>.</p>
        <form method="post" action="verify_email.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="login-button">Verify Email Address</button>
        </form>
    <?php endif; ?>
    <p class="login-secondary-link"><a href="<?php echo isLoggedIn() ? 'profile.php' : 'login.php'; ?>">Continue</a></p>
</div>
<?php renderScript('assets/js/theme.min.js', false); ?>
</body>
</html>
