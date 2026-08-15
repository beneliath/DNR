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
    $event_type = trim((string) ($engagement['event_type'] ?? ''));
    $title_parts = array_filter([$organization, $event_type], static fn($part) => $part !== '');
    $summary = "[{$status}] " . implode(' — ', $title_parts);
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
        'Event type: ' . ($event_type !== '' ? $event_type : 'Unspecified'),
    ];
    $lines[] = 'DESCRIPTION:' . calendarEscapeText(implode("\n", $description_parts));
    $lines[] = 'STATUS:' . (($engagement['confirmation_status'] ?? '') === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE');
    $lines[] = 'TRANSP:OPAQUE';
    $lines[] = 'END:VEVENT';

    return $lines;
}

function buildCalendar(array $engagements, $calendar_name = 'DNR Events') {
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

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('calendarFoldLine', $lines)) . "\r\n";
}

function calendarSubscriptionUrl(array $server) {
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

    return "{$scheme}://{$host}" . ($base_path !== '' ? $base_path : '') . '/calendar.php';
}
