<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/application_runtime.php';
require_once __DIR__ . '/../src/email_helpers.php';
require_once __DIR__ . '/../src/notification_helpers.php';

function expectTaskNotificationHelper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Task notification helper test failed: {$message}\n");
        exit(1);
    }
}

putenv('DNR_TIMEZONE=America/Chicago');
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.test');
putenv('DNR_REQUIRE_HTTPS=1');

expectTaskNotificationHelper(
    !taskDigestScheduleIsDue(
        '07:30:00',
        TASK_DIGEST_WEEKENDS,
        new DateTimeImmutable('2026-08-23 12:29:00', new DateTimeZone('UTC'))
    )
        && taskDigestScheduleIsDue(
            '07:30:00',
            TASK_DIGEST_WEEKENDS,
            new DateTimeImmutable('2026-08-23 12:30:00', new DateTimeZone('UTC'))
        )
        && !taskDigestScheduleIsDue(
            '07:30:00',
            TASK_DIGEST_WEEKDAYS,
            new DateTimeImmutable('2026-08-23 13:00:00', new DateTimeZone('UTC'))
        ),
    'digest scheduling should follow each user time, selected days, and the business timezone.'
);
expectTaskNotificationHelper(
    taskDigestDeliveryTimeFromInput('16:45') === '16:45:00'
        && taskDigestDeliveryTimeInputValue('16:45:00') === '16:45'
        && taskDigestDaysFromInput(['1', '4', '64']) === 69,
    'profile schedule values should normalize to database time and a bounded weekday bit mask.'
);
$invalidScheduleRejected = false;
try {
    taskDigestDaysFromInput([]);
} catch (InvalidArgumentException $exception) {
    $invalidScheduleRejected = true;
}
expectTaskNotificationHelper(
    $invalidScheduleRejected,
    'a digest schedule should require at least one delivery day.'
);

$digest = [
    'counts' => [
        'overdue' => 1,
        'today' => 1,
        'upcoming' => 1,
        'waiting' => 1,
        'closeouts' => 1,
        'total' => 5,
    ],
    'overdue' => [[
        'id' => 41,
        'title' => 'Call <the> host & confirm',
        'status' => 'open',
        'priority' => 'urgent',
        'due_date' => '2026-08-22',
        'subject_type' => 'engagement',
        'engagement_id' => 11,
        'engagement_label' => 'Summer Conference',
    ]],
    'today' => [[
        'id' => 42,
        'title' => 'Send the itinerary',
        'status' => 'in_progress',
        'priority' => 'high',
        'due_date' => '2026-08-23',
        'subject_type' => 'general',
    ]],
    'upcoming' => [[
        'id' => 43,
        'title' => 'Pack materials',
        'status' => 'open',
        'priority' => 'normal',
        'due_date' => '2026-08-27',
        'subject_type' => 'general',
    ]],
    'waiting' => [[
        'id' => 44,
        'title' => 'Confirm venue',
        'status' => 'waiting',
        'priority' => 'normal',
        'waiting_on' => 'the event coordinator',
        'subject_type' => 'organization',
        'organization_id' => 12,
        'organization_label' => 'Example Church',
    ]],
    'closeouts' => [[
        'id' => 15,
        'event_title' => 'Summer Conference',
        'organization_name' => 'Example Church',
        'event_start_date' => '2026-08-18',
        'event_end_date' => '2026-08-20',
        'days_overdue' => 3,
    ]],
];
$digest['dashboard'] = [
    'upcoming_count' => 1,
    'upcoming_engagements' => [[
        'id' => 11,
        'event_title' => 'Summer Conference',
        'organization_name' => 'Example Church',
        'event_start_date' => '2026-08-25',
        'event_end_date' => '2026-08-27',
        'confirmation_status' => 'under_review',
        'readiness_issues' => ['Venue address missing'],
    ]],
    'task_summary' => ['active' => 4, 'overdue' => 1, 'today' => 1],
    'my_tasks' => array_merge(
        $digest['overdue'],
        $digest['today'],
        $digest['upcoming'],
        $digest['waiting']
    ),
    'readiness_items' => [[
        'id' => 11,
        'event_title' => 'Summer Conference',
        'organization_name' => 'Example Church',
        'readiness_issues' => ['Venue address missing', 'No presentations'],
    ]],
    'financial_closeout_count' => 1,
    'financial_closeouts' => $digest['closeouts'],
    'inbound_review_count' => 2,
];
$message = dailyTaskDigestMessage([
    'email' => 'Editor@Example.test',
    'first_name' => 'Mary',
    'last_name' => 'Jones',
    'username' => 'mjones',
    'role' => 'editor',
], $digest, '2026-08-23');

expectTaskNotificationHelper(
    $message['recipient'] === 'editor@example.test'
        && str_contains($message['body'], 'Good day, Mary.')
        && !str_contains($message['body'], 'Mary Jones')
        && str_contains($message['body'], 'DASHBOARD SUMMARY')
        && str_contains($message['body'], 'UPCOMING ENGAGEMENTS (1)')
        && str_contains($message['body'], 'MY WORK (4)')
        && str_contains($message['body'], 'EVENT READINESS (1)')
        && str_contains($message['body'], 'FINANCIAL CLOSEOUTS (1)')
        && str_contains($message['body'], 'Waiting on: the event coordinator')
        && str_contains($message['body'], 'Call <the> host & confirm — Overdue · 2026-08-22')
        && str_contains($message['body'], 'Mail awaiting review: 2')
        && str_contains($message['body'], 'https://moed.example.test/tasks.php?view=my')
        && str_contains($message['body'], 'https://moed.example.test/dashboard.php')
        && str_contains($message['body'], 'https://moed.example.test/profile.php'),
    'the text alternative should contain the same personal Dashboard snapshot and navigation as the HTML digest.'
);
expectTaskNotificationHelper(
    str_starts_with($message['html_body'], '<!doctype html>')
        && str_contains($message['html_body'], 'name="color-scheme" content="light only"')
        && str_contains($message['html_body'], 'color-scheme: light only')
        && str_contains($message['html_body'], 'Upcoming Engagements')
        && str_contains($message['html_body'], 'My Work')
        && str_contains($message['html_body'], 'Needs Attention')
        && str_contains($message['html_body'], 'bgcolor="#ffe8ee"')
        && str_contains($message['html_body'], 'bgcolor="#d92d20"')
        && str_contains($message['html_body'], 'bgcolor="#e4f2ff"')
        && str_contains($message['html_body'], 'bgcolor="#2563eb"')
        && str_contains(
            $message['html_body'],
            'https://moed.example.test/edit_task.php?id=41&amp;return_to=dashboard.php'
        )
        && str_contains(
            $message['html_body'],
            'https://moed.example.test/view_engagement.php?id=11'
        )
        && str_contains(
            $message['html_body'],
            'https://moed.example.test/inbound_mail.php?status=review'
        )
        && str_contains($message['html_body'], 'aria-label="ASCII art cat"')
        && str_contains($message['html_body'], 'Genesis 49:9,10 ... Revelation 5:5')
        && str_contains($message['html_body'], '<br>Do you see Him?</div>')
        && str_contains($message['html_body'], 'display:inline-block')
        && str_contains($message['html_body'], 'text-align:left;white-space:pre')
        && str_contains($message['html_body'], 'opacity:0.5')
        && str_contains($message['html_body'], 'Call &lt;the&gt; host &amp; confirm')
        && !str_contains($message['html_body'], 'Call <the> host & confirm'),
    'the HTML alternative should mirror the light Dashboard, link its records, escape data, and preserve the exact due-date highlights.'
);

$reviewerMessage = dailyTaskDigestMessage([
    'email' => 'reviewer@example.test',
    'first_name' => 'Riley',
    'username' => 'riley',
    'role' => 'reviewer',
], $digest, '2026-08-23');
expectTaskNotificationHelper(
    str_contains($reviewerMessage['body'], 'FINANCIAL CLOSEOUTS (1)')
        && str_contains(
            $reviewerMessage['body'],
            'Open: https://moed.example.test/view_engagement.php?id=11'
        )
        && !str_contains($reviewerMessage['body'], 'Mail awaiting review')
        && !str_contains($reviewerMessage['body'], 'close_engagement.php')
        && str_contains(
            $reviewerMessage['html_body'],
            'https://moed.example.test/view_engagement.php?id=11'
        )
        && str_contains(
            $reviewerMessage['html_body'],
            'https://moed.example.test/view_engagement.php?id=15'
        )
        && !str_contains($reviewerMessage['html_body'], 'edit_task.php')
        && !str_contains($reviewerMessage['html_body'], 'edit_engagement.php')
        && !str_contains($reviewerMessage['html_body'], 'close_engagement.php')
        && !str_contains($reviewerMessage['html_body'], 'inbound_mail.php')
        && !str_contains($reviewerMessage['html_body'], '+ New task')
        && !str_contains($reviewerMessage['html_body'], '+ New engagement'),
    'reviewer digests should preserve Dashboard visibility while linking only to pages that role can use.'
);

putenv('DNR_TIMEZONE');
putenv('DNR_PUBLIC_BASE_URL');
putenv('DNR_REQUIRE_HTTPS');

echo "Task notification helper tests passed.\n";
