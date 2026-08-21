<?php
include 'config.php';
include 'functions.php';
include 'profile_helpers.php';
include 'two_factor_helpers.php';
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
unset($_SESSION['_admin_elevation_message'], $_SESSION['_admin_elevation_error']);
$admin_actions_unlocked = hasRecentAdminElevation();

$users = $conn->query(
    "SELECT id, username, first_name, last_name, phone, email,
            profile_picture_mime, profile_picture_updated_at,
            role, two_factor_enabled,
            created_at, last_updated_at, last_login_at, must_change_password
     FROM users
     ORDER BY username"
);

if (!$users) {
    die("Database error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <style>
        .page-heading {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            align-items: center;
            column-gap: 16px;
        }
        .page-heading h1 {
            justify-self: start;
        }
        .page-heading > .button-add:last-child {
            justify-self: end;
        }
        .audit-log-link {
            justify-self: center;
        }
        .sensitive-action-locked {
            justify-self: end;
            opacity: 0.55;
            cursor: not-allowed;
            pointer-events: none;
        }
        .user-details {
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .user-details:hover {
            background-color: #f9f9f9;
        }
        .dark-mode .user-details:hover,
        .dark-mode .user-details:hover div:not(.user-actions) {
            background-color: #333 !important;
        }
        .user-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        .user-timestamps {
            font-size: var(--font-size-small);
            color: #666;
            margin-left: 20px;
            padding-top: 5px;
            border-top: 1px solid #eee;
        }
        .timestamp {
            display: inline-block;
            margin-right: 20px;
        }
        .user-actions {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background-color: transparent !important;
        }
        .user-actions form {
            display: inline-flex;
            margin: 0;
        }
        .user-actions .action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            white-space: nowrap;
        }
        .user-actions .edit-button {
            background-color: var(--button-edit-color);
        }
        .user-actions .reset-password-button {
            background-color: var(--button-reset-password-color);
        }
        .user-actions .reset-two-factor-button {
            background-color: var(--button-reset-two-factor-color);
        }
        .user-actions .delete-button {
            background-color: var(--button-delete-color);
        }
        .link-button {
            border: 0;
            padding: 0;
            background: none;
            color: var(--link-color);
            text-decoration: underline;
            cursor: pointer;
        }
        .password-change-required {
            color: #FF9800;
            font-weight: var(--font-weight-semibold);
        }
        .user-profile-summary {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 14px;
        }
        .user-list-avatar {
            display: block;
            width: 52px;
            height: 52px;
            flex: 0 0 auto;
            border: 1px solid var(--border);
            border-radius: 50%;
            background: var(--accent);
            object-fit: cover;
        }
        .user-identity-copy {
            min-width: 0;
        }
        .user-account-heading {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }
        .user-display-name {
            font-size: 1rem;
        }
        .user-username {
            color: var(--text-muted);
            font-size: 0.82rem;
        }
        .user-contact-details {
            display: flex;
            min-width: 0;
            flex-wrap: wrap;
            gap: 5px 18px;
            margin-top: 7px;
            color: var(--text-muted);
            font-size: 0.84rem;
        }
        .user-contact-details span {
            overflow-wrap: anywhere;
        }
        .user-contact-details .is-empty {
            color: var(--text-subtle);
            font-style: italic;
        }
        @media (min-width: 761px) {
            .user-timestamps {
                margin-left: 66px;
            }
        }
        @media (max-width: 760px) {
            .user-main {
                gap: 16px;
            }
            .user-actions {
                align-self: flex-end;
            }
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div><h1>Users</h1><p class="page-intro">Manage access, roles, passwords, and two-factor authentication.</p></div>
        <a href="audit_log.php" class="button-add audit-log-link">Audit Log</a>
        <?php if ($admin_actions_unlocked): ?>
            <a href="register.php" class="button-add">+ New user</a>
        <?php else: ?>
            <span class="button-add sensitive-action-locked" aria-disabled="true" title="Unlock sensitive administrator actions first">+ New user (locked)</span>
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

    <section class="security-card admin-elevation-card" aria-labelledby="admin-elevation-title">
        <h2 id="admin-elevation-title">Sensitive Administrator Actions</h2>
        <?php if ($admin_actions_unlocked): ?>
            <p class="success">Unlocked with a fresh authentication factor. This elevation expires automatically.</p>
        <?php else: ?>
            <p>Confirm your password and a new authenticator or recovery code before creating users, editing accounts or roles, resetting 2FA, or deleting a user. Locked controls remain hidden until elevation succeeds.</p>
            <form method="post" action="users.php" class="security-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="elevate">
                <div class="form-group">
                    <label for="admin_password">Administrator Password</label>
                    <input type="password" id="admin_password" name="admin_password" autocomplete="current-password" required>
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
        <?php while ($user = $users->fetch_assoc()) { ?>
            <?php
            $display_name = profileDisplayName($user);
            $has_personal_name = trim((string) ($user['first_name'] ?? '')) !== ''
                || trim((string) ($user['last_name'] ?? '')) !== '';
            $display_phone = formatPhoneNumberForDisplay($user['phone'] ?? '');
            $display_email = trim((string) ($user['email'] ?? ''));
            $picture_version = strtotime((string) ($user['profile_picture_updated_at'] ?? '')) ?: 0;
            ?>
            <div class="user-details">
                <div class="user-main">
                    <div class="user-profile-summary">
                        <img class="user-list-avatar" src="profile_picture.php?id=<?php echo (int) $user['id']; ?>&amp;v=<?php echo $picture_version; ?>" alt="">
                        <div class="user-identity-copy">
                            <div class="user-account-heading">
                                <strong class="user-display-name"><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if ($has_personal_name): ?><span class="user-username">@<?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                <span>(<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>)</span>
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
                                <span class="<?php echo $display_email === '' ? 'is-empty' : ''; ?>">Email: <?php echo $display_email !== '' ? htmlspecialchars($display_email, ENT_QUOTES, 'UTF-8') : 'Not provided'; ?></span>
                                <span class="<?php echo $display_phone === '' ? 'is-empty' : ''; ?>">Phone: <?php echo $display_phone !== '' ? htmlspecialchars($display_phone, ENT_QUOTES, 'UTF-8') : 'Not provided'; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="user-actions">
                        <?php if ($admin_actions_unlocked && (int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                            <a href="reset_user_password.php?id=<?php echo (int) $user['id']; ?>" class="action-button reset-password-button">Reset Password</a>
                        <?php endif; ?>
                        <?php if ($admin_actions_unlocked && (int) $user['id'] !== (int) $_SESSION['user_id'] && !empty($user['two_factor_enabled'])): ?>
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
                        <?php if ($admin_actions_unlocked && (int) $user['id'] !== (int) $_SESSION['user_id']): ?>
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
                        Created: <?php echo !empty($user['created_at']) ? date('Y-m-d H:i', strtotime($user['created_at'])) : 'N/A'; ?>
                    </span>
                    <span class="timestamp">
                        Last Modified: <?php echo !empty($user['last_updated_at']) ? date('Y-m-d H:i', strtotime($user['last_updated_at'])) : 'N/A'; ?>
                    </span>
                    <span class="timestamp">
                        Last Logged In: <?php echo !empty($user['last_login_at']) ? date('Y-m-d H:i', strtotime($user['last_login_at'])) : 'Never'; ?>
                    </span>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>">
function confirmUserDeletion(form) {
    const requiredPhrase = 'DELETE USER';
    const confirmation = window.prompt(
        'Are you sure you want to delete this user? This cannot be undone.\n\nType DELETE USER to continue:'
    );

    if (confirmation !== requiredPhrase) {
        if (confirmation !== null) {
            window.alert('User not deleted. The confirmation phrase did not match DELETE USER.');
        }
        return false;
    }

    form.elements.delete_confirmation.value = confirmation;
    return true;
}

function confirmTwoFactorReset(form) {
    const requiredPhrase = 'RESET 2FA';
    const confirmation = window.prompt(
        'Reset two-factor authentication for this user? Their current authenticator and recovery codes will stop working.\n\nType RESET 2FA to continue:'
    );

    if (confirmation !== requiredPhrase) {
        if (confirmation !== null) {
            window.alert('Two-factor authentication was not reset. The confirmation phrase did not match RESET 2FA.');
        }
        return false;
    }

    form.elements.reset_confirmation.value = confirmation;
    return true;
}
document.querySelectorAll('form[data-sensitive-action]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        const confirmed = form.dataset.sensitiveAction === 'delete-user'
            ? confirmUserDeletion(form)
            : confirmTwoFactorReset(form);
        if (!confirmed) event.preventDefault();
    });
});
</script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
