<?php

include 'config.php';
include 'calendar_helpers.php';

$query = "SELECT
            e.id,
            e.event_title,
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

$presentation_query = "SELECT
            p.id,
            p.engagement_id,
            p.topic_title,
            p.presentation_date,
            p.presentation_time,
            p.speaker_name,
            e.event_title,
            e.confirmation_status,
            e.event_address_line_1,
            e.event_address_line_2,
            e.event_city,
            e.event_state,
            e.event_zipcode,
            e.event_country,
            o.organization_name,
            UNIX_TIMESTAMP(GREATEST(e.updated_at, o.updated_at, p.created_at)) AS calendar_updated_at
          FROM presentations p
          INNER JOIN engagements e ON e.id = p.engagement_id
          INNER JOIN organizations o ON o.id = e.organization_id
          WHERE e.is_deleted = 0
            AND p.is_archived = 0
            AND p.presentation_date IS NOT NULL
            AND p.presentation_time IS NOT NULL
            AND p.presentation_time <> ''
          ORDER BY p.presentation_date, p.presentation_time, p.id";

$presentation_result = $conn->query($presentation_query);
if (!$presentation_result) {
    error_log('Unable to add presentations to the public calendar: ' . $conn->error);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The DNR calendar is temporarily unavailable.');
}

$presentations = [];
while ($row = $presentation_result->fetch_assoc()) {
    $presentations[] = $row;
}

$calendar_timezone = getenv('DNR_TIMEZONE') ?: 'America/Chicago';
$calendar = buildCalendar($engagements, 'DNR Events', $presentations, $calendar_timezone);
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
