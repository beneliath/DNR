<?php
session_start();

// Include required files
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/functions.php';

use DNR\Utils\Security;

// Ensure the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get all users with plain text passwords
$users = $conn->query("SELECT id, password FROM users");

if ($users) {
    $updated = 0;
    $errors = 0;
    
    while ($user = $users->fetch_assoc()) {
        // Skip if password is already hashed (BCRYPT hashes start with $2y$)
        if (strpos($user['password'], '$2y$') === 0) {
            continue;
        }
        
        // Hash the plain text password
        $hashedPassword = Security::hashPassword($user['password']);
        
        // Update the user's password
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashedPassword, $user['id']);
        
        if ($stmt->execute()) {
            $updated++;
        } else {
            $errors++;
        }
    }
    
    echo "Migration complete:<br>";
    echo "Updated passwords: $updated<br>";
    echo "Errors: $errors<br>";
} else {
    echo "Error fetching users: " . $conn->error;
}
?> 