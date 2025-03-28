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

// Fetch the user ID from the URL parameter
if (isset($_GET['id'])) {
    $user_id = $_GET['id'];

    // Fetch user details from the database
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        // If no user is found, redirect to the users list
        header("Location: users.php");
        exit();
    }
}

// Handle the form submission for editing user
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $role = $_POST['role'];

    // Update the user details in the database
    $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
    $stmt->bind_param("ssi", $username, $role, $user_id);

    if ($stmt->execute()) {
        // Redirect back to the user management page after successful update
        header("Location: users.php");
        exit();
    } else {
        $error = "Error updating user details.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Edit User</h1>

    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="edit_user.php?id=<?php echo $user['id']; ?>">
        <label for="username">Username: <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required></label><br>
        <label for="role">Role:
            <select name="role" required>
                <option value="admin" <?php if ($user['role'] === 'admin') echo 'selected'; ?>>Admin</option>
                <option value="editor" <?php if ($user['role'] === 'editor') echo 'selected'; ?>>Editor</option>
                <option value="reviewer" <?php if ($user['role'] === 'reviewer') echo 'selected'; ?>>Reviewer</option>
            </select>
        </label><br>
        <input type="submit" value="Save Changes">
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

