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
        'title' => 'Call the host',
        'priority' => 'urgent',
        'due_date' => '2026-08-22',
    ]],
    'today' => [[
        'title' => 'Send the itinerary',
        'priority' => 'high',
        'due_date' => '2026-08-23',
    ]],
    'upcoming' => [[
        'title' => 'Pack materials',
        'priority' => 'normal',
        'due_date' => '2026-08-27',
    ]],
    'waiting' => [[
        'title' => 'Confirm venue',
        'priority' => 'normal',
        'waiting_on' => 'the event coordinator',
    ]],
    'closeouts' => [[
        'event_title' => 'Summer Conference',
        'organization_name' => 'Example Church',
        'event_end_date' => '2026-08-20',
    ]],
];
$message = dailyTaskDigestMessage([
    'email' => 'Editor@Example.test',
    'first_name' => 'Mary',
    'last_name' => 'Jones',
    'username' => 'mjones',
], $digest, '2026-08-23');

expectTaskNotificationHelper(
    $message['recipient'] === 'editor@example.test'
        && str_contains($message['body'], 'Good day, Mary.')
        && !str_contains($message['body'], 'Mary Jones')
        && str_contains($message['body'], 'OVERDUE (1)')
        && str_contains($message['body'], 'DUE TODAY (1)')
        && str_contains($message['body'], 'NEXT 7 DAYS (1)')
        && str_contains($message['body'], 'WAITING (1)')
        && str_contains($message['body'], 'FINANCIAL CLOSEOUTS (1)')
        && str_contains($message['body'], 'waiting on: the event coordinator')
        && str_contains($message['body'], 'https://moed.example.test/tasks.php?view=my')
        && str_contains($message['body'], 'https://moed.example.test/profile.php'),
    'the digest should be personal, bounded by category, and link back to the work queue and settings.'
);

putenv('DNR_TIMEZONE');
putenv('DNR_PUBLIC_BASE_URL');
putenv('DNR_REQUIRE_HTTPS');

echo "Task notification helper tests passed.\n";
