<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/engagement_view_helpers.php';

function expectEngagementView(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement view helper test failed: {$message}\n");
        exit(1);
    }
}

$draft = [
    'confirmation_status' => 'work_in_progress',
    'lifecycle_status' => 'active',
    'event_start_date' => '2020-11-14',
    'event_end_date' => '2020-11-14',
    'financial_report' => ['status' => 'final'],
];
$progress = engagementViewProgress($draft);
expectEngagementView(
    array_column($progress['steps'], 'state') === ['current', 'pending', 'pending', 'pending']
        && $progress['exception'] === null,
    'past dates and financial data must not advance a draft engagement.'
);

$progress = engagementViewProgress(array_replace($draft, ['confirmation_status' => 'under_review']));
expectEngagementView(
    array_column($progress['steps'], 'state') === ['complete', 'current', 'pending', 'pending'],
    'the saved confirmation stage should identify the current step.'
);
$confirmed = array_replace($draft, ['confirmation_status' => 'confirmed']);
$progress = engagementViewProgress($confirmed);
expectEngagementView(
    array_column($progress['steps'], 'state') === ['complete', 'complete', 'current', 'pending'],
    'a confirmed engagement should remain confirmed until its lifecycle is completed.'
);
$progress = engagementViewProgress(array_replace($draft, ['lifecycle_status' => 'completed']));
expectEngagementView(
    array_column($progress['steps'], 'state') === ['complete', 'complete', 'complete', 'current']
        && $progress['exception'] === null,
    'the explicitly completed lifecycle should control the final step.'
);

foreach (['postponed' => 'Postponed', 'canceled' => 'Canceled'] as $lifecycle => $label) {
    $progress = engagementViewProgress(array_replace($confirmed, ['lifecycle_status' => $lifecycle]));
    expectEngagementView(
        array_unique(array_column($progress['steps'], 'state')) === ['pending']
            && $progress['exception'] === ['key' => $lifecycle, 'label' => $label],
        "{$label} should be shown separately without suggesting linear progression."
    );
}
expectEngagementView(
    engagementViewProgress(array_replace($confirmed, ['is_deleted' => 1])) === engagementViewProgress($confirmed),
    'archiving should preserve the saved workflow state.'
);
foreach ([['lifecycle_status' => 'unrecognized'], ['confirmation_status' => 'unrecognized']] as $unknown) {
    $progress = engagementViewProgress(array_replace($draft, $unknown));
    expectEngagementView(
        array_unique(array_column($progress['steps'], 'state')) === ['pending']
            && $progress['exception'] !== null,
        'unrecognized states must not imply a workflow step has been reached.'
    );
}

$complete_details = [
    'org_id' => 42,
    'organization_name' => 'Community Church',
    'event_start_date' => '2026-11-14',
    'event_end_date' => '2026-11-15',
    'event_address_line_1' => '123 Main Street',
];
$partial_presentation = [['id' => 9, 'topic_title' => '', 'presentation_date' => null, 'duration_minutes' => null]];
expectEngagementView(
    array_column(engagementViewReadiness($complete_details, [['id' => 7]], $partial_presentation), 'ready')
        === [true, true, true, true, true],
    'a partial presentation is enough for the informational presentation indicator.'
);
expectEngagementView(
    array_column(engagementViewReadiness(['event_country' => 'US'], [], []), 'ready')
        === [false, false, false, false, false],
    'a default country must not suggest that event location or other details have been recorded.'
);
expectEngagementView(
    engagementViewReadiness(array_replace($complete_details, ['organization_name' => ' ']), [], [])[0]['ready'] === false
        && engagementViewReadiness(['organization_name' => 'Community Church'], [], [])[0]['ready'] === false
        && engagementViewReadiness(['organization_id' => 42, 'organization_name' => 'Community Church'], [], [])[0]['ready'],
    'organization readiness needs both the linked ID and a nonempty organization name.'
);
foreach (['event_city', 'event_state'] as $location_field) {
    expectEngagementView(
        engagementViewReadiness([$location_field => 'Recorded'], [], [])[4]['ready'],
        'a city or state should count as recorded location information.'
    );
}
foreach ([
    [null, null],
    ['2026-11-14', null],
    ['2026-11-15', '2026-11-14'],
    ['2026-02-30', '2026-03-01'],
    ['0000-00-00', '2026-11-14'],
] as [$start, $end]) {
    expectEngagementView(
        !engagementViewReadiness(['event_start_date' => $start, 'event_end_date' => $end], [], [])[1]['ready'],
        'missing, reversed, or invalid date ranges must not count as set.'
    );
}

foreach ([
    [null, null, 'Dates not set'],
    ['', '', 'Dates not set'],
    ['2026-11-14', null, 'Starts Nov 14, 2026'],
    [null, '2026-11-14', 'Ends Nov 14, 2026'],
    ['2026-11-14', '2026-11-14', 'Nov 14, 2026'],
    ['2026-11-14', '2026-11-16', 'Nov 14–16, 2026'],
    ['2026-11-30', '2026-12-02', 'Nov 30–Dec 2, 2026'],
    ['2026-12-31', '2027-01-02', 'Dec 31, 2026–Jan 2, 2027'],
    ['2028-02-29', '2028-02-29', 'Feb 29, 2028'],
    ['2026-11-15', '2026-11-14', 'Dates need review'],
    ['2026-02-29', '2026-03-01', 'Dates need review'],
    ['yesterday', 'tomorrow', 'Dates need review'],
    ['0000-00-00', '0000-00-00', 'Dates need review'],
] as [$start, $end, $expected]) {
    expectEngagementView(
        engagementViewDateRange($start, $end) === $expected,
        "the calendar date label should be {$expected}."
    );
}

echo "Engagement view helper tests passed.\n";
