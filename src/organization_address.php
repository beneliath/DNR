<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engagement_location_helpers.php';

$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (!hasRole(['admin', 'editor'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}
releaseApplicationSessionLock();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit(json_encode(['error' => 'Method not allowed.']));
}

$organization_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'organization_id');
if ($organization_id === null) {
    http_response_code(400);
    exit(json_encode(['error' => 'Select a valid organization.']));
}

try {
    $result = $conn->execute_query(
        'SELECT physical_address_line_1, physical_address_line_2, physical_city,
                physical_state, physical_zipcode, physical_country
         FROM organizations WHERE id = ? AND is_deleted = 0',
        [$organization_id]
    );
    $organization = $result->fetch_assoc();
    if (!$organization) {
        http_response_code(404);
        exit(json_encode(['error' => 'Select an active organization.']));
    }
    echo json_encode(['address' => organizationEventLocation($organization)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    applicationLog('error', 'Organization address lookup failed', [
        'organization_id' => $organization_id,
        'error' => $exception->getMessage(),
    ]);
    http_response_code(503);
    echo json_encode(['error' => 'The organization address is temporarily unavailable.']);
}
