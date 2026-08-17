<?php

function expectHoverStyle($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/style.css');
$modern_stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$users_page = file_get_contents(__DIR__ . '/../src/users.php');
$audit_log_page = file_get_contents(__DIR__ . '/../src/audit_log.php');

expectHoverStyle(
    strpos($stylesheet, 'html body :is(') !== false,
    'The shared hover selector should outrank page-local active button colors.'
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
    strpos($stylesheet, ':not(.nav-link):not(.mobile-menu-button):not(.mobile-theme-button):not(.sidebar-logout-button):not(.sort-button):not(.filter-button)') !== false,
    'Modern navigation, theme, and list controls should be excluded from the legacy orange hover rule.'
);
$legacy_hover_block = '';
if (($legacy_hover_start = strpos($stylesheet, 'Consistent button hover feedback')) !== false) {
    $legacy_hover_end = strpos($stylesheet, '/* Ensure login container', $legacy_hover_start);
    $legacy_hover_block = substr($stylesheet, $legacy_hover_start, $legacy_hover_end - $legacy_hover_start);
}
expectHoverStyle(
    $legacy_hover_block !== ''
        && strpos($legacy_hover_block, "\n  .button-add,") === false
        && strpos($legacy_hover_block, ':not(.button-add)') !== false,
    'Primary New buttons should be excluded from the legacy orange hover rule.'
);
foreach (['.add-org-button', '.save-button', '.save-event-button'] as $modern_primary_class) {
    expectHoverStyle(
        strpos($legacy_hover_block, "\n  {$modern_primary_class},") === false
            && strpos($legacy_hover_block, ":not({$modern_primary_class})") !== false,
        $modern_primary_class . ' should be excluded from the legacy orange hover rule.'
    );
}
expectHoverStyle(
    strpos($legacy_hover_block, "\n  .register-button,") === false
        && strpos($legacy_hover_block, ':not(.register-button)') !== false,
    'The Create User button should be excluded from the legacy orange hover rule.'
);
foreach (['.cancel-button', '.button-cancel'] as $modern_cancel_class) {
    expectHoverStyle(
        strpos($legacy_hover_block, "\n  {$modern_cancel_class},") === false,
        $modern_cancel_class . ' should be excluded from the legacy orange hover rule.'
    );
}
expectHoverStyle(
    strpos($modern_stylesheet, 'html body .button-add:hover') !== false
        && strpos($modern_stylesheet, 'background: var(--primary-hover) !important;') !== false,
    'Primary New buttons should use the modern themed hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body :is(.add-org-button, .save-button, .save-event-button, .register-button):hover') !== false,
    'Organization, event, and user creation controls should use the same modern themed hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'box-shadow: 0 8px 18px color-mix(in srgb, var(--primary) 24%, transparent) !important;') !== false,
    'The shared creation-button hover treatment should include its elevated shadow.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body :is(.sort-button, .filter-button):hover') !== false,
    'Search-row and pagination controls should use the modern themed hover rule.'
);
expectHoverStyle(
    strpos($modern_stylesheet, 'html body :is(.cancel-button, .button-cancel):hover') !== false
        && strpos($modern_stylesheet, 'background: var(--control-hover-bg) !important;') !== false,
    'Cancel controls should use the modern theme-aware hover treatment.'
);
expectHoverStyle(
    strpos($modern_stylesheet, '.mobile-theme-button:hover') !== false
        && strpos($modern_stylesheet, '.theme-toggle-button:hover') !== false,
    'Desktop and mobile theme controls should use modern themed hover rules.'
);

foreach (glob(__DIR__ . '/../src/*.php') as $page_path) {
    $page_source = file_get_contents($page_path);
    if (strpos($page_source, 'assets/css/style.css?v=') === false) {
        continue;
    }
    expectHoverStyle(
        strpos($page_source, 'assets/css/style.css?v=0.0.17') !== false,
        basename($page_path) . ' should use the current stylesheet cache key.'
    );
}

echo "Button hover style tests passed.\n";
