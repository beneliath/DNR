<?php

require_once __DIR__ . '/bootstrap.php';
startSecureSession();
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hasRole(['admin', 'editor'])) {
    http_response_code(403);
    echo json_encode(['error' => 'You cannot create an organization here.']);
    exit();
}
requireValidCsrfToken();
$input = \Dnr\Domain\OrganizationInput::normalize([
    'organization_name' => $_POST['organization_name'] ?? '',
]);
if ($input['errors'] !== []) {
    http_response_code(422);
    echo json_encode(['error' => $input['errors'][0]]);
    exit();
}
try {
    $name = $input['data']['organization_name'];
    $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to create the organization.');
    }
    $id = (int) $conn->insert_id;
    $stmt->close();
    echo json_encode(['id' => $id, 'label' => $name]);
} catch (Throwable $exception) {
    http_response_code(409);
    echo json_encode(['error' => (int) $exception->getCode() === 1062
        ? 'An organization with this name already exists. Choose it from the organization list.'
        : 'The organization could not be created. Your contact draft is still here; try again.']);
}
