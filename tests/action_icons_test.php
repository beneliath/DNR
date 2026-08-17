<?php

require_once __DIR__ . '/../src/functions.php';

function expectActionIcon($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Action icon test failed: {$message}\n");
        exit(1);
    }
}

foreach (['view', 'edit', 'archive', 'restore', 'delete'] as $action) {
    $icon = actionIconSvg($action);
    expectActionIcon(str_contains($icon, '<svg'), "{$action} should render an SVG icon.");
    expectActionIcon(str_contains($icon, 'aria-hidden="true"'), "{$action} icon should be hidden from assistive technology.");
}

expectActionIcon(actionIconSvg('unknown') === '', 'Unknown actions should not render arbitrary markup.');

foreach (['engagements.php', 'organizations.php', 'contacts.php', 'users.php'] as $page) {
    $source = file_get_contents(__DIR__ . '/../src/' . $page);
    expectActionIcon(str_contains($source, 'action-icon-button'), "{$page} should use icon action buttons.");
    expectActionIcon(str_contains($source, 'data-tooltip='), "{$page} icon actions should include hover labels.");
    expectActionIcon(str_contains($source, 'aria-label='), "{$page} icon actions should include accessible names.");
}

$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
expectActionIcon(str_contains($modern_styles, '.action-icon-button::after'), 'Icon buttons should render custom hover and focus labels.');
expectActionIcon(str_contains($modern_styles, '--nav-hover-bg: #ccfbf1;'), 'Light-mode navigation hover surfaces should be clearly visible.');
expectActionIcon(str_contains($modern_styles, '--nav-hover-bg: #134e4a;'), 'Dark-mode navigation hover surfaces should be clearly visible.');
expectActionIcon(str_contains($modern_styles, '--action-edit: #0f766e;'), 'Light-mode edit actions should use teal instead of orange.');
expectActionIcon(str_contains($modern_styles, '--action-edit: #5eead4;'), 'Dark-mode edit actions should use a brighter aqua treatment.');
expectActionIcon(!str_contains($modern_styles, '--action-edit: #b54708;'), 'The legacy orange edit color should not remain in the icon system.');
expectActionIcon(str_contains($modern_styles, '--action-edit-bg: #ccfbf1;'), 'Light-mode icon hover surfaces should be clearly visible.');
expectActionIcon(str_contains($modern_styles, '--action-edit-bg: #134e4a;'), 'Dark-mode icon hover surfaces should be clearly visible.');
expectActionIcon(str_contains($modern_styles, 'var(--action-current) 20%'), 'Icon hover states should include a visible semantic focus ring.');

echo "Action icon tests passed.\n";
