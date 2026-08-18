<?php

function expectHoverStyle($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/style.css');
$modern_stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$audit_log_page = file_get_contents(__DIR__ . '/../src/audit_log.php');
$calendar_subscription_page = file_get_contents(__DIR__ . '/../src/calendar_subscription.php');
$footer_template = file_get_contents(__DIR__ . '/../src/templates/footer.php');

expectHoverStyle(
    strpos($stylesheet, '--button-hover-color') === false
        && strpos(strtolower($stylesheet), '#f77f00') === false,
    'The legacy orange hover token should be removed from the base stylesheet.'
);
expectHoverStyle(
    strpos($stylesheet, '.filter-button,') !== false,
    'Audit filters and pagination controls should use the shared button styles.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '--control-hover-bg: #dbeafe;') !== false
        && strpos($modern_stylesheet, '--control-hover-bg: #243f73;') !== false,
    'List controls should define distinct, visible hover surfaces for both themes.'
);
expectHoverStyle(
    strpos($audit_log_page, "background-color: var(--button-edit-color) !important;") !== false,
    'The Audit Log should apply its active selector color without relying on a cached stylesheet.'
);
expectHoverStyle(
    strpos($stylesheet, 'Consistent button hover feedback') === false,
    'The legacy catch-all hover rule should be removed.'
);

$legacy_hover_markers = ['button-hover-color', '#f77f00', '#ff8c00', '#ffa500', 'darkorange'];
$source_paths = [];
$source_iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS)
);
foreach ($source_iterator as $source_file) {
    if (in_array(strtolower($source_file->getExtension()), ['php', 'css', 'js'], true)) {
        $source_paths[] = $source_file->getPathname();
    }
}
foreach ($source_paths as $source_path) {
    $source = strtolower(file_get_contents($source_path));
    foreach ($legacy_hover_markers as $marker) {
        expectHoverStyle(
            strpos($source, $marker) === false,
            basename($source_path) . ' should not contain the legacy orange hover marker ' . $marker . '.'
        );
    }
}
expectHoverStyle(
    strpos($modern_stylesheet, '/* Safe actions share one quiet surface treatment across pages and dialogs. */') !== false
        && strpos($modern_stylesheet, 'border: 1px solid var(--border-strong) !important;') !== false
        && strpos($modern_stylesheet, 'background: var(--surface) !important;') !== false
        && strpos($modern_stylesheet, 'color: var(--text) !important;') !== false,
    'Safe actions should use the shared outlined surface treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '.button-add, .save-button, .save-event-button, .button-save, .register-button, .login-button, .security-button, .add-org-button') !== false,
    'Creation, authentication, and security controls should use the same safe-action treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'background: var(--control-hover-bg) !important;') !== false
        && strpos($modern_stylesheet, 'border-color: var(--control-hover-border) !important;') !== false
        && strpos($modern_stylesheet, 'color: var(--control-hover-fg) !important;') !== false,
    'Safe-action hover and keyboard-focus states should use the pale blue control treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body :is(.sort-button, .filter-button):hover') !== false,
    'Search-row and pagination controls should use the modern themed hover rule.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body :is(.cancel-button, .button-cancel, .back-button):hover') !== false
        && strpos($modern_stylesheet, 'background: var(--control-hover-bg) !important;') !== false,
    'Cancel and Back controls should use the modern theme-aware hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '.button-secondary, .cancel-button, .button-cancel, .add-contact-btn, .add-presentation-btn, .archive-button, .restore-button):hover') !== false
        && strpos($footer_template, 'class="button-secondary"') !== false
        && strpos($footer_template, 'class="archive-button"') !== false,
    'Dialog and archive/restore workflow controls should use the same pale blue interaction treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body .export-button:hover') !== false
        && strpos($modern_stylesheet, 'background: var(--accent) !important;') !== false,
    'Copy and Download controls should use the modern theme-aware export treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body .reset-password-button:hover') !== false
        && strpos($modern_stylesheet, 'background: var(--action-archive) !important;') !== false,
    'Reset Password should use the modern theme-aware purple hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body .reset-two-factor-button:hover') !== false
        && strpos($modern_stylesheet, 'background: var(--action-reset-2fa) !important;') !== false,
    'Reset 2FA should use the modern theme-aware teal hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '.mobile-theme-button:hover') !== false
        && strpos($modern_stylesheet, '.theme-toggle-button:hover') !== false,
    'Desktop and mobile theme controls should use modern themed hover rules.'
);
expectHoverStyle(
    strpos($modern_stylesheet, ':is(#copy-calendar-url, #open-calendar-app):hover') !== false
        && strpos($modern_stylesheet, 'background: var(--control-hover-bg) !important;') !== false,
    'Calendar actions should share the modern theme-aware hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body #open-calendar-app:hover') === false,
    'Open in Calendar App should not override the shared pale blue hover with a solid fill.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '#copy-calendar-url.is-copied') !== false
        && strpos($calendar_subscription_page, "copyCalendarButton.textContent = 'Copied!';") !== false,
    'Copy URL should show an in-button success confirmation.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '#open-calendar-app.is-opening') !== false
        && strpos($calendar_subscription_page, "openCalendarLink.textContent = 'Opening…';") !== false,
    'Open in calendar app should show an in-button activation confirmation.'
);
expectHoverStyle(
    strpos($calendar_subscription_page, '>Open in Calendar App</a>') !== false
        && strpos($calendar_subscription_page, "openCalendarLink.textContent = 'Open in Calendar App';") !== false,
    'Open in Calendar App should use title case in its initial and restored labels.'
);

foreach (glob(__DIR__ . '/../src/*.php') as $page_path) {
    $page_source = file_get_contents($page_path);
    if (strpos($page_source, 'assets/css/style.min.css?v=') === false) {
        continue;
    }
    expectHoverStyle(
        strpos($page_source, 'assets/css/style.min.css?v=0.0.20') !== false,
        basename($page_path) . ' should use the current stylesheet cache key.'
    );
}

expectHoverStyle(
    strpos($audit_log_page, 'class="button-add">Back to Users</a>') !== false
        && strpos(file_get_contents(__DIR__ . '/../src/users.php'), 'background-color: #001489 !important;') === false,
    'Audit Log navigation controls should inherit the shared surface button style.'
);

foreach (['templates/header.php', 'login.php', 'recover_password.php', 'setup_2fa.php', 'verify_2fa.php', 'migrate_passwords.php'] as $page) {
    expectHoverStyle(
        strpos(file_get_contents(__DIR__ . '/../src/' . $page), 'assets/css/modern.min.css?v=0.1.55') !== false,
        $page . ' should use the current modern stylesheet cache key.'
    );
}

$interactive_markup_pattern = '/<(?:button\b|input\b[^>]*type=["\'](?:button|submit|reset)["\']|a\b[^>]*class=["\'][^"\']*(?:button|btn))/i';
foreach (glob(__DIR__ . '/../src/*.php') as $page_path) {
    $page_source = file_get_contents($page_path);
    if (stripos($page_source, '<!DOCTYPE html>') === false
        || preg_match($interactive_markup_pattern, $page_source) !== 1) {
        continue;
    }

    expectHoverStyle(
        strpos($page_source, "templates/header.php") !== false
            || strpos($page_source, 'assets/css/modern.min.css?v=0.1.55') !== false,
        basename($page_path) . ' has interactive controls but does not load the modern button system.'
    );
}

expectHoverStyle(
    strpos(file_get_contents(__DIR__ . '/../src/migrate_passwords.php'), 'class="security-button">Run migration</button>') !== false,
    'The standalone password migration page should classify its action with the shared safe-action style.'
);

echo "Button hover style tests passed.\n";
