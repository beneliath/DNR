<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engagement_contact_helpers.php';

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
if ($organization_id === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Select a valid organization.']);
    exit();
}

try {
    requireActiveOrganization($conn, $organization_id, true);
    $contacts = array_map(
        static function (array $contact): array {
            return [
                'id' => (int) $contact['id'],
                'name' => trim(
                    (string) $contact['contact_first_name'] . ' '
                    . (string) $contact['contact_last_name']
                ),
                'organization_role' => organizationContactRoleLabel($contact),
                'email' => (string) ($contact['contact_email'] ?? ''),
            ];
        },
        fetchOrganizationContactOptions($conn, $organization_id)
    );
    echo json_encode([
        'contacts' => $contacts,
        'roles' => engagementContactRoles(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(404);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    applicationLog('error', 'Organization contact options failed', [
        'organization_id' => $organization_id,
        'error' => $exception->getMessage(),
    ]);
    http_response_code(503);
    echo json_encode(['error' => 'Contacts are temporarily unavailable.']);
}
