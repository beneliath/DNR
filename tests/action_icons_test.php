<?php

require_once __DIR__ . '/../src/functions.php';

function expectActionIcon($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Action icon test failed: {$message}\n");
        exit(1);
    }
}

foreach (['view', 'edit', 'start', 'complete', 'archive', 'restore', 'delete'] as $action) {
    $icon = actionIconSvg($action);
    expectActionIcon(str_contains($icon, '<svg'), "{$action} should render an SVG icon.");
    expectActionIcon(str_contains($icon, 'aria-hidden="true"'), "{$action} icon should be hidden from assistive technology.");
}

expectActionIcon(actionIconSvg('unknown') === '', 'Unknown actions should not render arbitrary markup.');

foreach (['engagements.php', 'organizations.php', 'contacts.php', 'users.php', 'tasks.php'] as $page) {
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
expectActionIcon(str_contains($modern_styles, '--action-start: #175cd3;'), 'Start actions should use the blue action treatment.');
expectActionIcon(str_contains($modern_styles, '--action-complete: #067647;'), 'Complete actions should use the green action treatment.');
expectActionIcon(str_contains($modern_styles, 'var(--action-current) 20%'), 'Icon hover states should include a visible semantic focus ring.');
expectActionIcon(
    str_contains($modern_styles, ".task-table .task-actions {\n  display: inline-flex !important;")
        && str_contains($modern_styles, 'flex-flow: row nowrap !important;')
        && str_contains($modern_styles, ".task-table .task-actions > * {\n  flex: 0 0 auto !important;")
        && str_contains($modern_styles, '.task-table th:last-child,')
        && str_contains($modern_styles, 'min-width: 220px;')
        && str_contains($modern_styles, '.task-table tbody tr:hover'),
    'Work Queue actions should remain on one line and task rows should use the shared table hover treatment.'
);

$task_source = file_get_contents(__DIR__ . '/../src/tasks.php');
expectActionIcon(str_contains($task_source, 'aria-label="Start task"'), 'Start task controls should have an accessible name.');
expectActionIcon(str_contains($task_source, "actionIconSvg('start')"), 'Start task controls should render the shared play icon.');
expectActionIcon(str_contains($task_source, 'aria-label="Complete task"'), 'Complete task controls should have an accessible name.');
expectActionIcon(str_contains($task_source, "actionIconSvg('complete')"), 'Complete task controls should render the shared completion icon.');
expectActionIcon(str_contains($task_source, 'aria-label="Reopen task"'), 'Reopen task controls should have an accessible name.');
expectActionIcon(
    str_contains($task_source, 'data-tooltip="Reopen"')
        && str_contains($task_source, "actionIconSvg('restore')"),
    'Reopen task controls should use the shared restore icon and tooltip treatment.'
);

$context_task_source = file_get_contents(__DIR__ . '/../src/templates/follow_up_task_section.php');
expectActionIcon(
    str_contains($context_task_source, 'class="action-button action-icon-button edit-button"')
        && str_contains($context_task_source, 'aria-label="Edit task"')
        && str_contains($context_task_source, "actionIconSvg('edit')"),
    'Record-level follow-up cards should use the shared accessible edit icon treatment.'
);

echo "Action icon tests passed.\n";
