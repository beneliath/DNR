<?php

function engagementMapStatuses()
{
    return [
        'work_in_progress' => 'Work in progress',
        'under_review' => 'Under review',
        'confirmed' => 'Confirmed',
    ];
}

function normalizeEngagementMapFilters(array $query)
{
    $scalar = static function ($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    };

    $status = $scalar($query['status'] ?? '');
    if (!array_key_exists($status, engagementMapStatuses())) {
        $status = '';
    }

    $date_from = $scalar($query['date_from'] ?? '');
    $date_to = $scalar($query['date_to'] ?? '');
    $errors = [];

    if ($date_from !== '' && !validIsoDate($date_from)) {
        $errors[] = 'Choose a valid start date.';
        $date_from = '';
    }
    if ($date_to !== '' && !validIsoDate($date_to)) {
        $errors[] = 'Choose a valid end date.';
        $date_to = '';
    }
    if ($date_from !== '' && $date_to !== '' && $date_to < $date_from) {
        $errors[] = 'The end of the date window cannot precede its start.';
        $date_from = '';
        $date_to = '';
    }

    return [
        'status' => $status,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'errors' => $errors,
    ];
}

function engagementMapAddress(array $engagement)
{
    $parts = [];
    foreach (['event_address_line_1', 'event_address_line_2'] as $field) {
        $value = trim((string) ($engagement[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $city_line = trim(implode(', ', array_filter([
        trim((string) ($engagement['event_city'] ?? '')),
        trim((string) ($engagement['event_state'] ?? '')),
    ], static function ($value) {
        return $value !== '';
    })));
    $zipcode = trim((string) ($engagement['event_zipcode'] ?? ''));
    if ($zipcode !== '') {
        $city_line = trim($city_line . ' ' . $zipcode);
    }
    if ($city_line !== '') {
        $parts[] = $city_line;
    }

    $country = trim((string) ($engagement['event_country'] ?? ''));
    if ($country !== '') {
        $parts[] = $country;
    }

    return implode(', ', $parts);
}

function engagementMapAddressHash($address)
{
    $normalized = preg_replace('/\s+/u', ' ', trim((string) $address));
    return hash('sha256', strtolower($normalized));
}

function engagementMapCoordinatesAreValid($latitude, $longitude)
{
    return is_numeric($latitude)
        && is_numeric($longitude)
        && (float) $latitude >= -90
        && (float) $latitude <= 90
        && (float) $longitude >= -180
        && (float) $longitude <= 180;
}

function parseEngagementMapGeocoderResponse($response)
{
    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The location service returned an invalid response.');
    }
    if ($decoded === []) {
        return null;
    }

    $first = $decoded[0] ?? null;
    if (!is_array($first)
        || !engagementMapCoordinatesAreValid($first['lat'] ?? null, $first['lon'] ?? null)
    ) {
        throw new RuntimeException('The location service returned invalid coordinates.');
    }

    return [
        'latitude' => (float) $first['lat'],
        'longitude' => (float) $first['lon'],
    ];
}

function engagementMapDateLabel($start_date, $end_date)
{
    $start_timestamp = strtotime((string) $start_date);
    $end_timestamp = strtotime((string) $end_date);
    if (!$start_timestamp || !$end_timestamp) {
        return trim((string) $start_date . ' – ' . (string) $end_date);
    }
    $start = date('M j, Y', $start_timestamp);
    if ($start_date === $end_date) {
        return $start;
    }
    return $start . ' – ' . date('M j, Y', $end_timestamp);
}
