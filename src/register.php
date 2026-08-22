<?php
// Include required files
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    requireRecentAdminElevation('register.php');

    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
    $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $valid_roles = \Dnr\Domain\ReferenceData::userRoles();

    try {
        if (!in_array($role, $valid_roles, true)) {
            throw new InvalidArgumentException('Invalid role selected.');
        }
        $invitation = inviteUserAccount(
            $conn,
            $username,
            $email,
            $role,
            (int) $_SESSION['user_id']
        );
        try {
            sendInvitationEmail(
                $invitation['email'],
                $invitation['username'],
                $invitation['token']
            );
            $_SESSION['_user_lifecycle_message'] = 'The account was created and its single-use invitation link was sent.';
        } catch (Throwable $mail_exception) {
            applicationLog('error', 'Invitation email delivery failed', [
                'target_user_id' => $invitation['user_id'],
                'error' => $mail_exception->getMessage(),
            ]);
            $_SESSION['_user_lifecycle_error'] = 'The invited account was created, but email delivery failed. Check the mail configuration and resend the invitation.';
        }
        unset($_SESSION['_admin_elevated_at']);
        header('Location: users.php');
        exit();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to invite user', ['error' => $exception->getMessage()]);
        $error = 'Unable to create the invited account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Invite User - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>Invite User</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Invite User</h1><p class="page-intro">Create an account and email a single-use activation link.</p></div></div>

    <?php if (isset($error)): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <form method="post" action="register.php" data-invitation-form>
        <?php echo csrfInput(); ?>
        <div class="form-group"><label for="username">Username</label><input type="text" name="username" id="username" maxlength="50" autocomplete="username" value="<?php echo htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></div>
        <div class="form-group"><label for="email">Email address</label><input type="email" name="email" id="email" maxlength="254" autocomplete="email" value="<?php echo htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required><p class="field-help">The recipient will verify this address when they accept the invitation. The link expires after seven days.</p></div>
        <div class="form-group"><label for="role">Role</label><select name="role" id="role" required>
            <?php foreach (\Dnr\Domain\ReferenceData::userRoles() as $available_role): ?>
                <option value="<?php echo htmlspecialchars($available_role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($_POST['role'] ?? '') === $available_role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($available_role), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select></div>
        <p class="invitation-submit-status" role="status" aria-live="polite" data-invitation-submit-status hidden>
            <span class="invitation-submit-spinner" aria-hidden="true"></span>
            Emailing the activation link&hellip;
        </p>
        <div class="action-buttons create-form-actions">
            <a href="users.php" class="cancel-button">Cancel</a>
            <button type="submit" class="register-button" data-invitation-submit>Send invitation</button>
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
