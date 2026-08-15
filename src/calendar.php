<?php

include 'config.php';
include 'calendar_helpers.php';

$query = "SELECT
            e.id,
            e.event_start_date,
            e.event_end_date,
            e.event_type,
            e.confirmation_status,
            e.event_address_line_1,
            e.event_address_line_2,
            e.event_city,
            e.event_state,
            e.event_zipcode,
            e.event_country,
            o.organization_name,
            UNIX_TIMESTAMP(GREATEST(e.updated_at, o.updated_at)) AS calendar_updated_at
          FROM engagements e
          INNER JOIN organizations o ON o.id = e.organization_id
          WHERE e.is_deleted = 0
          ORDER BY e.event_start_date, e.id";

$result = $conn->query($query);
if (!$result) {
    error_log('Unable to build the public calendar: ' . $conn->error);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The DNR calendar is temporarily unavailable.');
}

$engagements = [];
while ($row = $result->fetch_assoc()) {
    $engagements[] = $row;
}

$calendar = buildCalendar($engagements);
$etag = '"' . hash('sha256', $calendar) . '"';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="dnr-events.ics"');
header('Cache-Control: public, max-age=300, must-revalidate');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit();
}

echo $calendar;
