<?php

declare(strict_types=1);

// This endpoint only reads batched queue/cache state. Enqueueing is kept in a
// separate request so repeated polling cannot rewrite retries or failed work.
require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/map_helpers.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status_code, array $payload): void {
    http_response_code($status_code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
};

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['status' => 'error', 'message' => 'Use POST to check engagement locations.']);
}
requireValidCsrfToken();
try {
    $engagement_ids = normalizeEngagementMapIds(
        $_POST['engagement_ids'] ?? '',
        applicationWorkflowSetting('map_max_events')
    );
} catch (InvalidArgumentException | LengthException $exception) {
    $respond(400, ['status' => 'error', 'message' => $exception->getMessage()]);
}
releaseApplicationSessionLock();

try {
    $locations = engagementMapLocationStatuses($conn, $engagement_ids);
} catch (RuntimeException $exception) {
    applicationLog('error', 'Unable to read batched map status', ['error' => $exception->getMessage()]);
    $respond(503, ['status' => 'error', 'message' => 'The location service is temporarily unavailable.']);
}

$payload_locations = [];
foreach ($locations as $location) {
    unset($location['address']);
    $payload_locations[] = $location;
}
$respond(200, ['status' => 'ok', 'locations' => $payload_locations]);
