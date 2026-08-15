<?php
// Session is already started in index.php
?>

<header>
    <nav>
        <ul>
            <li><a href="engagements.php">Engagements</a></li>|&nbsp;&nbsp;&nbsp;
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor'], true)) : ?>
            <li><a href="index.php">Add Engagement</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="add_organization.php">Add Organization</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="add_contact.php">Add Contact</a></li>|&nbsp;&nbsp;&nbsp;
            <?php endif; ?>
            <li><a href="organizations.php">Organizations</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="calendar_subscription.php">Calendar</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="two_factor_settings.php">Account Security</a></li>
            
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                <!-- Only display 'New User' link if the user is logged in as an admin -->
                |&nbsp;&nbsp;&nbsp;<li><a href="register.php">Add User</a></li>|&nbsp;&nbsp;&nbsp;
            <?php endif; ?>

            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                <!-- Only display 'Manage Users' link if the user is logged in as an admin -->
                <li><a href="users.php">Manage Users</a></li>
            <?php endif; ?>
 
        </ul>
    </nav><br>
    <button class="theme-toggle-button" onclick="toggleTheme()">Toggle Theme</button>
    <script src="assets/js/theme.js"></script>
</header>
