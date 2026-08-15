<?php
include 'config.php';
include 'functions.php';
include 'two_factor_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);

// Older databases may not have timestamp columns until the stabilization migration is applied.
$has_created_at = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'")->num_rows > 0;
$has_last_updated_at = $conn->query("SHOW COLUMNS FROM users LIKE 'last_updated_at'")->num_rows > 0;
$has_last_login_at = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->num_rows > 0;
$has_must_change_password = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->num_rows > 0;

$created_at_column = $has_created_at ? 'created_at' : 'NULL AS created_at';
$last_updated_at_column = $has_last_updated_at ? 'last_updated_at' : 'NULL AS last_updated_at';
$last_login_at_column = $has_last_login_at ? 'last_login_at' : 'NULL AS last_login_at';
$must_change_password_column = $has_must_change_password
    ? 'must_change_password'
    : '0 AS must_change_password';

$users = $conn->query(
    "SELECT id, username, role, two_factor_enabled,
            {$created_at_column}, {$last_updated_at_column}, {$last_login_at_column},
            {$must_change_password_column}
     FROM users
     ORDER BY username"
);

if (!$users) {
    die("Database error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.2">
    <style>
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
            font-size: 0.9em;
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
            font-family: inherit;
            font-size: 0.9em;
            line-height: 1.6;
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
            font: inherit;
            text-decoration: underline;
            cursor: pointer;
        }
        .password-change-required {
            color: #FF9800;
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Manage Users</h1>

    <?php if (isset($_GET['two_factor_reset'])): ?>
        <p class="success">The user's two-factor authentication was reset.</p>
    <?php elseif (isset($_GET['password_reset'])): ?>
        <p class="success">The user's temporary password was set. Their existing sessions were invalidated.</p>
    <?php endif; ?>

    <h2>Users List</h2>
    <div class="users-list">
        <?php while ($user = $users->fetch_assoc()) { ?>
            <div class="user-details">
                <div class="user-main">
                    <div>
                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                        (<?php echo htmlspecialchars($user['role']); ?>)
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
                    <div class="user-actions">
                        <?php if ((int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                            <a href="reset_user_password.php?id=<?php echo (int) $user['id']; ?>" class="action-button reset-password-button">Reset Password</a>
                        <?php endif; ?>
                        <?php if ((int) $user['id'] !== (int) $_SESSION['user_id'] && !empty($user['two_factor_enabled'])): ?>
                            <form method="post" action="reset_user_2fa.php" onsubmit="return confirmTwoFactorReset(this);">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="reset_confirmation" value="">
                                <button type="submit" class="action-button reset-two-factor-button">Reset 2FA</button>
                            </form>
                        <?php endif; ?>
                        <a href="edit_user.php?id=<?php echo (int) $user['id']; ?>" class="action-button edit-button">Edit</a>
                        <?php if ((int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                            <form method="post" action="delete_user.php" onsubmit="return confirmUserDeletion(this);">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                <input type="hidden" name="delete_confirmation" value="">
                                <button type="submit" class="action-button delete-button">Delete</button>
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
<script>
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
</script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
