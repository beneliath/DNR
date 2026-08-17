<?php

function calendarStatusLabel($status) {
    $status = trim((string) $status);
    if ($status === '') {
        return 'Unspecified';
    }

    return ucwords(str_replace('_', ' ', $status));
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
    $status = calendarStatusLabel($engagement['confirmation_status'] ?? '');
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
        'Status: ' . $status,
        'Organization: ' . $organization,
        'Event type: ' . ($event_type !== '' ? $event_type : 'Unspecified'),
    ];
    if ($event_title !== '') {
        array_splice($description_parts, 1, 0, ['Event title: ' . $event_title]);
    }
    $lines[] = 'DESCRIPTION:' . calendarEscapeText(implode("\n", $description_parts));
    $lines[] = 'STATUS:' . (($engagement['confirmation_status'] ?? '') === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE');
    $lines[] = 'TRANSP:OPAQUE';
    $lines[] = 'END:VEVENT';

    return $lines;
}

function calendarPresentationStart(array $presentation, $timezone_name = 'America/Chicago') {
    try {
        $timezone = new DateTimeZone($timezone_name);
    } catch (Throwable $exception) {
        $timezone = new DateTimeZone('America/Chicago');
    }

    $date = trim((string) ($presentation['presentation_date'] ?? ''));
    $time = strtoupper(trim((string) ($presentation['presentation_time'] ?? '')));
    if ($date === '' || $time === '') {
        return null;
    }

    $formats = str_contains($time, 'AM') || str_contains($time, 'PM')
        ? ['!Y-m-d g:i A', '!Y-m-d h:i A']
        : ['!Y-m-d H:i'];
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
    $timezone_name = 'America/Chicago',
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
    $status = calendarStatusLabel($presentation['confirmation_status'] ?? '');
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
        'Status: ' . $status,
    ];
    if ($speaker !== '') {
        $description_parts[] = 'Speaker: ' . $speaker;
    }
    $lines[] = 'DESCRIPTION:' . calendarEscapeText(implode("\n", $description_parts));
    $lines[] = 'STATUS:' . (($presentation['confirmation_status'] ?? '') === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE');
    $lines[] = 'TRANSP:OPAQUE';
    $lines[] = 'END:VEVENT';

    return $lines;
}

function buildCalendar(
    array $engagements,
    $calendar_name = 'DNR Events',
    array $presentations = [],
    $timezone_name = 'America/Chicago'
) {
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//DNR//Shared Engagement Calendar//EN',
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

function calendarAccessToken() {
    $token = trim((string) (getenv('DNR_CALENDAR_TOKEN') ?: ''));
    return strlen($token) >= 32 && strlen($token) <= 255 ? $token : null;
}

function calendarRequestIsAuthorized(array $server, $submitted_token = null) {
    $configured_token = calendarAccessToken();
    if ($configured_token === null || !is_string($submitted_token)) {
        return false;
    }
    return hash_equals($configured_token, $submitted_token);
}

function calendarSubscriptionUrl(array $server) {
    $configured_base_url = trim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: ''));
    if ($configured_base_url !== ''
        && filter_var($configured_base_url, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($configured_base_url, PHP_URL_SCHEME)), ['http', 'https'], true)
    ) {
        $calendar_url = rtrim($configured_base_url, '/') . '/calendar.php';
        $token = calendarAccessToken();
        return $token === null ? $calendar_url : $calendar_url . '?' . http_build_query(['token' => $token]);
    }

    $forwarded_proto = strtolower(trim(explode(',', $server['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $scheme = $forwarded_proto === 'https'
        || (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host = (string) ($server['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-z0-9.\-\[\]:]+$/i', $host)) {
        $host = 'localhost';
    }

    $script_name = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/calendar_subscription.php'));
    $base_path = rtrim(dirname($script_name), '/.');

    $calendar_url = "{$scheme}://{$host}" . ($base_path !== '' ? $base_path : '') . '/calendar.php';
    $token = calendarAccessToken();
    return $token === null ? $calendar_url : $calendar_url . '?' . http_build_query(['token' => $token]);
}
