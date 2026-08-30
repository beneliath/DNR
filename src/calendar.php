<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/calendar_helpers.php';

$subscription = calendarSubscriptionForToken(
    $conn,
    \Dnr\Http\RequestInput::string($_GET, 'token')
);
if ($subscription === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    exit('Calendar feed not found.');
}

$past_days = applicationWorkflowSetting('calendar_past_days');
$future_days = applicationWorkflowSetting('calendar_future_days');
$window_start = applicationBusinessDateOffset(-$past_days);
$window_end = applicationBusinessDateOffset($future_days);
$engagement_window = 'e.event_end_date >= ? AND e.event_start_date <= ?';
$presentation_window = 'p.presentation_date >= ? AND p.presentation_date <= ?';

$revision_result = $conn->query(
    'SELECT revision, UNIX_TIMESTAMP(updated_at) AS changed_at
     FROM calendar_feed_revision WHERE id = 1'
);
if (!$revision_result) {
    applicationLog('error', 'Unable to calculate the private calendar version', ['error' => $conn->error]);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit('The ' . applicationBrandName() . ' calendar is temporarily unavailable.');
}
$calendar_revision = $revision_result->fetch_assoc();
if (!$calendar_revision) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit('The ' . applicationBrandName() . ' calendar is temporarily unavailable.');
}
$etag = '"calendar-' . hash('sha256', implode('|', [
    $calendar_revision['revision'] ?? 0,
    $calendar_revision['changed_at'] ?? 0,
    $window_start,
    $window_end,
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
            e.lifecycle_status,
            e.cancellation_reason,
            e.event_address_line_1,
            e.event_address_line_2,
            e.event_city,
            e.event_state,
            e.event_zipcode,
            e.event_country,
            o.organization_name,
            replacement.event_title AS rescheduled_event_title,
            replacement.event_start_date AS rescheduled_event_start_date,
            replacement_organization.organization_name AS rescheduled_organization_name,
            UNIX_TIMESTAMP(GREATEST(
                e.updated_at,
                o.updated_at,
                COALESCE(replacement.updated_at, e.updated_at),
                COALESCE(replacement_organization.updated_at, o.updated_at)
            )) AS calendar_updated_at
          FROM engagements e
          INNER JOIN organizations o ON o.id = e.organization_id
          LEFT JOIN engagements replacement
                 ON replacement.id = e.rescheduled_to_engagement_id
          LEFT JOIN organizations replacement_organization
                 ON replacement_organization.id = replacement.organization_id
          WHERE e.is_deleted = 0 AND {$engagement_window}
          ORDER BY e.event_start_date, e.id";
$engagement_statement = $conn->prepare($query);
if (!$engagement_statement) {
    applicationLog('error', 'Unable to build the private calendar', ['error' => $conn->error]);
    http_response_code(503);
    exit('The ' . applicationBrandName() . ' calendar is temporarily unavailable.');
}
$engagement_statement->bind_param('ss', $window_start, $window_end);
$engagement_statement->execute();
$result = $engagement_statement->get_result();
$engagements = $result->fetch_all(MYSQLI_ASSOC);
$engagement_statement->close();

$presentation_query = "SELECT
            p.id,
            p.engagement_id,
            p.topic_title,
            p.presentation_date,
            p.presentation_time,
            p.speaker_name,
            p.duration_minutes,
            e.event_title,
            e.event_type,
            e.event_type_other,
            e.confirmation_status,
            e.lifecycle_status,
            e.cancellation_reason,
            e.event_address_line_1,
            e.event_address_line_2,
            e.event_city,
            e.event_state,
            e.event_zipcode,
            e.event_country,
            o.organization_name,
            replacement.event_title AS rescheduled_event_title,
            replacement.event_start_date AS rescheduled_event_start_date,
            replacement_organization.organization_name AS rescheduled_organization_name,
            UNIX_TIMESTAMP(GREATEST(
                e.updated_at,
                o.updated_at,
                p.updated_at,
                COALESCE(replacement.updated_at, e.updated_at),
                COALESCE(replacement_organization.updated_at, o.updated_at)
            )) AS calendar_updated_at
          FROM presentations p
          INNER JOIN engagements e ON e.id = p.engagement_id
          INNER JOIN organizations o ON o.id = e.organization_id
          LEFT JOIN engagements replacement
                 ON replacement.id = e.rescheduled_to_engagement_id
          LEFT JOIN organizations replacement_organization
                 ON replacement_organization.id = replacement.organization_id
          WHERE e.is_deleted = 0
            AND p.is_archived = 0
            AND p.presentation_time IS NOT NULL
            AND {$presentation_window}
          ORDER BY p.presentation_date, p.presentation_time, p.id";
$presentation_statement = $conn->prepare($presentation_query);
if (!$presentation_statement) {
    applicationLog('error', 'Unable to add presentations to the private calendar', ['error' => $conn->error]);
    http_response_code(503);
    exit('The ' . applicationBrandName() . ' calendar is temporarily unavailable.');
}
$presentation_statement->bind_param('ss', $window_start, $window_end);
$presentation_statement->execute();
$presentation_result = $presentation_statement->get_result();
$presentations = $presentation_result->fetch_all(MYSQLI_ASSOC);
$presentation_statement->close();

$calendar_timezone = applicationTimezoneName();
echo buildCalendar($engagements, applicationCalendarName(), $presentations, $calendar_timezone);
