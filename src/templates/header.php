<?php
session_start(); // Start session to access session variables
?>

<header>
    <nav>
        <ul>
            <li><a href="index.php">Dashboard</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="organizations.php">Organizations</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="engagements.php">Engagements</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>|&nbsp;&nbsp;&nbsp;
            
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                <!-- Only display 'New User' link if the user is logged in as an admin -->
                <li><a href="register.php">Add user</a></li>|&nbsp;&nbsp;&nbsp;
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                <!-- Only display 'View Users' link if the user is logged in as an admin -->
                <li><a href="users.php">View users</a></li>
            <?php endif; ?>
 
        </ul>
    </nav><br>
    <button onclick="toggleTheme()">Toggle Theme</button>
    <script src="assets/js/theme.js"></script>
</header>

