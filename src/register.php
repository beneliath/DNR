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
<html>
<head>
    <title>Register - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.2.2">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Register New User</h1>

    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="register.php">
        <?php echo csrfInput(); ?>
        <label for="username">Username <input type="text" name="username" id="username" required></label><br>
        <label for="password">Password <input type="password" name="password" id="password" minlength="12" required></label><br>
        <label for="password_confirm">Confirm Password <input type="password" name="password_confirm" id="password_confirm" minlength="12" required></label><br>
        <label for="role">Role
            <select name="role" id="role" required>
                <option value="admin">Admin</option>
                <option value="editor">Editor</option>
                <option value="reviewer">Reviewer</option>
            </select>
        </label><br>
        <input type="submit" value="Register">
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
