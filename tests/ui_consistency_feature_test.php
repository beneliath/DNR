<?php

function expectUiConsistency($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "UI consistency feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$modern = $read('src/assets/css/modern.css');
$legacy = $read('src/assets/css/style.css');
$booking = $read('src/assets/css/pages/booking_inquiries.css');
$engagementEmail = $read('src/assets/css/pages/engagement_email.css');
$pageActions = $read('src/assets/js/page-actions.js');

expectUiConsistency(
    str_contains($modern, '--app-content-max: 1680px;')
        && str_contains($modern, '--app-content-padding: clamp(22px, 3vw, 48px);')
        && preg_match('/\.container,\s*\.view-container\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $modern) === 1
        && preg_match('/\.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $modern) === 1,
    'the shared page canvas and footer should use the Dashboard-width layout contract.'
);

expectUiConsistency(
    preg_match('/\.profile-container\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $modern) === 1
        && !preg_match('/\.two-factor-settings-page \.app-footer\s*\{[^}]*960px/s', $modern),
    'wide account pages should not depend on page-stylesheet load order to override stale narrow fallbacks.'
);

expectUiConsistency(
    str_contains($modern, '--app-heading-size: clamp(1.8rem, 3vw, 2.3rem);')
        && preg_match('/h1\s*\{[^}]*font-size:\s*var\(--app-heading-size\);/s', $modern) === 1
        && preg_match('/\.page-heading\s*\{[^}]*border:\s*0 !important;[^}]*background:\s*transparent !important;/s', $modern) === 1,
    'page headings should share one font scale and a transparent masthead.'
);

expectUiConsistency(
    !str_contains($modern, 'html.dark-mode body div:not(')
        && !str_contains($legacy, '.dark-mode div:not(')
        && preg_match('/\.dark-mode main\s*\{[^}]*background-color:\s*transparent;/s', $legacy) === 1,
    'dark mode should preserve component surfaces instead of flattening every div.'
);

expectUiConsistency(
    str_contains($modern, '--status-pill-min-height: 26px;')
        && str_contains($modern, '.inquiry-stage-badge,')
        && str_contains($modern, '.dashboard-status,')
        && str_contains($modern, '.audit-badge,')
        && str_contains($modern, 'border-radius: 999px !important;')
        && str_contains($modern, 'font-variant-numeric: tabular-nums;'),
    'semantic status pills should share stable dimensions and number alignment.'
);

expectUiConsistency(
    str_contains($pageActions, 'function initializeStatusMessages()')
        && str_contains($pageActions, "message.setAttribute('role', 'alert')")
        && str_contains($pageActions, "message.setAttribute('role', 'status')"),
    'server feedback should be announced consistently by assistive technology.'
);

expectUiConsistency(
    str_contains($modern, '@media (prefers-reduced-motion: reduce)')
        && str_contains($pageActions, "window.matchMedia('(prefers-reduced-motion: reduce)').matches")
        && str_contains($pageActions, "behavior: reduceMotion ? 'auto' : 'smooth'"),
    'CSS and scripted scrolling should both honor reduced-motion preferences.'
);

foreach ([
    'src/assets/css/pages/engagements.css',
    'src/assets/css/pages/organizations.css',
    'src/assets/css/pages/contacts.css',
    'src/assets/css/pages/audit_log.css',
] as $tableStylesheet) {
    expectUiConsistency(
        !str_contains($read($tableStylesheet), 'border-bottom: 1px solid #ddd;')
            && str_contains($read($tableStylesheet), 'border-bottom: 1px solid var(--border);'),
        $tableStylesheet . ' should use theme-aware table separators.'
    );
}

foreach (glob($root . '/src/*.php') ?: [] as $pagePath) {
    $pageSource = (string) file_get_contents($pagePath);
    if (!str_contains($pageSource, '<!DOCTYPE html>')) {
        continue;
    }
    expectUiConsistency(
        str_contains($pageSource, '<main') || str_contains($pageSource, 'role="main"'),
        basename($pagePath) . ' should expose a main-content landmark.'
    );
}

expectUiConsistency(
    str_contains($booking, 'max-width: var(--app-content-max);')
        && str_contains($engagementEmail, 'width: min(100%, var(--app-content-max));')
        && str_contains($engagementEmail, 'max-width: var(--app-content-max);'),
    'the Booking Pipeline and engagement-email pages should use the shared wide canvas.'
);

foreach (glob($root . '/src/assets/css/pages/*.css') ?: [] as $pageStylesheet) {
    if (str_ends_with($pageStylesheet, '.min.css')) {
        continue;
    }
    $pageStyles = (string) file_get_contents($pageStylesheet);
    expectUiConsistency(
        !str_contains($pageStyles, 'max-width: 1680px;')
            && !str_contains($pageStyles, 'clamp(22px, 3vw, 48px)'),
        basename($pageStylesheet) . ' should consume shared canvas tokens instead of copying their values.'
    );
}

$register = $read('src/register.php');
$users = $read('src/users.php');
$inviteCss = $read('src/assets/css/pages/invite_user.css');
expectUiConsistency(
    str_contains($register, 'class="invite-user-body"')
        && str_contains($register, 'class="container invite-user-page"')
        && str_contains($register, 'class="invite-user-layout"')
        && str_contains($register, 'class="invite-user-guidance"')
        && str_contains($register, 'Administrator Confirmation Required')
        && str_contains($register, 'admin_elevation.php?return=register.php')
        && str_contains($register, "!\$admin_actions_unlocked ? ' disabled aria-describedby=\"invite-user-access-notice\"' : ''")
        && str_contains($users, 'href="register.php" class="button-add"')
        && !str_contains($users, 'Invite User (Locked)'),
    'administrators should be able to preview the themed Invite User page before unlocking the send action.'
);
expectUiConsistency(
    str_contains($inviteCss, 'grid-template-columns: minmax(0, 1.55fr) minmax(320px, 0.85fr);')
        && str_contains($inviteCss, 'background: var(--surface) !important;')
        && str_contains($inviteCss, 'background: var(--surface-subtle) !important;')
        && str_contains($inviteCss, '@media (max-width: 960px)'),
    'the Invite User form should use responsive, theme-aware surfaced panes.'
);

$calendarPage = $read('src/view_calendar.php');
$legacyCalendarRoute = $read('src/calendar_subscription.php');
$calendarFeed = $read('src/calendar.php');
expectUiConsistency(
    str_contains($calendarPage, "header('Location: view_calendar.php')")
        && str_contains($calendarPage, 'action="view_calendar.php"')
        && str_contains($legacyCalendarRoute, "header('Location: view_calendar.php'")
        && str_contains($legacyCalendarRoute, 'true, 308')
        && str_contains($calendarFeed, "header('Content-Type: text/calendar; charset=utf-8')"),
    'view_calendar.php should own the Calendar UI while calendar.php remains the ICS feed.'
);

$titleCaseActions = [
    'src/view_contact.php' => ['Edit Contact'],
    'src/view_organization.php' => ['Edit Organization', '+ New Contact'],
    'src/view_engagement.php' => ['Send Email', 'Edit Engagement', 'Open Source Inquiry', 'Close Out Event'],
    'src/tasks.php' => ['Standard Event Tasks', '+ New Task'],
    'src/templates/follow_up_task_section.php' => ['View in Work Queue', 'Add Missing Checklist Tasks'],
];
foreach ($titleCaseActions as $page => $labels) {
    $source = $read($page);
    foreach ($labels as $label) {
        expectUiConsistency(
            str_contains($source, $label),
            $page . ' should retain the Title Case action label “' . $label . '”.'
        );
    }
}

echo "UI consistency feature tests passed.\n";
