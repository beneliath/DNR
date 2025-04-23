<?php
session_start();

// Include required files
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/functions.php';

use DNR\Utils\Security;

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Redirect to login page if not logged in as admin
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Hash the password before storing
    $hashedPassword = Security::hashPassword($password);

    // Check if the user already exists
    $check = $conn->query("SELECT id FROM users WHERE username='$username'");
    if ($check->num_rows > 0) {
        $error = "Username already exists.";
    } else {
        // Store the user with hashed password
        $sql = "INSERT INTO users (username, password, role) VALUES ('$username', '$hashedPassword', '$role')";
        if ($conn->query($sql) === TRUE) {
            $message = "User registered successfully.";
        } else {
            $error = "Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Register New User</h1>

    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="register.php">
        <label for="username">Username <input type="text" name="username" id="username" required></label><br>
        <label for="password">Password <input type="password" name="password" id="password" required></label><br>
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

