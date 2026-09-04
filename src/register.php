<?php
// Include required files
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/user_lifecycle_helpers.php';
startSecureSession();
requireAdmin();
$admin_actions_unlocked = hasRecentAdminElevation();

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
        $_SESSION['_user_lifecycle_message'] = 'The account was created and its single-use invitation link was queued for delivery.';
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
<?php renderPageHead(applicationPageTitle('Invite User'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/invite_user.min.css',
  ),
)); ?>
<body class="invite-user-body">
<?php include 'templates/header.php'; ?>
<main class="container invite-user-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>Invite User</span></nav>
    <div class="page-heading form-page-heading invite-user-heading"><div><p class="invite-user-eyebrow">User Administration</p><h1>Invite User</h1><p class="page-intro">Create an account and email a single-use activation link.</p></div></div>

    <?php if (isset($error)): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <?php if (!$admin_actions_unlocked): ?>
        <section class="invite-user-access-notice" id="invite-user-access-notice" aria-labelledby="invite-user-access-title">
            <div>
                <strong id="invite-user-access-title">Administrator Confirmation Required</strong>
                <p>You can review this form now. Confirm your password and a fresh authentication code before entering invitation details and sending.</p>
            </div>
            <a href="admin_elevation.php?return=register.php" class="button-secondary">Unlock Invitations</a>
        </section>
    <?php endif; ?>

    <div class="invite-user-layout">
        <form method="post" action="register.php" class="invite-user-form" data-invitation-form>
            <?php echo csrfInput(); ?>
            <div class="invite-user-form-heading">
                <div><span>01</span><div><h2>Account Details</h2><p>Choose the sign-in identity and the correct access level.</p></div></div>
                <small>All Fields Required</small>
            </div>
            <div class="invite-user-field-grid">
                <div class="form-group"><label for="username">Username</label><input type="text" name="username" id="username" maxlength="50" autocomplete="username" value="<?php echo htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></div>
                <div class="form-group"><label for="email">Email Address</label><input type="email" name="email" id="email" maxlength="254" autocomplete="email" value="<?php echo htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required><p class="field-help">The recipient verifies this address when accepting the invitation.</p></div>
                <div class="form-group invite-user-role-field"><label for="role">Role</label><select name="role" id="role" required>
                    <?php foreach (\Dnr\Domain\ReferenceData::userRoles() as $available_role): ?>
                        <option value="<?php echo htmlspecialchars($available_role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($_POST['role'] ?? '') === $available_role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($available_role), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>
            <p class="invitation-submit-status" role="status" aria-live="polite" data-invitation-submit-status hidden>
                <span class="invitation-submit-spinner" aria-hidden="true"></span>
                Emailing the activation link&hellip;
            </p>
            <div class="action-buttons create-form-actions invite-user-actions">
                <a href="users.php" class="cancel-button">Cancel</a>
                <button type="submit" class="register-button" data-invitation-submit<?php echo !$admin_actions_unlocked ? ' disabled aria-describedby="invite-user-access-notice"' : ''; ?>>Send Invitation</button>
            </div>
        </form>

        <aside class="invite-user-guidance" aria-labelledby="invite-user-guidance-title">
            <div>
                <span>02</span>
                <div><h2 id="invite-user-guidance-title">Invitation Flow</h2><p>The link expires after seven days and can be used only once.</p></div>
            </div>
            <ol>
                <li><strong>Send</strong><span>MOED queues a private activation email.</span></li>
                <li><strong>Verify</strong><span>The recipient verifies the invited address.</span></li>
                <li><strong>Activate</strong><span>They set a password and configure account security.</span></li>
            </ol>
            <div class="invite-user-role-guide">
                <h3>Role Access</h3>
                <p><strong>Reviewer</strong> reads and exports shared records.</p>
                <p><strong>Editor</strong> creates and maintains operational work.</p>
                <p><strong>Administrator</strong> adds governance, recovery, and user controls.</p>
            </div>
        </aside>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
