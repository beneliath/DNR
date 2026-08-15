<?php

function expectHoverStyle($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/style.css');
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
    strpos($stylesheet, ".sort-button.active,\n.filter-button.active {\n  background-color: var(--button-edit-color) !important;") !== false,
    'Currently selected filter controls should use the blue active color.'
);
expectHoverStyle(
    strpos($audit_log_page, "background-color: var(--button-edit-color) !important;") !== false,
    'The Audit Log should apply its active selector color without relying on a cached stylesheet.'
);
expectHoverStyle(
    strpos($stylesheet, "):hover {\n  background-color: var(--button-hover-color) !important;") !== false,
    'Shared selectable buttons should hover with the orange button color.'
);
expectHoverStyle(
    strpos($users_page, '.audit-log-link:hover') === false,
    'The Audit Log button must not override the shared orange hover color.'
);

foreach (glob(__DIR__ . '/../src/*.php') as $page_path) {
    $page_source = file_get_contents($page_path);
    if (strpos($page_source, 'assets/css/style.css?v=') === false) {
        continue;
    }
    expectHoverStyle(
        strpos($page_source, 'assets/css/style.css?v=0.0.11') !== false,
        basename($page_path) . ' should use the current stylesheet cache key.'
    );
}

echo "Button hover style tests passed.\n";
