<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The digest preview generator is available only from the CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
putenv('DNR_CONFIG_FILE=' . $root . '/deployments/moed/application.yaml');
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.test');
putenv('DNR_REQUIRE_HTTPS=1');
putenv('DNR_TIMEZONE=America/Chicago');

require_once $root . '/src/email_helpers.php';
require_once $root . '/src/notification_helpers.php';

$overdueTask = [
    'id' => 743,
    'title' => 'Send final flight itinerary',
    'status' => 'in_progress',
    'priority' => 'high',
    'due_date' => '2026-09-02',
    'subject_type' => 'engagement',
    'engagement_id' => 284,
    'engagement_label' => 'Midwest Leadership Summit',
];
$todayTask = [
    'id' => 751,
    'title' => 'Reconfirm A/V setup',
    'status' => 'open',
    'priority' => 'urgent',
    'due_date' => '2026-09-03',
    'subject_type' => 'engagement',
    'engagement_id' => 291,
    'engagement_label' => 'Shalom in the City',
];
$upcomingTask = [
    'id' => 756,
    'title' => 'Print prophecy handouts',
    'status' => 'open',
    'priority' => 'normal',
    'due_date' => '2026-09-05',
    'subject_type' => 'engagement',
    'engagement_id' => 297,
    'engagement_label' => 'Prophecy Conference 2026',
];
$waitingTask = [
    'id' => 762,
    'title' => 'Venue contract countersignature',
    'status' => 'waiting',
    'priority' => 'high',
    'due_date' => null,
    'waiting_on' => 'venue director',
    'subject_type' => 'organization',
    'organization_id' => 88,
    'organization_label' => 'Grace Fellowship',
];
$closeouts = [[
    'id' => 268,
    'event_title' => 'Hope for Israel Weekend',
    'organization_name' => 'Calvary Chapel',
    'event_start_date' => '2026-08-27',
    'event_end_date' => '2026-08-29',
    'days_overdue' => 5,
], [
    'id' => 271,
    'event_title' => 'Prophecy & the Church',
    'organization_name' => 'Northside Bible Church',
    'event_start_date' => '2026-09-01',
    'event_end_date' => '2026-09-01',
    'days_overdue' => 2,
]];

$digest = [
    'counts' => [
        'active' => 4,
        'dashboard_overdue' => 1,
        'dashboard_today' => 1,
        'overdue' => 1,
        'today' => 1,
        'upcoming' => 1,
        'waiting' => 1,
        'closeouts' => 2,
        'total' => 6,
    ],
    'overdue' => [$overdueTask],
    'today' => [$todayTask],
    'upcoming' => [$upcomingTask],
    'waiting' => [$waitingTask],
    'undated' => [],
    'future' => [],
    'closeouts' => $closeouts,
    'dashboard' => [
        'upcoming_count' => 3,
        'upcoming_engagements' => [[
            'id' => 284,
            'event_title' => 'Midwest Leadership Summit',
            'organization_name' => 'Lakeside Community Church',
            'event_start_date' => '2026-09-08',
            'event_end_date' => '2026-09-10',
            'confirmation_status' => 'under_review',
            'readiness_issues' => ['Venue address missing', 'No event contacts assigned'],
        ], [
            'id' => 291,
            'event_title' => 'Shalom in the City',
            'organization_name' => 'Beth El Congregation',
            'event_start_date' => '2026-09-15',
            'event_end_date' => '2026-09-15',
            'confirmation_status' => 'confirmed',
            'readiness_issues' => [],
        ], [
            'id' => 297,
            'event_title' => 'Prophecy Conference 2026',
            'organization_name' => 'First Baptist Dallas',
            'event_start_date' => '2026-09-24',
            'event_end_date' => '2026-09-26',
            'confirmation_status' => 'work_in_progress',
            'readiness_issues' => ['No presentations'],
        ]],
        'task_summary' => ['active' => 4, 'overdue' => 1, 'today' => 1],
        'my_tasks' => [$overdueTask, $todayTask, $upcomingTask, $waitingTask],
        'readiness_items' => [[
            'id' => 284,
            'event_title' => 'Midwest Leadership Summit',
            'organization_name' => 'Lakeside Community Church',
            'readiness_issues' => ['Venue address missing', 'No event contacts assigned'],
        ], [
            'id' => 302,
            'event_title' => 'Israel Update Luncheon',
            'organization_name' => 'River City Bible Church',
            'readiness_issues' => ['No presentations'],
        ]],
        'financial_closeout_count' => 2,
        'financial_closeouts' => $closeouts,
        'inbound_review_count' => 3,
    ],
];

$message = dailyTaskDigestMessage([
    'email' => 'mary@example.test',
    'first_name' => 'Mary',
    'last_name' => 'Jones',
    'username' => 'mjones',
    'role' => 'editor',
], $digest, '2026-09-03');
$productionLogoUrl = dailyTaskDigestHtmlEscape(applicationPublicUrl(
    applicationBrandEmailLogo(),
    ['v' => applicationVersion()]
));
$previewLogoUrl = dailyTaskDigestHtmlEscape(
    '../src/' . applicationBrandEmailLogo() . '?v=' . rawurlencode(applicationVersion())
);
$previewHtml = str_replace(
    'src="' . $productionLogoUrl . '"',
    'src="' . $previewLogoUrl . '"',
    $message['html_body'],
    $logoReplacementCount
);
if ($logoReplacementCount !== 1) {
    throw new RuntimeException('Unable to map the digest logo into the local preview.');
}
$preview = $previewHtml . "\n";
$previewPath = $root . '/docs/daily-digest-preview.html';

if (in_array('--check', $argv, true)) {
    $current = is_file($previewPath) ? file_get_contents($previewPath) : false;
    if (!is_string($current) || !hash_equals($preview, $current)) {
        fwrite(STDERR, "Daily Digest preview is stale. Regenerate it with this script.\n");
        exit(1);
    }
    echo "Daily Digest preview is current.\n";
    exit(0);
}

if (file_put_contents($previewPath, $preview) === false) {
    fwrite(STDERR, "Unable to write {$previewPath}.\n");
    exit(1);
}
echo "Wrote {$previewPath}.\n";
