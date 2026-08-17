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
expectCalendar(
    str_contains($all_day_calendar, "SUMMARY:Under Review-Annual Leadership Summit-conference\r\n"),
    'The calendar summary should use the readable status, event title, and event type.'
);
expectCalendar(
    str_contains($all_day_calendar, 'Event title: Annual Leadership Summit'),
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
        && str_contains($calendar_with_presentation, "DTEND:20260821T153000Z\r\n"),
    'Presentation events should block one hour at their configured local date and time.'
);
expectCalendar(
    str_contains($unfolded_presentation_calendar, 'SUMMARY:Presentation: Opening Keynote — Annual Leadership Summit'),
    'Presentation events should identify the topic and engagement.'
);
expectCalendar(
    str_contains($unfolded_presentation_calendar, 'Speaker: Jordan Speaker'),
    'Presentation events should include the speaker in their description.'
);
expectCalendar(
    calendarPresentationEventLines(array_merge($presentation, ['presentation_time' => ''])) === [],
    'Presentations without both a date and time should not create timed calendar blocks.'
);

foreach (explode("\r\n", $calendar_with_presentation) as $line) {
    expectCalendar(strlen($line) <= 75, 'Generated iCalendar lines must be folded to 75 octets or fewer.');
}

$url = calendarSubscriptionUrl([
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_HOST' => 'dnr.example.org',
    'SCRIPT_NAME' => '/calendar_subscription.php',
]);
expectCalendar($url === 'https://dnr.example.org/calendar.php', 'The subscription URL should respect the public proxy scheme and host.');

echo "Calendar helper tests passed.\n";
