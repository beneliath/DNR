<?php
include 'config.php';
include 'functions.php';
startSecureSession();

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Redirect to login page if not logged in as admin
    header("Location: login.php");
    exit();
}

// Older databases may not have timestamp columns until the stabilization migration is applied.
$has_created_at = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'")->num_rows > 0;
$has_last_updated_at = $conn->query("SHOW COLUMNS FROM users LIKE 'last_updated_at'")->num_rows > 0;

if ($has_created_at && $has_last_updated_at) {
    $users = $conn->query(
        "SELECT id, username, role, created_at, last_updated_at FROM users ORDER BY username"
    );
} else {
    $users = $conn->query(
        "SELECT id, username, role, NULL AS created_at, NULL AS last_updated_at FROM users ORDER BY username"
    );
}

if (!$users) {
    die("Database error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .user-details {
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
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
        .link-button {
            border: 0;
            padding: 0;
            background: none;
            color: var(--link-color);
            font: inherit;
            text-decoration: underline;
            cursor: pointer;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Manage Users</h1>

    <h2>Users List</h2>
    <div class="users-list">
        <?php while ($user = $users->fetch_assoc()) { ?>
            <div class="user-details">
                <div class="user-main">
                    <div>
                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                        (<?php echo htmlspecialchars($user['role']); ?>)
                    </div>
                    <div>
                        <a href="edit_user.php?id=<?php echo $user['id']; ?>">Edit</a> |
                        <?php if ((int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                        <form method="post" action="delete_user.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                            <button type="submit" class="link-button">Delete</button>
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
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
