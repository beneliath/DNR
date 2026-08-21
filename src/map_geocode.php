<?php
// Compatibility endpoint: web requests may enqueue a location, but outbound
// geocoder traffic is deliberately restricted to the background worker.
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
    $respond(405, ['status' => 'error', 'message' => 'Use POST to queue an engagement location.']);
}
requireValidCsrfToken();

$engagement_id = filter_input(INPUT_POST, 'engagement_id', FILTER_VALIDATE_INT);
if (!$engagement_id) {
    $respond(400, ['status' => 'error', 'message' => 'Select a valid engagement.']);
}

$stmt = $conn->prepare(
    'SELECT event_address_line_1, event_address_line_2, event_city, event_state,
            event_zipcode, event_country
     FROM engagements
     WHERE id = ? AND is_deleted = 0'
);
if (!$stmt) {
    $respond(503, ['status' => 'error', 'message' => 'The location service is temporarily unavailable.']);
}
$stmt->bind_param('i', $engagement_id);
$stmt->execute();
$engagement = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$engagement) {
    $respond(404, ['status' => 'error', 'message' => 'That engagement is no longer available.']);
}

$address = engagementMapAddress($engagement);
if ($address === '') {
    $respond(200, ['status' => 'no_address']);
}
$address_hash = engagementMapAddressHash($address);
$cache_stmt = $conn->prepare(
    'SELECT latitude, longitude, lookup_status
     FROM engagement_map_geocodes
     WHERE address_hash = ?'
);
if (!$cache_stmt) {
    $respond(503, ['status' => 'error', 'message' => 'The engagement map migration is required.']);
}
$cache_stmt->bind_param('s', $address_hash);
$cache_stmt->execute();
$cached = $cache_stmt->get_result()->fetch_assoc();
$cache_stmt->close();
if ($cached && $cached['lookup_status'] === 'not_found') {
    $respond(200, ['status' => 'not_found']);
}
if ($cached && $cached['lookup_status'] === 'found'
    && engagementMapCoordinatesAreValid($cached['latitude'], $cached['longitude'])) {
    $respond(200, [
        'status' => 'found',
        'latitude' => (float) $cached['latitude'],
        'longitude' => (float) $cached['longitude'],
    ]);
}
if (!queueEngagementMapAddress($conn, $address)) {
    $respond(503, ['status' => 'error', 'message' => 'Unable to queue the location lookup.']);
}
$respond(202, ['status' => 'pending']);
