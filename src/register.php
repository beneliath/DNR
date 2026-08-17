<?php
// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $role = $_POST['role'] ?? '';
    $valid_roles = ['admin', 'editor', 'reviewer'];

    if ($username === '' || strlen($username) > 50) {
        $error = "Username is required and must be 50 characters or fewer.";
    } elseif (strlen($password) < 12) {
        $error = "Password must be at least 12 characters.";
    } elseif (!hash_equals($password, $password_confirm)) {
        $error = "Passwords do not match.";
    } elseif (!in_array($role, $valid_roles, true)) {
        $error = "Invalid role selected.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $existing_user = $check->get_result();

        if ($existing_user->num_rows > 0) {
            $error = "Username already exists.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                "INSERT INTO users (username, password, must_change_password, role) VALUES (?, ?, 1, ?)"
            );
            $stmt->bind_param("sss", $username, $hashedPassword, $role);

            if ($stmt->execute()) {
                $message = "User registered successfully.";
            } else {
                $error = "Unable to create the user.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.17">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>New user</span></nav>
    <div class="page-heading form-page-heading"><div><h1>New user</h1><p class="page-intro">Create an account and assign its access level.</p></div></div>

    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="register.php">
        <?php echo csrfInput(); ?>
        <div class="form-group"><label for="username">Username</label><input type="text" name="username" id="username" autocomplete="username" required></div>
        <div class="form-group"><label for="password">Temporary password</label><input type="password" name="password" id="password" autocomplete="new-password" minlength="12" required><p class="field-help">Use at least 12 characters. The user can change it from Account security.</p></div>
        <div class="form-group"><label for="password_confirm">Confirm temporary password</label><input type="password" name="password_confirm" id="password_confirm" autocomplete="new-password" minlength="12" required></div>
        <div class="form-group"><label for="role">Role</label><select name="role" id="role" required>
            <option value="admin">Admin</option>
            <option value="editor">Editor</option>
            <option value="reviewer">Reviewer</option>
        </select></div>
        <div class="action-buttons"><a href="users.php" class="cancel-button">Cancel</a><input type="submit" value="Create user" class="register-button"></div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
