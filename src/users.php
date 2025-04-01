<?php
session_start(); // Start the session to access session data
include 'config.php';
include 'functions.php';

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Redirect to login page if not logged in as admin
    header("Location: login.php");
    exit();
}

// First, check if the timestamp columns exist, if not add them
$checkColumns = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'");
if ($checkColumns->num_rows === 0) {
    // Add the timestamp columns if they don't exist
    $alterTable = "ALTER TABLE users 
                   ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                   ADD COLUMN last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    $conn->query($alterTable);
}

// Now fetch all users from the database
$users = $conn->query("SELECT id, username, role, 
                      IFNULL(created_at, 'N/A') as created_at, 
                      IFNULL(last_updated_at, 'N/A') as last_updated_at 
                      FROM users");

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
                        <a href="delete_user.php?id=<?php echo $user['id']; ?>" 
                           onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                    </div>
                </div>
                <div class="user-timestamps">
                    <span class="timestamp">
                        Created: <?php echo $user['created_at'] !== 'N/A' ? date('Y-m-d H:i', strtotime($user['created_at'])) : 'N/A'; ?>
                    </span>
                    <span class="timestamp">
                        Last Modified: <?php echo $user['last_updated_at'] !== 'N/A' ? date('Y-m-d H:i', strtotime($user['last_updated_at'])) : 'N/A'; ?>
                    </span>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

