<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireAdmin();

// Fetch the user ID from the URL parameter
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $user_id = (int) $_GET['id'];

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
} else {
    header("Location: users.php");
    exit();
}

// Handle the form submission for editing user
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();

    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    $valid_roles = ['admin', 'editor', 'reviewer'];

    if ($username === '' || strlen($username) > 50) {
        $error = "Username is required and must be 50 characters or fewer.";
    } elseif (!in_array($role, $valid_roles, true)) {
        $error = "Invalid role selected.";
    } else {
        // Update the user details in the database
        $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
        $stmt->bind_param("ssi", $username, $role, $user_id);

        if ($stmt->execute()) {
            header("Location: users.php");
            exit();
        } else {
            $error = "Unable to update user details. The username may already exist.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.2">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Edit User</h1>

    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="edit_user.php?id=<?php echo $user['id']; ?>">
        <?php echo csrfInput(); ?>
        <label for="username">Username <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required></label><br>
        <label for="role">Role
            <select name="role" required>
                <option value="admin" <?php if ($user['role'] === 'admin') echo 'selected'; ?>>Admin</option>
                <option value="editor" <?php if ($user['role'] === 'editor') echo 'selected'; ?>>Editor</option>
                <option value="reviewer" <?php if ($user['role'] === 'reviewer') echo 'selected'; ?>>Reviewer</option>
            </select>
        </label><br>
        <input type="submit" value="Save Changes" class="save-button">
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
