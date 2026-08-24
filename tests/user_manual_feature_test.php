<?php

function expectUserManual(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "User manual feature test failed: {$message}\n");
        exit(1);
    }
}

$manual = file_get_contents(__DIR__ . '/../src/help.php');
$header = file_get_contents(__DIR__ . '/../src/templates/header.php');
$styles = file_get_contents(__DIR__ . '/../src/assets/css/pages/help.css');
$behavior = file_get_contents(__DIR__ . '/../src/assets/js/user-manual.js');
$package = file_get_contents(__DIR__ . '/../package.json');

foreach ([$manual, $header, $styles, $behavior, $package] as $source) {
    expectUserManual(is_string($source), 'all manual source files should be readable.');
}

expectUserManual(
    str_contains($manual, 'requireLogin();')
        && str_contains($manual, "\$manual_role = (string) (\$_SESSION['role'] ?? 'reviewer');")
        && str_contains($manual, "'assets/css/pages/help.min.css'")
        && str_contains($manual, "'assets/js/user-manual.min.js'"),
    'the manual should be authenticated, role-aware, and load committed production assets.'
);

$chapters = [
    'orientation',
    'roles',
    'dashboard',
    'engagements',
    'organizations-contacts',
    'work-queue',
    'chron-mail',
    'map-calendar',
    'profile-security',
    'administration',
    'troubleshooting',
];
foreach ($chapters as $chapter) {
    expectUserManual(
        str_contains($manual, 'id="' . $chapter . '" data-manual-section')
            && str_contains($manual, 'href="#' . $chapter . '" data-manual-toc'),
        "the {$chapter} chapter should have matching content and table-of-contents anchors."
    );
}
expectUserManual(
    substr_count($manual, 'data-manual-section') === count($chapters)
        && str_contains($manual, 'data-manual-search')
        && str_contains($manual, 'data-manual-status')
        && str_contains($manual, 'data-manual-empty'),
    'the searchable manual should expose all chapters and accessible result feedback.'
);

foreach ([
    'dashboard.php',
    'engagements.php',
    'organizations.php',
    'contacts.php',
    'tasks.php',
    'inbound_mail.php',
    'map.php',
    'calendar_subscription.php',
    'profile.php',
    'two_factor_settings.php',
    'users.php',
    'audit_log.php',
    'database_maintenance.php',
    'operations.php',
] as $destination) {
    expectUserManual(
        str_contains($manual, 'href="' . $destination . '"'),
        "the manual should link to {$destination}."
    );
}

expectUserManual(
    str_contains($manual, 'Reviewer')
        && str_contains($manual, 'Editor')
        && str_contains($manual, 'Administrator')
        && str_contains($manual, 'Lifecycle and confirmation are independent.')
        && str_contains($manual, '[MOED#123]')
        && str_contains($manual, 'Permanent Means Permanent'),
    'the manual should explain role boundaries, event state, email routing, and destructive actions.'
);

expectUserManual(
    str_contains($header, "'help' => ['help.php']")
        && str_contains($header, 'href="help.php"')
        && str_contains($header, '<span>User Manual</span>'),
    'the shared application shell should expose and activate the manual.'
);

expectUserManual(
    str_contains($styles, 'var(--surface)')
        && str_contains($styles, 'var(--accent)')
        && str_contains($styles, 'var(--primary-subtle)')
        && str_contains($styles, '@media (max-width: 700px)')
        && str_contains($styles, '@media (prefers-reduced-motion: no-preference)')
        && str_contains($styles, '@media print'),
    'manual styling should use the app theme, responsive layout, reduced-motion handling, and print support.'
);

expectUserManual(
    str_contains($behavior, "terms.every")
        && str_contains($behavior, "event.key === '/'")
        && str_contains($behavior, "event.key === 'Escape'")
        && str_contains($behavior, "IntersectionObserver")
        && str_contains($behavior, "status.textContent"),
    'manual behavior should provide multi-term search, keyboard access, chapter tracking, and live status updates.'
);

expectUserManual(
    str_contains($package, 'src/assets/js/user-manual.js')
        && str_contains($package, 'src/assets/js/user-manual.min.js'),
    'the production asset build should include the manual behavior.'
);

echo "User manual feature tests passed.\n";
