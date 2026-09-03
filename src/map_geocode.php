<?php
// Compatibility endpoint: web requests may enqueue a location, but outbound
// geocoder traffic is deliberately restricted to the background worker.
require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
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

try {
    $engagement_ids = normalizeEngagementMapIds(
        $_POST['engagement_ids'] ?? ($_POST['engagement_id'] ?? ''),
        applicationWorkflowSetting('map_max_events')
    );
} catch (InvalidArgumentException | LengthException $exception) {
    $respond(400, ['status' => 'error', 'message' => $exception->getMessage()]);
}
releaseApplicationSessionLock();

try {
    $locations = engagementMapLocationStatuses($conn, $engagement_ids);
} catch (RuntimeException $exception) {
    applicationLog('error', 'Unable to load batched map locations', ['error' => $exception->getMessage()]);
    $respond(503, ['status' => 'error', 'message' => 'The location service is temporarily unavailable.']);
}
if ($locations === []) {
    $respond(404, ['status' => 'error', 'message' => 'Those engagements are no longer available.']);
}

$addresses_to_queue = [];
foreach ($locations as $location) {
    if ($location['status'] === 'unqueued') {
        $addresses_to_queue[] = $location['address'];
    }
}
if (!queueEngagementMapAddresses($conn, $addresses_to_queue)) {
    $respond(503, ['status' => 'error', 'message' => 'Unable to queue the location lookups.']);
}

$payload_locations = [];
$has_pending = false;
foreach ($locations as $location) {
    unset($location['address']);
    if ($location['status'] === 'unqueued') {
        $location['status'] = 'pending';
    }
    $has_pending = $has_pending || $location['status'] === 'pending';
    $payload_locations[] = $location;
}
$respond($has_pending ? 202 : 200, [
    'status' => $has_pending ? 'pending' : 'complete',
    'locations' => $payload_locations,
]);
