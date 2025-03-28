<?php
session_start(); // Ensure the session is started to store session data
include 'config.php';
include 'functions.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Prepare and execute query to check the user in the database
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        // Directly compare plain-text passwords
        if ($password === $user['password']) {
            // Set session variables after successful login
            $_SESSION['user_id'] = $user['id']; // Store user ID in session
            $_SESSION['username'] = $user['username']; // Store username in session
            $_SESSION['role'] = $user['role']; // Store the role separately

            // Redirect to dashboard or home page
            header("Location: index.php");
            exit(); // Make sure to stop further execution after redirection
        }
    }

    // If login fails, show an error
    $error = "Invalid username or password";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DNR - Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div class="login-container">
    <h2>Login to DNR</h2>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <form method="post" action="login.php">
      <label for="username">username:</label>
      <input type="text" name="username" required><br>

      <label for="password">password:</label>
      <input type="password" name="password" required><br>

      <input type="submit" value="Login">
    </form>
  </div>
</body>
</html>

