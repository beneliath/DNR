<?php
session_start(); // Ensure the session is started to store session data
include 'config.php';
include 'functions.php';

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Redirect to login page if not logged in as admin
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password']; // Store plain-text password temporarily
    $role = $_POST['role'];

    // Check if the user already exists
    $check = $conn->query("SELECT id FROM users WHERE username='$username'");
    if ($check->num_rows > 0) {
        $error = "Username already exists.";
    } else {
        // Store the user with plain-text password (for now)
        $sql = "INSERT INTO users (username, password, role) VALUES ('$username', '$password', '$role')";
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
        <label for="username">username: <input type="text" name="username" id="username" required></label><br>
        <label for="password">password: <input type="password" name="password" id="password" required></label><br>
        <label for="role">role:
            <select name="role" id="role" required>
                <option value="admin">admin</option>
                <option value="editor">editor</option>
                <option value="reviewer">reviewer</option>
            </select>
        </label><br>
        <input type="submit" value="Register">
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

