<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__.'/record_creation_helpers.php';
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
    $token=submittedCreationToken($_POST);
    $conn->begin_transaction();
    $replayed=beginRecordCreation($conn,(int)$_SESSION['user_id'],$token,'organization');
    if($replayed!==null) {
        $row=$conn->execute_query('SELECT id,organization_name AS label FROM organizations WHERE id=?',[$replayed])->fetch_assoc();
        $conn->commit();
        echo json_encode($row,JSON_THROW_ON_ERROR); exit;
    }
    $name = $input['data']['organization_name'];
    if($conn->execute_query('SELECT id FROM organizations WHERE organization_name=? LIMIT 1',[$name])->fetch_row()) throw new InvalidArgumentException('An organization with this name exists. Choose it from the list, or use the full New Organization form to review distinct records.');
    $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to create the organization.');
    }
    $id = (int) $conn->insert_id;
    $stmt->close();
    completeRecordCreation($conn,(int)$_SESSION['user_id'],$token,$id);
    $conn->commit();
    echo json_encode(['id' => $id, 'label' => $name]);
} catch (Throwable $exception) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(['error' => $exception instanceof InvalidArgumentException ? $exception->getMessage() : ((int) $exception->getCode() === 1062
        ? 'An organization with this name already exists. Choose it from the organization list.'
        : 'The organization could not be created. Your contact draft is still here; try again.')]);
}
