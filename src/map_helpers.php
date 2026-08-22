<?php

declare(strict_types=1);

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

    // Keep the initial map query bounded. Callers may narrow this window, but
    // an empty filter never means "load the entire engagement history".
    if ($date_from === '' && $date_to === '') {
        $past_days = max(0, min(3650, (int) (getenv('DNR_MAP_PAST_DAYS') ?: 90)));
        $future_days = max(1, min(3650, (int) (getenv('DNR_MAP_FUTURE_DAYS') ?: 730)));
        $date_from = gmdate('Y-m-d', time() - ($past_days * 86400));
        $date_to = gmdate('Y-m-d', time() + ($future_days * 86400));
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

function queueEngagementMapAddress(mysqli $conn, $address)
{
    $address = trim((string) $address);
    if ($address === '') {
        return false;
    }
    $address_hash = engagementMapAddressHash($address);
    $stmt = $conn->prepare(
        "INSERT INTO engagement_map_geocode_queue
            (address_hash, address_query, status, attempts, next_attempt_at)
         VALUES (?, ?, 'pending', 0, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            address_query = VALUES(address_query),
            attempts = IF(status = 'failed', 0, attempts),
            status = IF(status = 'processing', status, 'pending'),
            next_attempt_at = IF(status = 'processing', next_attempt_at, UTC_TIMESTAMP()),
            processing_started_at = IF(status = 'processing', processing_started_at, NULL),
            last_error = NULL"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $address_hash, $address);
    $queued = $stmt->execute();
    $stmt->close();
    return $queued;
}

/**
 * Store a completed lookup and acknowledge its queue item as one atomic change.
 *
 * @param array{latitude: float, longitude: float}|null $coordinates
 */
function completeEngagementMapGeocodeJob(
    mysqli $conn,
    string $address_hash,
    string $address_query,
    ?array $coordinates
): void {
    $latitude = $coordinates['latitude'] ?? null;
    $longitude = $coordinates['longitude'] ?? null;
    $lookup_status = $coordinates === null ? 'not_found' : 'found';
    if ($coordinates !== null && !engagementMapCoordinatesAreValid($latitude, $longitude)) {
        throw new InvalidArgumentException('A completed geocoding job must contain valid coordinates.');
    }
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start geocoding result storage: ' . $conn->error);
    }

    try {
        $save = $conn->prepare(
            'INSERT INTO engagement_map_geocodes
                (address_hash, address_query, latitude, longitude, lookup_status, geocoded_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                address_query = VALUES(address_query), latitude = VALUES(latitude),
                longitude = VALUES(longitude), lookup_status = VALUES(lookup_status),
                geocoded_at = VALUES(geocoded_at)'
        );
        if (!$save) {
            throw new RuntimeException('Unable to prepare geocoding result storage: ' . $conn->error);
        }
        $save->bind_param(
            'ssdds',
            $address_hash,
            $address_query,
            $latitude,
            $longitude,
            $lookup_status
        );
        if (!$save->execute()) {
            $save_error = $save->error;
            $save->close();
            throw new RuntimeException('Unable to store a geocoding result: ' . $save_error);
        }
        $save->close();

        $delete = $conn->prepare(
            "DELETE FROM engagement_map_geocode_queue
             WHERE address_hash = ? AND status = 'processing'"
        );
        if (!$delete) {
            throw new RuntimeException('Unable to prepare geocoding-job completion: ' . $conn->error);
        }
        $delete->bind_param('s', $address_hash);
        if (!$delete->execute() || $delete->affected_rows !== 1) {
            $delete_error = $delete->error;
            $delete->close();
            throw new RuntimeException('Unable to complete the geocoding job: ' . $delete_error);
        }
        $delete->close();

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit the geocoding result: ' . $conn->error);
        }
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function geocoderHostIsPublic($host)
{
    $addresses = array_values(array_unique(array_filter(array_merge(
        gethostbynamel($host) ?: [],
        array_map(
            static fn($record) => $record['ipv6'] ?? null,
            dns_get_record($host, DNS_AAAA) ?: []
        )
    ))));
    if ($addresses === []) {
        return false;
    }
    foreach ($addresses as $address) {
        if (!filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return false;
        }
    }
    return true;
}

function validatedGeocoderBaseUrl()
{
    $url = trim((string) (getenv('DNR_GEOCODER_BASE_URL') ?: 'https://nominatim.openstreetmap.org/search'));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $allowed_hosts = array_filter(array_map(
        static fn($value) => strtolower(trim($value)),
        explode(',', (string) (getenv('DNR_GEOCODER_ALLOWED_HOSTS') ?: 'nominatim.openstreetmap.org'))
    ));
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || !in_array($host, $allowed_hosts, true)
        || !geocoderHostIsPublic($host)
    ) {
        throw new RuntimeException('The configured geocoder endpoint is not an allowed public HTTPS host.');
    }
    return $url;
}

function geocodeEngagementMapAddress($address)
{
    $base_url = validatedGeocoderBaseUrl();
    $application_version = defined('APP_VERSION') ? APP_VERSION : 'dev';
    $separator = strpos($base_url, '?') === false ? '?' : '&';
    $url = $base_url . $separator . http_build_query([
        'format' => 'jsonv2',
        'limit' => 1,
        'q' => $address,
    ], '', '&', PHP_QUERY_RFC3986);
    $user_agent = preg_replace('/[\r\n]+/', ' ', trim((string) (getenv('DNR_GEOCODER_USER_AGENT')
        ?: 'MOED/' . $application_version . ' (https://github.com/beneliath/DNR)')));
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\nUser-Agent: {$user_agent}\r\n",
        'timeout' => 8,
        'ignore_errors' => true,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents($url, false, $context, 0, 262144);
    $status_line = $http_response_header[0] ?? '';
    if ($body === false || !preg_match('/\s2\d\d\s/', $status_line)) {
        throw new RuntimeException('The geocoder did not return a successful response.');
    }
    return parseEngagementMapGeocoderResponse($body);
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
