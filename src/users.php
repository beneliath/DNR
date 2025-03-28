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

// Fetch all users from the database
$users = $conn->query("SELECT id, username, role FROM users");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Manage Users</h1>

    <h2>Users List</h2>
    <table>
        <thead>
            <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($user = $users->fetch_assoc()) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                    <td>
                        <a href="edit_user.php?id=<?php echo $user['id']; ?>">Edit</a> |
                        <a href="delete_user.php?id=<?php echo $user['id']; ?>" onclick="return confirm('Are you sure you want to delete this user?');">Delete</a>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

