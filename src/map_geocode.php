<?php
include 'config.php';
include 'functions.php';
include 'map_helpers.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function ($status_code, array $payload) {
    http_response_code($status_code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
};

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['status' => 'error', 'message' => 'Use POST to resolve an engagement location.']);
}
requireValidCsrfToken();

$engagement_id = filter_input(INPUT_POST, 'engagement_id', FILTER_VALIDATE_INT);
if (!$engagement_id) {
    $respond(400, ['status' => 'error', 'message' => 'Select a valid engagement.']);
}

$engagement_stmt = $conn->prepare(
    'SELECT
        event_address_line_1,
        event_address_line_2,
        event_city,
        event_state,
        event_zipcode,
        event_country
     FROM engagements
     WHERE id = ? AND is_deleted = 0'
);
if (!$engagement_stmt) {
    $respond(503, ['status' => 'error', 'message' => 'The location service is temporarily unavailable.']);
}
$engagement_stmt->bind_param('i', $engagement_id);
$engagement_stmt->execute();
$engagement = $engagement_stmt->get_result()->fetch_assoc();
$engagement_stmt->close();
if (!$engagement) {
    $respond(404, ['status' => 'error', 'message' => 'That engagement is no longer available.']);
}

$address = engagementMapAddress($engagement);
if ($address === '') {
    $respond(200, ['status' => 'no_address']);
}
$address_hash = engagementMapAddressHash($address);

$load_cached_geocode = static function () use ($conn, $address_hash) {
    $stmt = $conn->prepare(
        'SELECT latitude, longitude, lookup_status
         FROM engagement_map_geocodes
         WHERE address_hash = ?'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $address_hash);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
};

$cached_response = static function ($cached) {
    if (!is_array($cached)) {
        return null;
    }
    if ($cached['lookup_status'] === 'not_found') {
        return ['status' => 'not_found'];
    }
    if ($cached['lookup_status'] === 'found'
        && engagementMapCoordinatesAreValid($cached['latitude'], $cached['longitude'])
    ) {
        return [
            'status' => 'found',
            'latitude' => (float) $cached['latitude'],
            'longitude' => (float) $cached['longitude'],
        ];
    }
    return null;
};

$cached = $load_cached_geocode();
if ($cached === false) {
    $respond(503, ['status' => 'error', 'message' => 'The engagement map database migration is required.']);
}
$cached_payload = $cached_response($cached);
if ($cached_payload !== null) {
    $respond(200, $cached_payload);
}

// Serialize outbound lookups across Apache workers and retain the most recent
// request start time. This keeps the application below the public service's
// one-request-per-second ceiling even when multiple users open the map.
$rate_lock_path = sys_get_temp_dir() . '/dnr-engagement-map-geocoder.lock';
$rate_lock = @fopen($rate_lock_path, 'c+');
if ($rate_lock === false || !flock($rate_lock, LOCK_EX)) {
    if (is_resource($rate_lock)) {
        fclose($rate_lock);
    }
    $respond(503, ['status' => 'error', 'message' => 'The location service is busy. Please try again.']);
}

try {
    // Another request may have populated the shared cache while this request
    // waited for the geocoder lock.
    $cached = $load_cached_geocode();
    $cached_payload = $cached_response($cached);
    if ($cached_payload !== null) {
        flock($rate_lock, LOCK_UN);
        fclose($rate_lock);
        $respond(200, $cached_payload);
    }

    rewind($rate_lock);
    $last_request_started = (float) trim((string) stream_get_contents($rate_lock));
    $wait_microseconds = (int) max(
        0,
        ceil((1.1 - (microtime(true) - $last_request_started)) * 1000000)
    );
    if ($wait_microseconds > 0) {
        usleep($wait_microseconds);
    }
    $request_started = microtime(true);
    rewind($rate_lock);
    ftruncate($rate_lock, 0);
    fwrite($rate_lock, sprintf('%.6f', $request_started));
    fflush($rate_lock);

    $geocoder_base_url = trim((string) (getenv('DNR_GEOCODER_BASE_URL') ?: 'https://nominatim.openstreetmap.org/search'));
    $geocoder_scheme = strtolower((string) parse_url($geocoder_base_url, PHP_URL_SCHEME));
    if (!filter_var($geocoder_base_url, FILTER_VALIDATE_URL)
        || !in_array($geocoder_scheme, ['http', 'https'], true)
    ) {
        throw new RuntimeException('The configured geocoder URL is invalid.');
    }
    $query_separator = strpos($geocoder_base_url, '?') === false ? '?' : '&';
    $geocoder_url = $geocoder_base_url . $query_separator . http_build_query([
        'format' => 'jsonv2',
        'limit' => 1,
        'q' => $address,
    ], '', '&', PHP_QUERY_RFC3986);

    $user_agent = trim((string) (getenv('DNR_GEOCODER_USER_AGENT')
        ?: 'MOED/' . APP_VERSION . ' (https://github.com/beneliath/DNR)'));
    $request_headers = "Accept: application/json\r\nUser-Agent: {$user_agent}\r\n";
    $public_base_url = trim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: ''));
    if (filter_var($public_base_url, FILTER_VALIDATE_URL)) {
        $request_headers .= 'Referer: ' . rtrim($public_base_url, '/') . "/map.php\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $request_headers,
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);
    $geocoder_body = @file_get_contents($geocoder_url, false, $context, 0, 262144);
    $status_line = $http_response_header[0] ?? '';
    if ($geocoder_body === false || !preg_match('/\s2\d\d\s/', $status_line)) {
        throw new RuntimeException('The configured geocoder did not return a successful response.');
    }
    $coordinates = parseEngagementMapGeocoderResponse($geocoder_body);

    $latitude = $coordinates['latitude'] ?? null;
    $longitude = $coordinates['longitude'] ?? null;
    $lookup_status = $coordinates === null ? 'not_found' : 'found';
    $save_stmt = $conn->prepare(
        'INSERT INTO engagement_map_geocodes
            (address_hash, address_query, latitude, longitude, lookup_status, geocoded_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            address_query = VALUES(address_query),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            lookup_status = VALUES(lookup_status),
            geocoded_at = VALUES(geocoded_at)'
    );
    if (!$save_stmt) {
        throw new RuntimeException('Unable to prepare the location cache.');
    }
    $save_stmt->bind_param(
        'ssdds',
        $address_hash,
        $address,
        $latitude,
        $longitude,
        $lookup_status
    );
    if (!$save_stmt->execute()) {
        $save_stmt->close();
        throw new RuntimeException('Unable to cache the resolved location.');
    }
    $save_stmt->close();
} catch (Throwable $exception) {
    error_log('Unable to geocode engagement ' . (int) $engagement_id . ': ' . $exception->getMessage());
    flock($rate_lock, LOCK_UN);
    fclose($rate_lock);
    $respond(502, ['status' => 'error', 'message' => 'This location could not be resolved right now.']);
}

flock($rate_lock, LOCK_UN);
fclose($rate_lock);
if ($coordinates === null) {
    $respond(200, ['status' => 'not_found']);
}
$respond(200, [
    'status' => 'found',
    'latitude' => $coordinates['latitude'],
    'longitude' => $coordinates['longitude'],
]);
