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
    'mattermost',
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
    'standard_tasks.php',
    'inbound_mail.php',
    'map.php',
    'calendar_subscription.php',
    'profile.php',
    'two_factor_settings.php',
    'mattermost.php',
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
        && str_contains($manual, 'applicationInboundMarker(123)')
        && str_contains($manual, 'Permanent Means Permanent'),
    'the manual should explain role boundaries, event state, email routing, and destructive actions.'
);

expectUserManual(
    str_contains($manual, '/moed connect CODE')
        && str_contains($manual, '/moed today')
        && str_contains($manual, '/moed event search TEXT')
        && str_contains($manual, '/moed link-event ID')
        && str_contains($manual, 'MOED is authoritative')
        && str_contains($manual, '<strong>Message actions</strong> using the grid/apps icon')
        && str_contains($manual, 'not the three-dot menu')
        && str_contains($manual, '<strong>Add MOED task</strong> creates follow-up work')
        && str_contains($manual, 'Add to MOED Chron')
        && str_contains($manual, "adds the post to the linked engagement's history")
        && str_contains($manual, 'Opening or canceling the form writes nothing')
        && str_contains($manual, 'Reviewers stay read only')
        && str_contains($manual, 'Private by default')
        && str_contains($header, "'mattermost' => ['mattermost.php']")
        && str_contains($header, 'href="mattermost.php"'),
    'the manual and utility navigation should explain the Mattermost plugin workflow, privacy, and role boundaries.'
);

expectUserManual(
    str_contains($manual, 'PDF Slide Deck')
        && str_contains($manual, 'PDF up to 100 MB')
        && str_contains($manual, 'Speaker QR Codes')
        && str_contains($manual, 'speaker’s notes download, website, and donation page')
        && str_contains($manual, 'Paste QR code')
        && str_contains($manual, 'Save Changes')
        && str_contains($manual, 'copy icon immediately beside the marker'),
    'the manual should explain presentation assets, nearby saving, and routing-marker copying.'
);

expectUserManual(
    str_contains($manual, 'Send and Track Event Email')
        && str_contains($manual, 'Booking confirmation')
        && str_contains($manual, 'Select event contacts')
        && str_contains($manual, 'Every unique address receives a separate email')
        && str_contains($manual, 'share-safe brief')
        && str_contains($manual, 'Queue Email')
        && str_contains($manual, 'Retry failed deliveries')
        && str_contains($manual, 'Automatic Chron History')
        && str_contains($manual, 'Sending a booking, reconfirmation, or thank-you message')
        && str_contains($manual, 'How Inbound Email Finds Its Records')
        && str_contains($manual, 'An Outbound Email Failed or Cannot Be Queued'),
    'the manual should explain outbound correspondence as a concise workflow integrated with tasks, Chron, and inbound email.'
);

expectUserManual(
    str_contains($manual, 'Enter Organization Addresses')
        && str_contains($manual, 'countries worldwide')
        && str_contains($manual, 'Choose the country before the region')
        && str_contains($manual, 'United States of America')
        && str_contains($manual, 'Canadian address')
        && str_contains($manual, 'State/Province')
        && str_contains($manual, 'A contact can be saved without an organization.'),
    'the manual should explain international organization addresses and optional contact organizations.'
);

expectUserManual(
    str_contains($manual, 'Enter a required event title')
        && str_contains($manual, 'a valid start and end date')
        && str_contains($manual, 'every initial standard task is assigned to that Caller')
        && str_contains($manual, 'changing the engagement’s Caller later does not reassign')
        && str_contains($manual, 'reassign those tasks in the Work Queue')
        && str_contains($manual, 'tasks added this way are assigned to the user performing the action')
        && str_contains($manual, 'reschedules its active generated tasks')
        && str_contains($manual, 'due date was manually overridden keeps that date')
        && str_contains($manual, 'next <?php echo $manual_task_days; ?> days')
        && str_contains($manual, 'Confirm that the amounts are final')
        && str_contains($manual, 'all event-specific information')
        && str_contains($manual, 'Deleting a contact removes')
        && str_contains($manual, 'pending invitations can be deleted directly'),
    'the manual should prioritize accurate scheduling, ownership, reminders, and data-safety guidance.'
);

expectUserManual(
    str_contains($manual, 'Monthly Calendar')
        && str_contains($manual, '<strong>Previous</strong>')
        && str_contains($manual, '<strong>Next</strong>')
        && str_contains($manual, '<strong>Events</strong>')
        && str_contains($manual, '<strong>My Tasks</strong>')
        && str_contains($manual, '<strong>All Tasks</strong>')
        && str_contains($manual, '<strong>Everything</strong>')
        && str_contains($manual, 'Your task accent color.')
        && str_contains($manual, 'rose-colored cell'),
    'the manual should explain month navigation, calendar content filters, color coding, and the current-day highlight.'
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
