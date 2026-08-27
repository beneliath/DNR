<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

function calendarStatusLabel($status) {
    $status = trim((string) $status);
    if ($status === '') {
        return 'Unspecified';
    }

    return ucwords(str_replace('_', ' ', $status));
}

function calendarLifecycleLabel($status) {
    return match (trim((string) $status)) {
        'active' => 'Active',
        'postponed' => 'Postponed',
        'canceled' => 'Canceled',
        'completed' => 'Completed',
        default => 'Active',
    };
}

function calendarOperationalStatus(array $engagement) {
    $lifecycle = trim((string) ($engagement['lifecycle_status'] ?? 'active'));
    return $lifecycle === 'active'
        ? calendarStatusLabel($engagement['confirmation_status'] ?? '')
        : calendarLifecycleLabel($lifecycle);
}

/**
 * @return array{
 *   month: string,
 *   label: string,
 *   previous_month: string,
 *   next_month: string,
 *   month_start: string,
 *   month_end: string,
 *   grid_start: string,
 *   grid_end: string,
 *   today: string,
 *   days: list<string>
 * }
 */
function calendarMonthContext($requested_month = null, $today_date = null) {
    $today_value = trim((string) $today_date);
    $today = DateTimeImmutable::createFromFormat('!Y-m-d', $today_value);
    if (!$today instanceof DateTimeImmutable || $today->format('Y-m-d') !== $today_value) {
        $today = new DateTimeImmutable('today');
    }

    $month_value = trim((string) $requested_month);
    $month_start = preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month_value) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m', $month_value)
        : false;
    if (!$month_start instanceof DateTimeImmutable || $month_start->format('Y-m') !== $month_value) {
        $month_start = $today->modify('first day of this month');
    }

    $month_end = $month_start->modify('last day of this month');
    $grid_start = $month_start->modify('-' . $month_start->format('w') . ' days');
    $grid_end = $month_end->modify('+' . (6 - (int) $month_end->format('w')) . ' days');
    $days = [];
    for ($day = $grid_start; $day <= $grid_end; $day = $day->modify('+1 day')) {
        $days[] = $day->format('Y-m-d');
    }

    return [
        'month' => $month_start->format('Y-m'),
        'label' => $month_start->format('F Y'),
        'previous_month' => $month_start->modify('-1 month')->format('Y-m'),
        'next_month' => $month_start->modify('+1 month')->format('Y-m'),
        'month_start' => $month_start->format('Y-m-d'),
        'month_end' => $month_end->format('Y-m-d'),
        'grid_start' => $grid_start->format('Y-m-d'),
        'grid_end' => $grid_end->format('Y-m-d'),
        'today' => $today->format('Y-m-d'),
        'days' => $days,
    ];
}

/** @return array<string, string> */
function calendarViewerModes() {
    return [
        'events' => 'Events',
        'my_tasks' => 'My Tasks',
        'all_tasks' => 'All Tasks',
        'everything' => 'Everything',
    ];
}

function normalizeCalendarViewerMode($mode) {
    $mode = trim((string) $mode);
    return array_key_exists($mode, calendarViewerModes()) ? $mode : 'events';
}

/**
 * @return array{
 *   date: string,
 *   label: string,
 *   previous_day: string,
 *   next_day: string,
 *   today: string,
 *   is_today: bool
 * }
 */
function calendarDayContext($requested_day = null, $today_date = null) {
    $today_value = trim((string) $today_date);
    $today = DateTimeImmutable::createFromFormat('!Y-m-d', $today_value);
    if (!$today instanceof DateTimeImmutable || $today->format('Y-m-d') !== $today_value) {
        $today = new DateTimeImmutable('today');
    }

    $day_value = trim((string) $requested_day);
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $day_value);
    if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $day_value) {
        $day = $today;
    }

    return [
        'date' => $day->format('Y-m-d'),
        'label' => $day->format('l, F j, Y'),
        'previous_day' => $day->modify('-1 day')->format('Y-m-d'),
        'next_day' => $day->modify('+1 day')->format('Y-m-d'),
        'today' => $today->format('Y-m-d'),
        'is_today' => $day->format('Y-m-d') === $today->format('Y-m-d'),
    ];
}

function calendarViewerPageUrl($month = null, $mode = 'events', $day = null) {
    $parameters = [];
    $month = trim((string) $month);
    if (preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) === 1) {
        $parameters['month'] = $month;
    }
    $mode = normalizeCalendarViewerMode($mode);
    if ($mode !== 'events') {
        $parameters['show'] = $mode;
    }
    $day = trim((string) $day);
    $parsed_day = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if ($parsed_day instanceof DateTimeImmutable && $parsed_day->format('Y-m-d') === $day) {
        $parameters['day'] = $day;
    }

    return 'calendar_subscription.php'
        . ($parameters === [] ? '' : '?' . http_build_query($parameters))
        . '#event-calendar';
}

/** @return list<array<string, mixed>> */
function fetchCalendarViewerEngagements(mysqli $conn, $window_start, $window_end) {
    $stmt = $conn->prepare(
        "SELECT e.id, e.event_title, e.event_start_date, e.event_end_date,
                e.confirmation_status, e.lifecycle_status,
                o.organization_name
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0
           AND COALESCE(e.event_end_date, e.event_start_date) >= ?
           AND e.event_start_date <= ?
         ORDER BY e.event_start_date, e.id"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the calendar month view.');
    }
    $stmt->bind_param('ss', $window_start, $window_end);
    $stmt->execute();
    $engagements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $engagements;
}

/** @return list<array<string, mixed>> */
function fetchCalendarViewerTasks(mysqli $conn, $window_start, $window_end, $assigned_user_id = null) {
    $assigned_user_id = $assigned_user_id === null ? null : (int) $assigned_user_id;
    $assigned_filter = $assigned_user_id !== null ? ' AND t.assigned_to = ?' : '';
    $stmt = $conn->prepare(
        "SELECT t.id, t.title, t.due_date, t.status, t.priority, t.assigned_to,
                assignee.username AS assignee_username
         FROM follow_up_tasks t
         LEFT JOIN users assignee ON assignee.id = t.assigned_to
         WHERE t.due_date BETWEEN ? AND ?
           AND t.status IN ('open', 'in_progress', 'waiting')"
        . $assigned_filter .
        " ORDER BY t.due_date,
                   FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
                   t.id"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare tasks for the calendar month view.');
    }
    if ($assigned_user_id !== null) {
        $stmt->bind_param('ssi', $window_start, $window_end, $assigned_user_id);
    } else {
        $stmt->bind_param('ss', $window_start, $window_end);
    }
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}

/**
 * @param list<array<string, mixed>> $engagements
 * @return array<string, list<array<string, mixed>>>
 */
function calendarEventsByDate(array $engagements, $window_start, $window_end) {
    $window_start_value = trim((string) $window_start);
    $window_end_value = trim((string) $window_end);
    $range_start = DateTimeImmutable::createFromFormat('!Y-m-d', $window_start_value);
    $range_end = DateTimeImmutable::createFromFormat('!Y-m-d', $window_end_value);
    if (!$range_start instanceof DateTimeImmutable
        || !$range_end instanceof DateTimeImmutable
        || $range_start->format('Y-m-d') !== $window_start_value
        || $range_end->format('Y-m-d') !== $window_end_value
        || $range_end < $range_start
    ) {
        throw new InvalidArgumentException('A valid calendar date window is required.');
    }

    $events_by_date = [];
    foreach ($engagements as $engagement) {
        $start_value = trim((string) ($engagement['event_start_date'] ?? ''));
        $end_value = trim((string) ($engagement['event_end_date'] ?? '')) ?: $start_value;
        $event_start = DateTimeImmutable::createFromFormat('!Y-m-d', $start_value);
        $event_end = DateTimeImmutable::createFromFormat('!Y-m-d', $end_value);
        if (!$event_start instanceof DateTimeImmutable
            || !$event_end instanceof DateTimeImmutable
            || $event_start->format('Y-m-d') !== $start_value
            || $event_end->format('Y-m-d') !== $end_value
        ) {
            continue;
        }
        if ($event_end < $event_start) {
            $event_end = $event_start;
        }
        $event_start = $event_start < $range_start ? $range_start : $event_start;
        $event_end = $event_end > $range_end ? $range_end : $event_end;
        if ($event_start > $event_end) {
            continue;
        }

        for ($day = $event_start; $day <= $event_end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $events_by_date[$date] ??= [];
            $events_by_date[$date][] = $engagement;
        }
    }
    return $events_by_date;
}

/**
 * @param list<array<string, mixed>> $tasks
 * @return array<string, list<array<string, mixed>>>
 */
function calendarTasksByDate(array $tasks, $window_start, $window_end) {
    $window_start = trim((string) $window_start);
    $window_end = trim((string) $window_end);
    $tasks_by_date = [];
    foreach ($tasks as $task) {
        $due_date = trim((string) ($task['due_date'] ?? ''));
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $due_date);
        if (!$due instanceof DateTimeImmutable
            || $due->format('Y-m-d') !== $due_date
            || $due_date < $window_start
            || $due_date > $window_end
        ) {
            continue;
        }
        $tasks_by_date[$due_date] ??= [];
        $tasks_by_date[$due_date][] = $task;
    }
    return $tasks_by_date;
}

function calendarViewerEventLabel(array $engagement) {
    $event_title = trim((string) ($engagement['event_title'] ?? ''));
    if ($event_title !== '') {
        return $event_title;
    }
    $organization = trim((string) ($engagement['organization_name'] ?? ''));
    return $organization !== '' ? $organization : 'Untitled event';
}

function calendarViewerEventTone(array $engagement) {
    $lifecycle = trim((string) ($engagement['lifecycle_status'] ?? 'active'));
    if (in_array($lifecycle, ['canceled', 'postponed', 'completed'], true)) {
        return $lifecycle;
    }
    return ($engagement['confirmation_status'] ?? '') === 'confirmed'
        ? 'confirmed'
        : 'tentative';
}

function calendarViewerTaskLabel(array $task) {
    $title = trim((string) ($task['title'] ?? ''));
    return $title !== '' ? $title : 'Untitled task';
}

function calendarViewerTaskTone(array $task, $current_user_id) {
    return (int) ($task['assigned_to'] ?? 0) === (int) $current_user_id
        ? 'mine'
        : 'other';
}

function calendarIcsStatus(array $engagement) {
    if (($engagement['lifecycle_status'] ?? 'active') === 'canceled') {
        return 'CANCELLED';
    }
    return ($engagement['confirmation_status'] ?? '') === 'confirmed'
        ? 'CONFIRMED'
        : 'TENTATIVE';
}

function calendarTransparency(array $engagement) {
    return in_array(
        (string) ($engagement['lifecycle_status'] ?? 'active'),
        ['postponed', 'canceled'],
        true
    ) ? 'TRANSPARENT' : 'OPAQUE';
}

function calendarRescheduledEventLabel(array $engagement) {
    $title = trim((string) ($engagement['rescheduled_event_title'] ?? ''));
    $organization = trim((string) ($engagement['rescheduled_organization_name'] ?? ''));
    $date = trim((string) ($engagement['rescheduled_event_start_date'] ?? ''));
    $label = $title !== '' ? $title : $organization;
    if ($label === '') {
        return '';
    }
    return $date !== '' ? $label . ' · ' . $date : $label;
}

function calendarEscapeText($value) {
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    return str_replace(
        ['\\', ';', ',', "\n"],
        ['\\\\', '\\;', '\\,', '\\n'],
        $value
    );
}

function calendarFoldLine($line) {
    if (strlen($line) <= 75) {
        return $line;
    }

    $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) {
        $characters = str_split($line);
    }

    $lines = [];
    $current = '';
    $limit = 75;

    foreach ($characters as $character) {
        if ($current !== '' && strlen($current . $character) > $limit) {
            $lines[] = $current;
            $current = $character;
            $limit = 74;
        } else {
            $current .= $character;
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return implode("\r\n ", $lines);
}

function calendarUtcTimestamp($timestamp) {
    if (is_numeric($timestamp)) {
        return (new DateTimeImmutable('@' . (int) $timestamp))->format('Ymd\THis\Z');
    }

    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');
}

function calendarSequence($timestamp) {
    if (!is_numeric($timestamp)) {
        return 0;
    }

    // Keep the monotonically increasing value inside the iCalendar 32-bit integer range.
    return max(0, (int) $timestamp - 1577836800);
}

function calendarPresentationSequence($timestamp) {
    // Revision 1 changed timed presentation names. Keep the same stable UID,
    // but advance SEQUENCE so calendar clients refresh existing items.
    return min(2147483647, calendarSequence($timestamp) + 1);
}

function calendarLocation(array $engagement) {
    $parts = array_filter([
        trim((string) ($engagement['event_address_line_1'] ?? '')),
        trim((string) ($engagement['event_address_line_2'] ?? '')),
        trim(implode(', ', array_filter([
            trim((string) ($engagement['event_city'] ?? '')),
            trim((string) ($engagement['event_state'] ?? '')),
        ])) . (!empty($engagement['event_zipcode']) ? ' ' . trim((string) $engagement['event_zipcode']) : '')),
        trim((string) ($engagement['event_country'] ?? '')),
    ], static fn($part) => $part !== '');

    return implode(', ', $parts);
}

function calendarEventLines(array $engagement) {
    $status = calendarOperationalStatus($engagement);
    $confirmation = calendarStatusLabel($engagement['confirmation_status'] ?? '');
    $lifecycle = calendarLifecycleLabel($engagement['lifecycle_status'] ?? 'active');
    $organization = trim((string) ($engagement['organization_name'] ?? 'Unknown organization'));
    $event_title = trim((string) ($engagement['event_title'] ?? ''));
    $event_type = trim((string) ($engagement['event_type'] ?? ''));
    if ($event_type === 'other' && trim((string) ($engagement['event_type_other'] ?? '')) !== '') {
        $event_type = trim((string) $engagement['event_type_other']);
    }
    $calendar_title = $event_title !== '' ? $event_title : $organization;
    $calendar_type = $event_type !== '' ? $event_type : 'Unspecified';
    $summary = "{$status}-{$calendar_title}-{$calendar_type}";
    $updated_timestamp = $engagement['calendar_updated_at'] ?? null;
    $updated_at = calendarUtcTimestamp($updated_timestamp);

    $lines = [
        'BEGIN:VEVENT',
        'UID:engagement-' . (int) $engagement['id'] . '@dnr-calendar',
        'DTSTAMP:' . $updated_at,
        'LAST-MODIFIED:' . $updated_at,
        'SEQUENCE:' . calendarSequence($updated_timestamp),
        'SUMMARY:' . calendarEscapeText($summary),
    ];

    $start_date = $engagement['event_start_date'] ?? '';
    $end_date = ($engagement['event_end_date'] ?? '') ?: $start_date;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $end_date);
    if ($start === false || $end === false) {
        return [];
    }
    if ($end < $start) {
        $end = $start;
    }
    $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
    $lines[] = 'DTEND;VALUE=DATE:' . $end->modify('+1 day')->format('Ymd');

    $location = calendarLocation($engagement);
    if ($location !== '') {
        $lines[] = 'LOCATION:' . calendarEscapeText($location);
    }

    $description_parts = [
        'Lifecycle: ' . $lifecycle,
        'Confirmation: ' . $confirmation,
        'Organization: ' . $organization,
        'Event type: ' . ($event_type !== '' ? $event_type : 'Unspecified'),
    ];
    if ($event_title !== '') {
        array_splice($description_parts, 2, 0, ['Event title: ' . $event_title]);
    }
    $cancellation_reason = trim((string) ($engagement['cancellation_reason'] ?? ''));
    if ($cancellation_reason !== '') {
        $description_parts[] = 'Cancellation reason: ' . $cancellation_reason;
    }
    $rescheduled_event = calendarRescheduledEventLabel($engagement);
    if ($rescheduled_event !== '') {
        $description_parts[] = 'Rescheduled as: ' . $rescheduled_event;
    }
    $lines[] = 'DESCRIPTION:' . calendarEscapeText(implode("\n", $description_parts));
    $lines[] = 'STATUS:' . calendarIcsStatus($engagement);
    $lines[] = 'TRANSP:' . calendarTransparency($engagement);
    $lines[] = 'END:VEVENT';

    return $lines;
}

function calendarPresentationStart(array $presentation, $timezone_name = null) {
    $timezone_name = trim((string) ($timezone_name ?? applicationTimezoneName()));
    try {
        $timezone = new DateTimeZone($timezone_name);
    } catch (Throwable $exception) {
        $timezone = applicationTimezone();
    }

    $date = trim((string) ($presentation['presentation_date'] ?? ''));
    $time = strtoupper(trim((string) ($presentation['presentation_time'] ?? '')));
    if ($date === '' || $time === '') {
        return null;
    }

    $formats = str_contains($time, 'AM') || str_contains($time, 'PM')
        ? ['!Y-m-d g:i A', '!Y-m-d h:i A']
        : ['!Y-m-d H:i:s', '!Y-m-d H:i'];
    foreach ($formats as $format) {
        $start = DateTimeImmutable::createFromFormat($format, $date . ' ' . $time, $timezone);
        $date_errors = DateTimeImmutable::getLastErrors();
        if ($start instanceof DateTimeImmutable
            && ($date_errors === false
                || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))
        ) {
            return $start;
        }
    }

    return null;
}

function calendarPresentationEventLines(
    array $presentation,
    $timezone_name = null,
    $duration_minutes = 60
) {
    $start = calendarPresentationStart($presentation, $timezone_name);
    $topic_title = trim((string) ($presentation['topic_title'] ?? ''));
    if (!$start || $topic_title === '') {
        return [];
    }

    $duration_minutes = max(1, (int) $duration_minutes);
    $end = $start->modify('+' . $duration_minutes . ' minutes');
    $utc = new DateTimeZone('UTC');
    $organization = trim((string) ($presentation['organization_name'] ?? 'Unknown organization'));
    $event_title = trim((string) ($presentation['event_title'] ?? ''));
    $engagement_label = $event_title !== '' ? $event_title : $organization;
    $confirmation = calendarStatusLabel($presentation['confirmation_status'] ?? '');
    $lifecycle = calendarLifecycleLabel($presentation['lifecycle_status'] ?? 'active');
    $speaker = trim((string) ($presentation['speaker_name'] ?? ''));
    $speaker_label = $speaker !== '' ? $speaker : 'Unknown Speaker';
    $updated_timestamp = $presentation['calendar_updated_at'] ?? null;
    $updated_at = calendarUtcTimestamp($updated_timestamp);
    $summary = 'Presentation-' . $topic_title . '-' . $speaker_label;

    $lines = [
        'BEGIN:VEVENT',
        'UID:presentation-' . (int) $presentation['id'] . '@dnr-calendar',
        'RELATED-TO:engagement-' . (int) $presentation['engagement_id'] . '@dnr-calendar',
        'DTSTAMP:' . $updated_at,
        'LAST-MODIFIED:' . $updated_at,
        'SEQUENCE:' . calendarPresentationSequence($updated_timestamp),
        'SUMMARY:' . calendarEscapeText($summary),
        'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
        'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
    ];

    $location = calendarLocation($presentation);
    if ($location !== '') {
        $lines[] = 'LOCATION:' . calendarEscapeText($location);
    }

    $description_parts = [
        'Presentation: ' . $topic_title,
        'Engagement: ' . $engagement_label,
        'Organization: ' . $organization,
        'Lifecycle: ' . $lifecycle,
        'Confirmation: ' . $confirmation,
    ];
    if ($speaker !== '') {
        $description_parts[] = 'Speaker: ' . $speaker;
    }
    $lines[] = 'DESCRIPTION:' . calendarEscapeText(implode("\n", $description_parts));
    $lines[] = 'STATUS:' . calendarIcsStatus($presentation);
    $lines[] = 'TRANSP:' . calendarTransparency($presentation);
    $lines[] = 'END:VEVENT';

    return $lines;
}

function buildCalendar(
    array $engagements,
    $calendar_name = null,
    array $presentations = [],
    $timezone_name = null
) {
    $calendar_name = $calendar_name ?? applicationCalendarName();
    $productName = preg_replace('/[^A-Za-z0-9 ._-]+/u', '', applicationBrandName()) ?: 'DNR';
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . $productName . '//Shared Engagement Calendar//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . calendarEscapeText($calendar_name),
        'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
        'X-PUBLISHED-TTL:PT15M',
    ];

    foreach ($engagements as $engagement) {
        $lines = array_merge($lines, calendarEventLines($engagement));
    }

    foreach ($presentations as $presentation) {
        $lines = array_merge(
            $lines,
            calendarPresentationEventLines($presentation, $timezone_name)
        );
    }

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('calendarFoldLine', $lines)) . "\r\n";
}

function calendarTokenHash($token) {
    if (!is_string($token) || strlen($token) < 32 || strlen($token) > 255) {
        return null;
    }
    return hash('sha256', $token, true);
}

function createCalendarSubscription(mysqli $conn, $user_id, $label = 'Calendar subscription') {
    $user_id = (int) $user_id;
    $label = trim(substr((string) $label, 0, 100));
    if ($user_id < 1 || $label === '') {
        throw new InvalidArgumentException('A subscription owner and label are required.');
    }

    $conn->begin_transaction();
    try {
        // Serialize subscription creation per owner so the five-token limit
        // cannot be bypassed by concurrent requests.
        $user_stmt = $conn->prepare(
            "SELECT id FROM users WHERE id = ? AND account_status = 'active' FOR UPDATE"
        );
        if (!$user_stmt) {
            throw new RuntimeException('Unable to lock the subscription owner.');
        }
        $user_stmt->bind_param('i', $user_id);
        $user_stmt->execute();
        $user_exists = $user_stmt->get_result()->num_rows === 1;
        $user_stmt->close();
        if (!$user_exists) {
            throw new InvalidArgumentException('The subscription owner is unavailable.');
        }

        $count_stmt = $conn->prepare(
            'SELECT COUNT(*) AS active_count FROM calendar_subscriptions
             WHERE user_id = ? AND revoked_at IS NULL'
        );
        $count_stmt->bind_param('i', $user_id);
        $count_stmt->execute();
        $active_count = (int) $count_stmt->get_result()->fetch_assoc()['active_count'];
        $count_stmt->close();
        if ($active_count >= 5) {
            throw new RuntimeException('Revoke an existing subscription before creating another.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token_hash = calendarTokenHash($token);
        $stmt = $conn->prepare(
            'INSERT INTO calendar_subscriptions (user_id, label, token_hash)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iss', $user_id, $label, $token_hash);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to create the calendar subscription.');
        }
        $subscription_id = (int) $conn->insert_id;
        $stmt->close();
        $conn->commit();
        return ['id' => $subscription_id, 'token' => $token, 'label' => $label];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function calendarSubscriptionForToken(mysqli $conn, $submitted_token) {
    $token_hash = calendarTokenHash($submitted_token);
    if ($token_hash === null) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT subscription.id, subscription.user_id, subscription.label,
                subscription.created_at, subscription.last_used_at
         FROM calendar_subscriptions subscription
         INNER JOIN users user ON user.id = subscription.user_id
         WHERE subscription.token_hash = ? AND subscription.revoked_at IS NULL
           AND user.account_status = \'active\'
         LIMIT 1'
    );
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $subscription = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$subscription) {
        return null;
    }

    $subscription_id = (int) $subscription['id'];
    $touch = $conn->prepare(
        'UPDATE calendar_subscriptions SET last_used_at = UTC_TIMESTAMP()
         WHERE id = ? AND (last_used_at IS NULL OR last_used_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
    );
    $touch->bind_param('i', $subscription_id);
    $touch->execute();
    $touch->close();
    return $subscription;
}

function calendarSubscriptionsForUser(mysqli $conn, $user_id) {
    $stmt = $conn->prepare(
        'SELECT id, label, created_at, last_used_at, revoked_at
         FROM calendar_subscriptions WHERE user_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function revokeCalendarSubscription(mysqli $conn, $user_id, $subscription_id) {
    $stmt = $conn->prepare(
        'UPDATE calendar_subscriptions SET revoked_at = UTC_TIMESTAMP()
         WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
    );
    $stmt->bind_param('ii', $subscription_id, $user_id);
    $stmt->execute();
    $revoked = $stmt->affected_rows === 1;
    $stmt->close();
    return $revoked;
}

function purgeRevokedCalendarSubscriptions(mysqli $conn, $user_id) {
    $user_id = (int) $user_id;
    if ($user_id < 1) {
        throw new InvalidArgumentException('A subscription owner is required.');
    }

    $stmt = $conn->prepare(
        'DELETE FROM calendar_subscriptions
         WHERE user_id = ? AND revoked_at IS NOT NULL'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare revoked subscription cleanup.');
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $purged_count = max(0, (int) $stmt->affected_rows);
    $stmt->close();
    return $purged_count;
}

function calendarSubscriptionUrl(array $server, $token = null) {
    $requires_https = function_exists('applicationRequiresHttps')
        ? applicationRequiresHttps()
        : filter_var(getenv('DNR_REQUIRE_HTTPS') ?: '0', FILTER_VALIDATE_BOOL);
    $configured_base_url = trim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: ''));
    if ($configured_base_url !== '') {
        $configured_scheme = strtolower((string) parse_url($configured_base_url, PHP_URL_SCHEME));
        if (!filter_var($configured_base_url, FILTER_VALIDATE_URL)
            || !in_array($configured_scheme, ['http', 'https'], true)
            || parse_url($configured_base_url, PHP_URL_USER) !== null
            || parse_url($configured_base_url, PHP_URL_PASS) !== null
            || parse_url($configured_base_url, PHP_URL_QUERY) !== null
            || parse_url($configured_base_url, PHP_URL_FRAGMENT) !== null
            || ($requires_https && $configured_scheme !== 'https')
        ) {
            throw new RuntimeException('DNR_PUBLIC_BASE_URL must be a canonical HTTP(S) origin.');
        }
        $calendar_url = rtrim($configured_base_url, '/') . '/calendar.php';
        return $token === null ? $calendar_url : $calendar_url . '?' . http_build_query(['token' => $token]);
    }

    if ($requires_https) {
        throw new RuntimeException(
            'DNR_PUBLIC_BASE_URL is required before a production calendar subscription can be created.'
        );
    }
    $uses_https = function_exists('requestUsesHttps')
        ? requestUsesHttps($server)
        : (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off');
    $scheme = $uses_https ? 'https' : 'http';

    $host = (string) ($server['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-z0-9.\-\[\]:]+$/i', $host)) {
        $host = 'localhost';
    }

    $script_name = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/calendar_subscription.php'));
    $base_path = rtrim(dirname($script_name), '/.');

    $calendar_url = "{$scheme}://{$host}" . ($base_path !== '' ? $base_path : '') . '/calendar.php';
    return $token === null ? $calendar_url : $calendar_url . '?' . http_build_query(['token' => $token]);
}
