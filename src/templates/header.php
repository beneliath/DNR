<?php
// Session is already started by the page entry point.
$shell_current_page = basename($_SERVER['PHP_SELF'] ?? '');
$nav_groups = [
    'engagements' => ['engagements.php', 'index.php', 'edit_engagement.php', 'view_engagement.php', 'restore_chron_entries.php'],
    'tasks' => ['tasks.php', 'add_task.php', 'edit_task.php'],
    'map' => ['map.php'],
    'organizations' => ['organizations.php', 'add_organization.php', 'edit_organization.php', 'view_organization.php'],
    'contacts' => ['contacts.php', 'add_contact.php', 'edit_contact.php', 'view_contact.php', 'contact_photo.php'],
    'users' => ['users.php', 'register.php', 'edit_user.php', 'audit_log.php', 'reset_user_password.php', 'admin_elevation.php'],
    'database' => ['database_maintenance.php'],
    'profile' => ['profile.php'],
];
$active_nav = '';
foreach ($nav_groups as $group => $pages) {
    if (in_array($shell_current_page, $pages, true)) {
        $active_nav = $group;
        break;
    }
}
$username = (string) ($_SESSION['username'] ?? 'Account');
$user_display_name = (string) ($_SESSION['profile_display_name'] ?? $username);
$user_role = (string) ($_SESSION['role'] ?? 'user');
$profile_picture_version = (int) ($_SESSION['profile_picture_version'] ?? 0);
?>

<link rel="stylesheet" href="assets/css/modern.min.css?v=0.1.60">
<?php if ($shell_current_page === 'map.php') : ?>
<link rel="stylesheet" href="assets/css/map.min.css?v=1.0.8">
<?php endif; ?>

<header class="app-shell-header">
    <div class="mobile-app-bar">
        <button type="button" class="mobile-menu-button" data-nav-toggle aria-controls="app-sidebar" aria-expanded="false">
            <span class="visually-hidden">Open navigation</span>
            <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <a href="engagements.php" class="mobile-brand" aria-label="DNR — MOED מוֹעֵד home">
            <span class="mobile-brand-name">MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi></span>
        </a>
        <button type="button" class="mobile-theme-button" data-theme-toggle aria-label="Switch to dark theme">
            <svg class="theme-icon-light" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
            <svg class="theme-icon-dark" aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
        </button>
    </div>

    <div class="app-sidebar" id="app-sidebar">
        <a class="app-brand" href="engagements.php" aria-label="DNR — MOED מוֹעֵד">
            <span class="app-brand-copy"><strong>MOED <bdi lang="he" dir="rtl">מוֹעֵד</bdi></strong></span>
        </a>

        <nav class="site-navigation" aria-label="Primary">
            <ul>
                <li><a href="engagements.php" class="nav-link<?php echo $active_nav === 'engagements' ? ' active' : ''; ?>"<?php echo $active_nav === 'engagements' ? ' aria-current="page"' : ''; ?>>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg><span>Engagements</span>
                </a></li>
                <li><a href="tasks.php" class="nav-link<?php echo $active_nav === 'tasks' ? ' active' : ''; ?>"<?php echo $active_nav === 'tasks' ? ' aria-current="page"' : ''; ?>>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="m8 10 2 2 4-4M8 17h8"/></svg><span>Work Queue</span>
                </a></li>
                <li><a href="map.php" class="nav-link<?php echo $active_nav === 'map' ? ' active' : ''; ?>"<?php echo $active_nav === 'map' ? ' aria-current="page"' : ''; ?>>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/><circle cx="15" cy="11" r="2"/></svg><span>Map</span>
                </a></li>
                <li><a href="organizations.php" class="nav-link<?php echo $active_nav === 'organizations' ? ' active' : ''; ?>"<?php echo $active_nav === 'organizations' ? ' aria-current="page"' : ''; ?>>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 21V6l8-3v18M12 9h8v12M8 8v.01M8 12v.01M8 16v.01M16 13v.01M16 17v.01M2 21h20"/></svg><span>Organizations</span>
                </a></li>
                <li><a href="contacts.php" class="nav-link<?php echo $active_nav === 'contacts' ? ' active' : ''; ?>"<?php echo $active_nav === 'contacts' ? ' aria-current="page"' : ''; ?>>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span>Contacts</span>
                </a></li>
                <?php if ($user_role === 'admin') : ?>
                    <li><a href="users.php" class="nav-link<?php echo $active_nav === 'users' ? ' active' : ''; ?>"<?php echo $active_nav === 'users' ? ' aria-current="page"' : ''; ?>>
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>Users</span>
                    </a></li>
                    <li><a href="database_maintenance.php" class="nav-link<?php echo $active_nav === 'database' ? ' active' : ''; ?>"<?php echo $active_nav === 'database' ? ' aria-current="page"' : ''; ?>>
                        <svg aria-hidden="true" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/></svg><span>Database</span>
                    </a></li>
                <?php endif; ?>
            </ul>
        </nav>

        <nav class="utility-navigation" aria-label="Account and application">
            <a href="calendar_subscription.php" class="nav-link<?php echo $shell_current_page === 'calendar_subscription.php' ? ' active' : ''; ?>"<?php echo $shell_current_page === 'calendar_subscription.php' ? ' aria-current="page"' : ''; ?>>
                <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg><span>Calendar</span>
            </a>
            <a href="two_factor_settings.php" class="nav-link<?php echo in_array($shell_current_page, ['two_factor_settings.php', 'setup_2fa.php', 'two_factor_recovery_codes.php'], true) ? ' active' : ''; ?>"<?php echo in_array($shell_current_page, ['two_factor_settings.php', 'setup_2fa.php', 'two_factor_recovery_codes.php'], true) ? ' aria-current="page"' : ''; ?>>
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg><span>Account security</span>
            </a>
            <button type="button" class="nav-link theme-toggle-button" data-theme-toggle aria-label="Switch to dark theme">
                <svg class="theme-icon-light" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
                <svg class="theme-icon-dark" aria-hidden="true" viewBox="0 0 24 24"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg><span class="theme-label">Dark theme</span>
            </button>
        </nav>

        <div class="sidebar-account">
            <a href="profile.php" class="sidebar-account-link<?php echo $active_nav === 'profile' ? ' active' : ''; ?>"<?php echo $active_nav === 'profile' ? ' aria-current="page"' : ''; ?> aria-label="Open profile for <?php echo htmlspecialchars($user_display_name, ENT_QUOTES, 'UTF-8'); ?>">
                <img class="account-avatar" src="profile_picture.php?v=<?php echo $profile_picture_version; ?>" alt="">
                <span class="account-copy"><strong><?php echo htmlspecialchars($user_display_name, ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars(ucfirst($user_role)); ?></small></span>
            </a>
            <form method="post" action="logout.php" id="logout-form" class="sidebar-logout-form">
                <?php echo csrfInput(); ?>
                <button type="submit" class="sidebar-logout-button" aria-label="Log out <?php echo htmlspecialchars($username); ?>" title="Log out">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg>
                </button>
            </form>
        </div>
    </div>
    <button type="button" class="sidebar-backdrop" data-nav-backdrop aria-label="Close navigation"></button>
</header>
<script src="assets/js/theme.min.js?v=1.1.0"></script>
<script src="assets/js/app-shell.min.js?v=1.1.0"></script>
<script src="assets/js/phone-input.min.js?v=0.2.0" defer></script>
