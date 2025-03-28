<?php
session_start(); // Start the session to access session data

// Unset all session variables
session_unset();

// Destroy the session
session_destroy();

// Redirect to login page after logout
header("Location: login.php");
exit();
?>

