<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
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
    $conn->begin_transaction();
    try {
        $verification = findUserEmailToken($conn, 'verification', $token, true);
        if (!$verification
            || $verification['account_status'] !== 'active'
            || !hash_equals(
                normalizeAccountEmail($verification['email']),
                normalizeAccountEmail($verification['token_email'])
            )
        ) {
            throw new InvalidArgumentException('The account email changed after this link was issued.');
        }
        if (emailAddressBelongsToAnotherUser(
            $conn,
            $verification['token_email'],
            (int) $verification['user_id']
        )) {
            throw new InvalidArgumentException('That email address belongs to another account.');
        }
        $user_id = (int) $verification['user_id'];
        $update = $conn->prepare(
            "UPDATE users SET email_verified_at = UTC_TIMESTAMP()
             WHERE id = ? AND account_status = 'active'"
        );
        $update->bind_param('i', $user_id);
        $update->execute();
        $update->close();
        if (!consumeUserEmailToken($conn, (int) $verification['token_id'])) {
            throw new RuntimeException('Unable to consume the email verification token.');
        }
        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'email_verified',
            'target_user_id' => $user_id,
            'target_username' => (string) $verification['username'],
            'entity_type' => 'users',
            'entity_id' => $user_id,
            'entity_label' => (string) $verification['username'],
            'details' => 'Verified ' . (string) $verification['token_email'],
        ])) {
            throw new RuntimeException('Unable to audit email verification.');
        }
        $conn->commit();
        $verified = true;
        $verification = null;
    } catch (InvalidArgumentException $exception) {
        $conn->rollback();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $conn->rollback();
        applicationLog('error', 'Email verification failed', ['error' => $exception->getMessage()]);
        $error = 'The email address could not be verified.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Verify Email - DNR', [
    'styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css'],
    'scripts' => [['path' => 'assets/js/theme-init.min.js', 'defer' => false]],
]); ?>
<body class="fullscreen-center">
<div class="login-container recovery-container">
    <div class="auth-brand"><strong>MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi></strong></div>
    <h1>Verify Email</h1>
    <?php if (isset($verified)): ?>
        <p class="success">Your email address is verified and can now be used for password recovery.</p>
    <?php elseif (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($verification): ?>
        <p class="login-help">Confirm <?php echo htmlspecialchars($verification['token_email'], ENT_QUOTES, 'UTF-8'); ?> as the recovery address for <?php echo htmlspecialchars($verification['username'], ENT_QUOTES, 'UTF-8'); ?>.</p>
        <form method="post" action="verify_email.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="login-button">Verify email address</button>
        </form>
    <?php endif; ?>
    <p class="login-secondary-link"><a href="<?php echo isLoggedIn() ? 'profile.php' : 'login.php'; ?>">Continue</a></p>
</div>
<?php renderScript('assets/js/theme.min.js', false); ?>
</body>
</html>
