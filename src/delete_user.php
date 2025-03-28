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

    // Delete the user from the database
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);

    if ($stmt->execute()) {
        // Redirect to the users list after successful deletion
        header("Location: users.php");
        exit();
    } else {
        // If deletion fails, redirect back to users list
        header("Location: users.php");
        exit();
    }
} else {
    // If no user ID is passed, redirect to users list
    header("Location: users.php");
    exit();
}
?>

