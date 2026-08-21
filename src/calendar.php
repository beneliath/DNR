<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/calendar_helpers.php';

$subscription = calendarSubscriptionForToken($conn, $_GET['token'] ?? null);
if ($subscription === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    exit('Calendar feed not found.');
}

$past_days = max(0, min(3650, (int) (getenv('DNR_CALENDAR_PAST_DAYS') ?: 365)));
$future_days = max(30, min(3650, (int) (getenv('DNR_CALENDAR_FUTURE_DAYS') ?: 1095)));
$engagement_window = "e.event_end_date >= DATE_SUB(CURDATE(), INTERVAL {$past_days} DAY)
    AND e.event_start_date <= DATE_ADD(CURDATE(), INTERVAL {$future_days} DAY)";
$presentation_window = "p.presentation_date >= DATE_SUB(CURDATE(), INTERVAL {$past_days} DAY)
    AND p.presentation_date <= DATE_ADD(CURDATE(), INTERVAL {$future_days} DAY)";

// Calculate a cheap data version before allocating rows or rendering the ICS.
$engagement_version_result = $conn->query(
    "SELECT COUNT(*) AS row_count,
            COALESCE(UNIX_TIMESTAMP(MAX(GREATEST(e.updated_at, o.updated_at))), 0) AS changed_at
     FROM engagements e
     INNER JOIN organizations o ON o.id = e.organization_id
     WHERE e.is_deleted = 0 AND {$engagement_window}"
);
$presentation_version_result = $conn->query(
    "SELECT COUNT(*) AS row_count,
            COALESCE(UNIX_TIMESTAMP(MAX(GREATEST(e.updated_at, o.updated_at, p.updated_at))), 0) AS changed_at
     FROM presentations p
     INNER JOIN engagements e ON e.id = p.engagement_id
     INNER JOIN organizations o ON o.id = e.organization_id
     WHERE e.is_deleted = 0
       AND p.is_archived = 0
       AND p.presentation_time IS NOT NULL
       AND p.presentation_time <> ''
       AND {$presentation_window}"
);
if (!$engagement_version_result || !$presentation_version_result) {
    error_log('Unable to calculate the private calendar version: ' . $conn->error);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit('The DNR calendar is temporarily unavailable.');
}
$engagement_version = $engagement_version_result->fetch_assoc();
$presentation_version = $presentation_version_result->fetch_assoc();
$etag = '"calendar-' . hash('sha256', implode('|', [
    $engagement_version['row_count'] ?? 0,
    $engagement_version['changed_at'] ?? 0,
    $presentation_version['row_count'] ?? 0,
    $presentation_version['changed_at'] ?? 0,
    $past_days,
    $future_days,
])) . '"';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="dnr-events.ics"');
header('Cache-Control: private, max-age=300, must-revalidate');
header('ETag: ' . $etag);
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit();
}

$query = "SELECT
            e.id,
            e.event_title,
            e.event_start_date,
            e.event_end_date,
            e.event_type,
            e.event_type_other,
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
          WHERE e.is_deleted = 0 AND {$engagement_window}
          ORDER BY e.event_start_date, e.id";
$result = $conn->query($query);
if (!$result) {
    error_log('Unable to build the private calendar: ' . $conn->error);
    http_response_code(503);
    exit('The DNR calendar is temporarily unavailable.');
}
$engagements = $result->fetch_all(MYSQLI_ASSOC);

$presentation_query = "SELECT
            p.id,
            p.engagement_id,
            p.topic_title,
            p.presentation_date,
            p.presentation_time,
            p.speaker_name,
            e.event_title,
            e.event_type,
            e.event_type_other,
            e.confirmation_status,
            e.event_address_line_1,
            e.event_address_line_2,
            e.event_city,
            e.event_state,
            e.event_zipcode,
            e.event_country,
            o.organization_name,
            UNIX_TIMESTAMP(GREATEST(e.updated_at, o.updated_at, p.updated_at)) AS calendar_updated_at
          FROM presentations p
          INNER JOIN engagements e ON e.id = p.engagement_id
          INNER JOIN organizations o ON o.id = e.organization_id
          WHERE e.is_deleted = 0
            AND p.is_archived = 0
            AND p.presentation_time IS NOT NULL
            AND p.presentation_time <> ''
            AND {$presentation_window}
          ORDER BY p.presentation_date,
                   STR_TO_DATE(p.presentation_time, '%h:%i %p'), p.id";
$presentation_result = $conn->query($presentation_query);
if (!$presentation_result) {
    error_log('Unable to add presentations to the private calendar: ' . $conn->error);
    http_response_code(503);
    exit('The DNR calendar is temporarily unavailable.');
}
$presentations = $presentation_result->fetch_all(MYSQLI_ASSOC);

$calendar_timezone = getenv('DNR_TIMEZONE') ?: 'America/Chicago';
echo buildCalendar($engagements, 'DNR Events', $presentations, $calendar_timezone);
