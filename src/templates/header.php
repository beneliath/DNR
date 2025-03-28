<?php
session_start(); // Start session to access session variables
?>

<header>
    <nav>
        <ul>
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="organizations.php">Organizations</a></li>
            <li><a href="engagements.php">Engagements</a></li>
            <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</a></li>
            
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                <!-- Only display 'New User' link if the user is logged in as an admin -->
                <li><a href="register.php">New</a></li>|
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                <!-- Only display 'View Users' link if the user is logged in as an admin -->
                <li><a href="users.php">&nbsp;&nbsp; View</a>&nbsp;Users</li>
            <?php endif; ?>
 
        </ul>
    </nav><br>
    <button onclick="toggleTheme()">Toggle Theme</button>
</header>

