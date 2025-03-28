<?php
// Check if the user is logged in by verifying if 'user_id' is set in the session
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Ensure that the user is logged in; if not, redirect to the login page
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php"); // Redirect to login page if not logged in
        exit(); // Ensure no further code is executed after the redirect
    }
}

// Check if the logged-in user has a specific role
function checkRole($role) {
    return (isset($_SESSION['role']) && $_SESSION['role'] == $role);
}

// Check if the logged-in user has any of the specified roles
function hasRole($roles = []) {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], $roles));
}
?>
