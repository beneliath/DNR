<?php
// Session is already started in index.php
?>

<header>
    <nav>
        <ul>
            <li><a href="engagements.php">Engagements</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="organizations.php">Organizations</a></li>|&nbsp;&nbsp;&nbsp;
            <li><a href="contacts.php">Contacts</a></li>
            
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') : ?>
                |&nbsp;&nbsp;&nbsp;<li><a href="users.php">Users</a></li>
            <?php endif; ?>
 
        </ul>
    </nav><br>
    <button class="theme-toggle-button" onclick="toggleTheme()">Toggle Theme</button>
    <script src="assets/js/theme.js"></script>
</header>
