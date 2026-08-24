<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/dashboard_helpers.php';

function expectDashboardHelper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Dashboard helper test failed: {$message}\n");
        exit(1);
    }
}

$missing_everything = dashboardEngagementReadinessIssues([
    'confirmation_status' => 'work_in_progress',
    'active_presentation_count' => 0,
    'assigned_contact_count' => 0,
]);
expectDashboardHelper(
    $missing_everything === [
        'Not confirmed',
        'Venue address missing',
        'No presentations',
        'No event contacts assigned',
    ],
    'readiness checks should identify every missing operational essential.'
);

expectDashboardHelper(
    dashboardEngagementReadinessIssues([
        'confirmation_status' => 'confirmed',
        'event_city' => 'Nashville',
        'active_presentation_count' => 2,
        'assigned_contact_count' => 1,
    ]) === [],
    'a confirmed, located event with presentations and a contact should be ready.'
);

expectDashboardHelper(
    dashboardGreetingName([
        'profile_first_name' => 'Mary Jane',
        'profile_display_name' => 'Mary Jane Watson',
        'username' => 'mjwatson',
    ]) === 'Mary Jane'
        && dashboardGreetingName([
            'profile_display_name' => 'David Gilmore',
            'username' => 'dgilmore',
        ]) === 'David'
        && dashboardGreetingName(['username' => 'dgilmore']) === 'dgilmore',
    'dashboard greetings should use only the profile first name with safe legacy fallbacks.'
);

expectDashboardHelper(
    dashboardEngagementLabel([
        'event_title' => '  ',
        'organization_name' => 'Grace Fellowship',
    ]) === 'Grace Fellowship',
    'an untitled event should use its organization as its dashboard label.'
);

expectDashboardHelper(
    dashboardDateRangeLabel('2026-08-23', '2026-08-23') === 'Aug 23, 2026'
        && dashboardDateRangeLabel('2026-08-23', '2026-08-25') === 'Aug 23 – Aug 25, 2026'
        && dashboardDateRangeLabel('', '') === 'Date not set',
    'dashboard date labels should handle single-day, multi-day, and missing dates.'
);

expectDashboardHelper(
    dashboardConfirmationStatusLabel('under_review') === 'Under review'
        && dashboardConfirmationStatusLabel('unexpected') === 'Status not set',
    'dashboard statuses should use stable user-facing labels.'
);

echo "Dashboard helper tests passed.\n";
