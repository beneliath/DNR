<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
startSecureSession();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$token = is_string($_POST['token'] ?? null)
    ? $_POST['token']
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$invitation = findUserEmailToken($conn, 'invitation', $token);
if (!$invitation || $invitation['account_status'] !== 'invited') {
    $error = 'This invitation is invalid, expired, or already used. Ask an administrator to send a new invitation.';
    $invitation = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitation) {
    requireValidCsrfToken();
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmation = is_string($_POST['password_confirmation'] ?? null)
        ? $_POST['password_confirmation']
        : '';
    $password_error = \Dnr\Security\PasswordPolicy::validationError($password);
    if ($password_error !== null) {
        $error = $password_error;
    } elseif (!hash_equals($password, $confirmation)) {
        $error = 'Passwords do not match.';
    } else {
        $conn->begin_transaction();
        try {
            $invitation = findUserEmailToken($conn, 'invitation', $token, true);
            if (!$invitation
                || $invitation['account_status'] !== 'invited'
                || !hash_equals(
                    normalizeAccountEmail($invitation['email']),
                    normalizeAccountEmail($invitation['token_email'])
                )
            ) {
                throw new InvalidArgumentException('This invitation is no longer available.');
            }
            if (emailAddressBelongsToAnotherUser(
                $conn,
                $invitation['token_email'],
                (int) $invitation['user_id']
            )) {
                throw new InvalidArgumentException('That email address now belongs to another account.');
            }
            $password_hash = \Dnr\Security\PasswordPolicy::hash($password);
            $user_id = (int) $invitation['user_id'];
            $update = $conn->prepare(
                "UPDATE users
                 SET password = ?, email_verified_at = UTC_TIMESTAMP(),
                     account_status = 'active', activated_at = UTC_TIMESTAMP(),
                     deactivated_at = NULL, auth_version = auth_version + 1
                 WHERE id = ? AND account_status = 'invited'"
            );
            $update->bind_param('si', $password_hash, $user_id);
            $update->execute();
            if ($update->affected_rows !== 1
                || !consumeUserEmailToken($conn, (int) $invitation['token_id'])
            ) {
                throw new RuntimeException('Unable to accept the invitation.');
            }
            $update->close();
            $consume_others = $conn->prepare(
                'UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND consumed_at IS NULL'
            );
            $consume_others->bind_param('i', $user_id);
            $consume_others->execute();
            $consume_others->close();
            if (!recordAuditEvent($conn, [
                'event_category' => 'security',
                'event_type' => 'user_invitation_accepted',
                'target_user_id' => $user_id,
                'target_username' => (string) $invitation['username'],
                'entity_type' => 'users',
                'entity_id' => $user_id,
                'entity_label' => (string) $invitation['username'],
                'details' => 'Email address verified and account activated',
            ])) {
                throw new RuntimeException('Unable to audit invitation acceptance.');
            }
            $conn->commit();

            $user = fetchAuthenticationUserById($conn, $user_id);
            if (!$user) {
                throw new RuntimeException('The activated account could not be loaded.');
            }
            beginPendingAuthentication($user);
            if (!empty($user['two_factor_enabled'])) {
                header('Location: verify_2fa.php');
            } elseif (twoFactorRequiredForRole($user['role'])) {
                header('Location: setup_2fa.php');
            } else {
                completeAuthentication($conn, $user, false);
                header('Location: dashboard.php');
            }
            exit();
        } catch (InvalidArgumentException $exception) {
            $conn->rollback();
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $conn->rollback();
            applicationLog('error', 'Invitation acceptance failed', ['error' => $exception->getMessage()]);
            $error = 'The invitation could not be accepted. Ask an administrator to send a new one.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Accept Invitation'), [
    'styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css'],
    'scripts' => [['path' => 'assets/js/theme-init.min.js', 'defer' => false]],
]); ?>
<body class="fullscreen-center">
<div class="login-container recovery-container">
    <div class="auth-brand"><strong><?php echo htmlspecialchars(applicationBrandName(), ENT_QUOTES, 'UTF-8'); ?><?php if (applicationBrandNativeName() !== ''): ?> <bdi dir="auto"><?php echo htmlspecialchars(applicationBrandNativeName(), ENT_QUOTES, 'UTF-8'); ?></bdi><?php endif; ?></strong></div>
    <h1>Accept Invitation</h1>
    <?php if (isset($error)): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($invitation): ?>
        <p class="login-help">Create a private password for <strong><?php echo htmlspecialchars($invitation['username'], ENT_QUOTES, 'UTF-8'); ?></strong>. Accepting also verifies <?php echo htmlspecialchars($invitation['token_email'], ENT_QUOTES, 'UTF-8'); ?>.</p>
        <form method="post" action="accept_invitation.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group"><label for="password">Password</label><input type="password" name="password" id="password" autocomplete="new-password" minlength="12" maxlength="72" required autofocus></div>
            <div class="form-group"><label for="password_confirmation">Confirm password</label><input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required></div>
            <button type="submit" class="login-button">Activate account</button>
        </form>
    <?php endif; ?>
    <p class="login-secondary-link"><a href="login.php">Back to login</a></p>
</div>
<?php renderScript('assets/js/theme.min.js', false); ?>
</body>
</html>
