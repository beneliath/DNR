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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit User - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.17">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>Edit user</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit user</h1><p class="page-intro">Change the account username or access level.</p></div></div>

    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="edit_user.php?id=<?php echo $user['id']; ?>">
        <?php echo csrfInput(); ?>
        <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($user['username']); ?>" required></div>
        <div class="form-group"><label for="role">Role</label><select id="role" name="role" required>
            <option value="admin" <?php if ($user['role'] === 'admin') echo 'selected'; ?>>Admin</option>
            <option value="editor" <?php if ($user['role'] === 'editor') echo 'selected'; ?>>Editor</option>
            <option value="reviewer" <?php if ($user['role'] === 'reviewer') echo 'selected'; ?>>Reviewer</option>
        </select></div>
        <div class="action-buttons"><a href="users.php" class="cancel-button">Cancel</a><input type="submit" value="Save changes" class="save-button"></div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
