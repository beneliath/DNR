<?php
session_start();
include 'config.php';
include 'functions.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if ($password === $user['password']) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php");
            exit();
        }
    }

    $error = "Invalid username or password";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DNR - Login</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script>
    // Load theme before page renders
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
      document.documentElement.classList.add('dark-mode');
    }
  </script>
</head>
<body class="fullscreen-center">
  <div class="login-container">
    <h1>Login</h1>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <form method="post" action="login.php">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" name="username" id="username" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" name="password" id="password" required>
      </div>

      <button type="submit" class="login-button">Login</button>
    </form>
  </div>
  <script src="assets/js/theme.js"></script>
</body>
</html>

