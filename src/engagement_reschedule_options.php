<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';

$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (!hasRole(['admin', 'editor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$organization_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'organization_id');
$exclude_engagement_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'exclude_id');
if ($organization_id === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a valid organization.']);
    exit();
}

try {
    requireActiveOrganization($conn, $organization_id, true);
    $candidates = array_map(
        static fn(array $candidate): array => [
            'id' => (int) $candidate['id'],
            'label' => engagementReferenceLabel($candidate),
            'lifecycle' => engagementLifecycleLabel($candidate['lifecycle_status']),
        ],
        fetchEngagementRescheduleCandidates(
            $conn,
            $organization_id,
            $exclude_engagement_id
        )
    );
    echo json_encode(
        ['engagements' => $candidates],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
} catch (InvalidArgumentException $exception) {
    http_response_code(404);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    applicationLog('error', 'Rescheduled-event options failed', [
        'organization_id' => $organization_id,
        'error' => $exception->getMessage(),
    ]);
    http_response_code(503);
    echo json_encode(['error' => 'Rescheduled-event options are temporarily unavailable.']);
}
