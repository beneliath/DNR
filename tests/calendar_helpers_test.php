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
    str_contains($all_day_calendar, "SUMMARY:[Under Review] Example\\, Inc. — conference\r\n"),
    'The event title should include a readable status and escaped organization name.'
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

foreach (explode("\r\n", $all_day_calendar) as $line) {
    expectCalendar(strlen($line) <= 75, 'Generated iCalendar lines must be folded to 75 octets or fewer.');
}

$url = calendarSubscriptionUrl([
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_HOST' => 'dnr.example.org',
    'SCRIPT_NAME' => '/calendar_subscription.php',
]);
expectCalendar($url === 'https://dnr.example.org/calendar.php', 'The subscription URL should respect the public proxy scheme and host.');

echo "Calendar helper tests passed.\n";
