<?php

require_once __DIR__ . '/../src/calendar_helpers.php';

function expectCalendar($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$common = [
    'id' => 42,
    'event_title' => 'Annual Leadership Summit',
    'organization_name' => 'Example, Inc.',
    'event_type' => 'conference',
    'confirmation_status' => 'under_review',
    'lifecycle_status' => 'active',
    'event_address_line_1' => '123 Main St',
    'event_address_line_2' => '',
    'event_city' => 'Chicago',
    'event_state' => 'IL',
    'event_zipcode' => '60601',
    'event_country' => 'USA',
    'calendar_updated_at' => 1786708800,
    'engagement_notes' => 'This private note must not appear.',
];

$all_day = $common + [
    'event_start_date' => '2026-08-14',
    'event_end_date' => '2026-08-15',
];
$all_day_calendar = buildCalendar([$all_day]);
$unfolded_all_day_calendar = str_replace("\r\n ", '', $all_day_calendar);
expectCalendar(
    str_contains($all_day_calendar, "SUMMARY:Under Review-Annual Leadership Summit-conference\r\n"),
    'The calendar summary should use the readable status, event title, and event type.'
);
expectCalendar(
    str_contains($unfolded_all_day_calendar, 'Event title: Annual Leadership Summit'),
    'The event title should be included in the calendar description.'
);
expectCalendar(
    str_contains($all_day_calendar, "DTSTART;VALUE=DATE:20260814\r\nDTEND;VALUE=DATE:20260816\r\n"),
    'All-day events should use an exclusive end date.'
);
expectCalendar(
    !str_contains($all_day_calendar, "\r\nDTSTART:") && !str_contains($all_day_calendar, "\r\nDTEND:"),
    'Calendar entries must never contain timed start or end values.'
);
expectCalendar(
    str_contains($all_day_calendar, "SEQUENCE:208872000\r\n"),
    'Events should carry a monotonic revision sequence derived from their update time.'
);
expectCalendar(
    !str_contains($all_day_calendar, 'This private note must not appear.'),
    'Private engagement notes must not be included in the public feed.'
);
expectCalendar(
    str_contains($all_day_calendar, "LOCATION:123 Main St\\, Chicago\\, IL 60601\\, USA\r\n"),
    'Calendar locations should be assembled and escaped.'
);

$canceled_calendar = buildCalendar([array_merge($all_day, [
    'lifecycle_status' => 'canceled',
    'cancellation_reason' => 'Venue closed unexpectedly',
    'rescheduled_event_title' => 'Leadership Summit — New Date',
    'rescheduled_event_start_date' => '2026-09-18',
])]);
$unfolded_canceled_calendar = str_replace("\r\n ", '', $canceled_calendar);
expectCalendar(
    str_contains($canceled_calendar, "STATUS:CANCELLED\r\n")
        && str_contains($canceled_calendar, "TRANSP:TRANSPARENT\r\n")
        && str_contains($unfolded_canceled_calendar, 'SUMMARY:Canceled-Annual Leadership Summit-conference')
        && str_contains($unfolded_canceled_calendar, 'Cancellation reason: Venue closed unexpectedly')
        && str_contains($unfolded_canceled_calendar, 'Rescheduled as: Leadership Summit — New Date · 2026-09-18'),
    'Canceled engagements should publish a transparent cancellation with reason and replacement context.'
);

$legacy_calendar = buildCalendar([array_merge($all_day, ['event_title' => ''])]);
expectCalendar(
    str_contains($legacy_calendar, "SUMMARY:Under Review-Example\\, Inc.-conference\r\n"),
    'Engagements without a title should use the readable status, organization, and event type.'
);

$presentation = array_merge($common, [
    'id' => 7,
    'engagement_id' => 42,
    'topic_title' => 'Opening Keynote',
    'speaker_name' => 'Jordan Speaker',
    'presentation_date' => '2026-08-21',
    'presentation_time' => '09:30 AM',
    'duration_minutes' => 90,
]);
$calendar_with_presentation = buildCalendar(
    [$all_day],
    'DNR Events',
    [$presentation],
    'America/Chicago'
);
$unfolded_presentation_calendar = str_replace("\r\n ", '', $calendar_with_presentation);
expectCalendar(
    str_contains($calendar_with_presentation, "UID:presentation-7@dnr-calendar\r\n")
        && str_contains($calendar_with_presentation, "RELATED-TO:engagement-42@dnr-calendar\r\n"),
    'Presentation events should have stable IDs and relate back to their engagement.'
);
expectCalendar(
    str_contains($calendar_with_presentation, "DTSTART:20260821T143000Z\r\n")
        && str_contains($calendar_with_presentation, "DTEND:20260821T160000Z\r\n"),
    'Presentation events should use their configured duration at their local date and time.'
);
expectCalendar(
    str_contains($calendar_with_presentation, "UID:presentation-7@dnr-calendar\r\n")
        && str_contains($calendar_with_presentation, "SEQUENCE:208872001\r\n"),
    'Presentation events should advance their sequence for the calendar-title format revision.'
);
expectCalendar(
    str_contains($unfolded_presentation_calendar, 'SUMMARY:Presentation-Opening Keynote-Jordan Speaker'),
    'Presentation event names should use Presentation-[Title]-[Speaker Name].'
);
expectCalendar(
    str_contains($unfolded_presentation_calendar, 'Speaker: Jordan Speaker'),
    'Presentation events should include the speaker in their description.'
);
expectCalendar(
    calendarPresentationEventLines(array_merge($presentation, ['presentation_time' => ''])) === [],
    'Presentations without both a date and time should not create timed calendar blocks.'
);
expectCalendar(
    str_contains(
        implode("\r\n", calendarPresentationEventLines(array_merge($presentation, ['speaker_name' => '']))),
        'SUMMARY:Presentation-Opening Keynote-Unknown Speaker'
    ),
    'Presentation event names should retain the requested format when the speaker is blank.'
);

foreach (explode("\r\n", $calendar_with_presentation) as $line) {
    expectCalendar(strlen($line) <= 75, 'Generated iCalendar lines must be folded to 75 octets or fewer.');
}

$month_context = calendarMonthContext('2026-08', '2026-08-25');
expectCalendar(
    $month_context['label'] === 'August 2026'
        && $month_context['previous_month'] === '2026-07'
        && $month_context['next_month'] === '2026-09'
        && $month_context['grid_start'] === '2026-07-26'
        && $month_context['grid_end'] === '2026-09-05'
        && count($month_context['days']) === 42,
    'The graphical calendar should build complete Sunday-through-Saturday month grids.'
);
expectCalendar(
    calendarMonthContext('not-a-month', '2026-08-25')['month'] === '2026-08',
    'Invalid month navigation values should fall back to the current business month.'
);
expectCalendar(
    array_keys(calendarViewerModes()) === ['events', 'my_tasks', 'all_tasks', 'everything']
        && normalizeCalendarViewerMode('my_tasks') === 'my_tasks'
        && normalizeCalendarViewerMode('invalid') === 'everything',
    'Calendar content selectors should use a fixed allowlist and default to everything.'
);
$day_context = calendarDayContext('2026-08-25', '2026-08-25');
expectCalendar(
    $day_context['label'] === 'Tuesday, August 25, 2026'
        && $day_context['previous_day'] === '2026-08-24'
        && $day_context['next_day'] === '2026-08-26'
        && $day_context['is_today'] === true
        && calendarDayContext('invalid', '2026-08-25')['date'] === '2026-08-25',
    'The daily agenda should move one date at a time and safely fall back to today.'
);
expectCalendar(
    calendarViewerPageUrl('2026-09', 'everything')
        === 'calendar_subscription.php?month=2026-09#event-calendar'
        && calendarViewerPageUrl(null, 'my_tasks')
        === 'calendar_subscription.php?show=my_tasks#event-calendar'
        && calendarViewerPageUrl(null, 'everything', '2026-08-26')
        === 'calendar_subscription.php?day=2026-08-26#event-calendar'
        && calendarViewerPageUrl(null, 'events', 'invalid')
        === 'calendar_subscription.php?show=events#event-calendar'
        && calendarViewerPageUrl('invalid', 'events')
        === 'calendar_subscription.php?show=events#event-calendar',
    'Calendar navigation should preserve selected content without accepting invalid month or day values.'
);

$viewer_events = [
    [
        'id' => 1,
        'event_title' => 'Cross-month event',
        'organization_name' => 'Example Org',
        'event_start_date' => '2026-07-31',
        'event_end_date' => '2026-08-02',
        'confirmation_status' => 'confirmed',
        'lifecycle_status' => 'active',
    ],
    [
        'id' => 2,
        'event_title' => '',
        'organization_name' => 'Single Day Org',
        'event_start_date' => '2026-08-15',
        'event_end_date' => null,
        'confirmation_status' => 'under_review',
        'lifecycle_status' => 'active',
    ],
    [
        'id' => 3,
        'event_title' => 'Invalid event',
        'event_start_date' => 'invalid',
        'event_end_date' => '2026-08-20',
    ],
];
$viewer_events_by_date = calendarEventsByDate(
    $viewer_events,
    $month_context['grid_start'],
    $month_context['grid_end']
);
expectCalendar(
    count($viewer_events_by_date['2026-07-31']) === 1
        && count($viewer_events_by_date['2026-08-01']) === 1
        && count($viewer_events_by_date['2026-08-02']) === 1
        && count($viewer_events_by_date['2026-08-15']) === 1
        && !isset($viewer_events_by_date['invalid']),
    'Multi-day events should appear on every visible date while invalid dates are ignored.'
);
expectCalendar(
    calendarViewerEventLabel($viewer_events[1]) === 'Single Day Org'
        && calendarViewerEventTone($viewer_events[0]) === 'confirmed'
        && calendarViewerEventTone($viewer_events[1]) === 'tentative'
        && calendarViewerEventTone(['lifecycle_status' => 'canceled']) === 'canceled',
    'Calendar event labels and visual status tones should remain stable.'
);

$viewer_tasks = [
    [
        'id' => 11,
        'title' => 'Send itinerary',
        'due_date' => '2026-08-15',
        'assigned_to' => 7,
    ],
    [
        'id' => 12,
        'title' => '',
        'due_date' => '2026-08-16',
        'assigned_to' => 9,
    ],
    [
        'id' => 13,
        'title' => 'Outside the grid',
        'due_date' => '2026-09-20',
        'assigned_to' => null,
    ],
    [
        'id' => 14,
        'title' => 'Invalid date',
        'due_date' => 'invalid',
        'assigned_to' => 7,
    ],
];
$viewer_tasks_by_date = calendarTasksByDate(
    $viewer_tasks,
    $month_context['grid_start'],
    $month_context['grid_end']
);
expectCalendar(
    count($viewer_tasks_by_date['2026-08-15']) === 1
        && count($viewer_tasks_by_date['2026-08-16']) === 1
        && !isset($viewer_tasks_by_date['2026-09-20'])
        && !isset($viewer_tasks_by_date['invalid']),
    'Calendar tasks should appear on their valid due dates inside the visible grid.'
);
expectCalendar(
    calendarViewerTaskLabel($viewer_tasks[0]) === 'Send itinerary'
        && calendarViewerTaskLabel($viewer_tasks[1]) === 'Untitled task'
        && calendarViewerTaskTone($viewer_tasks[0], 7) === 'mine'
        && calendarViewerTaskTone($viewer_tasks[1], 7) === 'other',
    'Calendar task labels and ownership color tones should remain stable.'
);

putenv('DNR_PUBLIC_BASE_URL=https://calendar.example.org/dnr');
$calendar_token = str_repeat('a', 32);
$url = calendarSubscriptionUrl([
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_HOST' => 'dnr.example.org',
    'SCRIPT_NAME' => '/calendar_subscription.php',
], $calendar_token);
expectCalendar(
    $url === 'https://calendar.example.org/dnr/calendar.php?token=' . $calendar_token,
    'The subscription URL should use the configured public base and revocable token.'
);
expectCalendar(
    is_string(calendarTokenHash($calendar_token))
        && strlen(calendarTokenHash($calendar_token)) === 32
        && calendarTokenHash('short') === null
        && !hash_equals(calendarTokenHash($calendar_token), calendarTokenHash(str_repeat('b', 32))),
    'Calendar bearer tokens should use fixed-size digests and reject undersized values.'
);
putenv('DNR_PUBLIC_BASE_URL');

putenv('DNR_REQUIRE_HTTPS=1');
try {
    calendarSubscriptionUrl([
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_HOST' => 'attacker.example',
    ], $calendar_token);
    $missingOriginRejected = false;
} catch (RuntimeException $exception) {
    $missingOriginRejected = true;
}
expectCalendar(
    $missingOriginRejected,
    'production bearer links should require a configured canonical public origin.'
);
putenv('DNR_REQUIRE_HTTPS');

expectCalendar(
    str_starts_with(calendarSubscriptionUrl([
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_HOST' => 'localhost:8080',
        'SCRIPT_NAME' => '/calendar_subscription.php',
    ], $calendar_token), 'http://localhost:8080/'),
    'an untrusted forwarded protocol should not change development link schemes.'
);

echo "Calendar helper tests passed.\n";
