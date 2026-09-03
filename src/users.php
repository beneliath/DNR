<?php
require_once __DIR__ . '/bootstrap.php';
include 'profile_helpers.php';
include 'two_factor_helpers.php';
include 'user_lifecycle_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (($_POST['action'] ?? '') !== 'elevate') {
        http_response_code(400);
        exit('Invalid administrator action.');
    }
    $password = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
    $code = is_string($_POST['admin_code'] ?? null) ? $_POST['admin_code'] : '';
    if (attemptAdminElevation($conn, $password, $code)) {
        $_SESSION['_admin_elevation_message'] = 'Sensitive administrator actions are unlocked for five minutes.';
    } else {
        $_SESSION['_admin_elevation_error'] = 'The administrator password or fresh authentication code was not accepted.';
    }
    header('Location: users.php');
    exit();
}

$elevation_message = $_SESSION['_admin_elevation_message'] ?? '';
$elevation_error = $_SESSION['_admin_elevation_error'] ?? '';
$lifecycle_message = $_SESSION['_user_lifecycle_message'] ?? '';
$lifecycle_error = $_SESSION['_user_lifecycle_error'] ?? '';
unset($_SESSION['_admin_elevation_message'], $_SESSION['_admin_elevation_error']);
unset($_SESSION['_user_lifecycle_message'], $_SESSION['_user_lifecycle_error']);
$admin_actions_unlocked = hasRecentAdminElevation();
$current_user_id = (int) $_SESSION['user_id'];
generateCsrfToken();
releaseApplicationSessionLock();

$page_size = 50;
$cursor = decodePaginationCursor(
    \Dnr\Http\RequestInput::string($_GET, 'cursor'),
    ['username', 'id']
);
$cursor_filter = '';
$cursor_values = [];
$cursor_types = '';
if (is_array($cursor)
    && is_string($cursor['username'])
    && filter_var($cursor['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
) {
    $cursor_filter = 'WHERE (username, id) > (?, ?)';
    $cursor_values = [(string) $cursor['username'], (int) $cursor['id']];
    $cursor_types = 'si';
} else {
    $cursor = null;
}
$users_stmt = $conn->prepare(
    "SELECT id, username, first_name, last_name, phone, email,
            profile_picture_mime, profile_picture_updated_at,
            role, account_status, activated_at, deactivated_at,
            email_verified_at, two_factor_enabled,
            created_at, last_updated_at, last_login_at, must_change_password
     FROM users
     {$cursor_filter}
     ORDER BY username, id
     LIMIT ?"
);
if (!$users_stmt) {
    abortApplication(503, 'The user list is temporarily unavailable.', ['error' => $conn->error]);
}
$query_limit = $page_size + 1;
$cursor_values[] = $query_limit;
$cursor_types .= 'i';
$users_bind = [$cursor_types];
foreach ($cursor_values as &$cursor_value) {
    $users_bind[] = &$cursor_value;
}
unset($cursor_value);
$users_stmt->bind_param(...$users_bind);
$users_stmt->execute();
$users = $users_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$users_stmt->close();
$has_next_page = count($users) > $page_size;
if ($has_next_page) {
    array_pop($users);
}
$next_cursor = null;
if ($has_next_page && $users !== []) {
    $last_user = $users[array_key_last($users)];
    $next_cursor = encodePaginationCursor([
        'username' => (string) $last_user['username'],
        'id' => (int) $last_user['id'],
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Users'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/users.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div><h1>Users</h1><p class="page-intro">Manage invitations, account access, verified recovery email, roles, passwords, and two-factor authentication.</p></div>
        <a href="audit_log.php" class="button-add audit-log-link">Audit Log</a>
        <?php if ($admin_actions_unlocked): ?>
            <a href="register.php" class="button-add">+ Invite user</a>
        <?php else: ?>
            <span class="button-add sensitive-action-locked" aria-disabled="true" title="Unlock sensitive administrator actions first">+ Invite user (locked)</span>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['two_factor_reset'])): ?>
        <p class="success">The user's two-factor authentication was reset.</p>
    <?php elseif (isset($_GET['password_reset'])): ?>
        <p class="success">The user's temporary password was set. Their existing sessions were invalidated.</p>
    <?php endif; ?>

    <?php if ($elevation_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($elevation_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($elevation_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($elevation_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($lifecycle_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($lifecycle_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($lifecycle_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($lifecycle_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <section class="security-card admin-elevation-card" aria-labelledby="admin-elevation-title">
        <h2 id="admin-elevation-title">Sensitive Administrator Actions</h2>
        <?php if ($admin_actions_unlocked): ?>
            <p class="success">Unlocked with a fresh authentication factor. This elevation expires automatically.</p>
        <?php else: ?>
            <p>Confirm your password and a new authenticator or recovery code before inviting users, changing account access or roles, resetting authentication, or deleting a user. Locked controls remain hidden until elevation succeeds.</p>
            <form method="post" action="users.php" class="security-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="elevate">
                <div class="form-group">
                    <label for="admin_password">Administrator Password</label>
                    <input type="password" id="admin_password" name="admin_password" autocomplete="current-password" maxlength="72" required>
                </div>
                <div class="form-group">
                    <label for="admin_code">Fresh Authentication Code</label>
                    <input type="text" id="admin_code" name="admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>
                </div>
                <button type="submit" class="security-button">Unlock for Five Minutes</button>
            </form>
        <?php endif; ?>
    </section>

    <div class="users-list">
        <?php foreach ($users as $user) { ?>
            <?php
            $display_name = profileDisplayName($user);
            $has_personal_name = trim((string) ($user['first_name'] ?? '')) !== ''
                || trim((string) ($user['last_name'] ?? '')) !== '';
            $display_phone = formatPhoneNumberForDisplay($user['phone'] ?? '');
            $display_email = trim((string) ($user['email'] ?? ''));
            $picture_version = strtotime((string) ($user['profile_picture_updated_at'] ?? '')) ?: 0;
            $profile_thumbnail_url = 'profile_picture.php?id=' . (int) $user['id']
                . '&v=' . $picture_version;
            ?>
            <div class="user-details">
                <div class="user-main">
                    <div class="user-profile-summary">
                        <img class="user-list-avatar" src="<?php echo htmlspecialchars(
                            $profile_thumbnail_url,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>" alt="">
                        <div class="user-identity-copy">
                            <div class="user-account-heading">
                                <strong class="user-display-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($has_personal_name): ?><span class="user-username">@<?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <span>(<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                &mdash;
                                <span class="account-status account-status-<?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(userAccountStatusLabel($user['account_status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                &mdash;
                                <?php if (!empty($user['two_factor_enabled'])): ?>
                                    <span class="two-factor-status-enabled">2FA enabled</span>
                                <?php else: ?>
                                    <span class="two-factor-status-disabled">2FA not enabled</span>
                                <?php endif; ?>
                                <?php if (!empty($user['must_change_password'])): ?>
                                    &mdash; <span class="password-change-required">password change required</span>
                                <?php endif; ?>
                            </div>
                            <div class="user-contact-details">
                                <span class="<?php echo $display_email === '' ? 'is-empty' : ''; ?>">Email: <?php echo $display_email !== '' ? htmlspecialchars($display_email, ENT_QUOTES, 'UTF-8') : 'Not provided'; ?><?php if ($display_email !== ''): ?> · <?php echo !empty($user['email_verified_at']) ? 'Verified' : 'Unverified'; ?><?php endif; ?></span>
                                <span class="<?php echo $display_phone === '' ? 'is-empty' : ''; ?>">Phone: <?php echo $display_phone !== '' ? htmlspecialchars($display_phone, ENT_QUOTES, 'UTF-8') : 'Not provided'; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="user-actions">
                        <?php if ($admin_actions_unlocked && (int) $user['id'] !== (int) $_SESSION['user_id'] && $user['account_status'] === 'active'): ?>
                            <a href="reset_user_password.php?id=<?php echo (int) $user['id']; ?>" class="action-button reset-password-button">Reset Password</a>
                        <?php endif; ?>
                        <?php if ($admin_actions_unlocked && (int) $user['id'] !== (int) $_SESSION['user_id'] && $user['account_status'] === 'active' && !empty($user['two_factor_enabled'])): ?>
                            <form method="post" action="reset_user_2fa.php" data-sensitive-action="reset-2fa">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="reset_confirmation" value="">
                                <button type="submit" class="action-button reset-two-factor-button">Reset 2FA</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($admin_actions_unlocked): ?>
                            <a href="edit_user.php?id=<?php echo (int) $user['id']; ?>" class="action-button action-icon-button edit-button" aria-label="Edit user" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                        <?php endif; ?>
                        <?php if ($admin_actions_unlocked && $user['account_status'] === 'invited'): ?>
                            <form method="post" action="user_lifecycle.php" data-invitation-form>
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="action" value="resend_invitation">
                                <button type="submit" class="action-button reset-password-button" data-invitation-submit data-submitting-label="Resending invitation&hellip;">Resend invitation</button>
                                <span class="invitation-submit-status invitation-submit-status-compact" role="status" aria-live="polite" data-invitation-submit-status hidden>
                                    <span class="invitation-submit-spinner" aria-hidden="true"></span>
                                    Emailing a new activation link&hellip;
                                </span>
                            </form>
                        <?php elseif ($admin_actions_unlocked && $user['account_status'] === 'active' && (int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="post" action="user_lifecycle.php">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" class="action-button delete-button" data-confirm="Deactivate this account? Sessions and calendar links will be revoked, and tasks will be unassigned.">Deactivate</button>
                            </form>
                        <?php elseif ($admin_actions_unlocked && $user['account_status'] === 'inactive'): ?>
                            <form method="post" action="user_lifecycle.php">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="action" value="activate">
                                <button type="submit" class="action-button reset-two-factor-button">Activate</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($admin_actions_unlocked && $user['account_status'] !== 'active' && (int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="post" action="delete_user.php" data-sensitive-action="delete-user">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="delete_confirmation" value="">
                                <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete user" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="user-timestamps">
                    <span class="timestamp">
                        Created: <?php echo !empty($user['created_at']) ? applicationTimestampLabel($user['created_at']) : 'N/A'; ?>
                    </span>
                    <span class="timestamp">
                        Last Modified: <?php echo !empty($user['last_updated_at']) ? applicationTimestampLabel($user['last_updated_at']) : 'N/A'; ?>
                    </span>
                    <span class="timestamp">
                        Last Logged In: <?php echo !empty($user['last_login_at']) ? applicationTimestampLabel($user['last_login_at']) : 'Never'; ?>
                    </span>
                    <span class="timestamp">
                        <?php echo $user['account_status'] === 'inactive' ? 'Deactivated' : 'Activated'; ?>: <?php $lifecycle_at = $user['account_status'] === 'inactive' ? $user['deactivated_at'] : $user['activated_at']; echo !empty($lifecycle_at) ? applicationTimestampLabel($lifecycle_at) : 'N/A'; ?>
                    </span>
                </div>
            </div>
        <?php } ?>
    </div>
    <?php if ($cursor !== null || $next_cursor !== null): ?>
        <nav class="pagination" aria-label="User pages">
            <?php if ($cursor !== null): ?>
                <a href="users.php" class="pagination-link">First page</a>
            <?php endif; ?>
            <?php if ($next_cursor !== null): ?>
                <a href="users.php?cursor=<?php echo rawurlencode($next_cursor); ?>" class="pagination-link">Next</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
