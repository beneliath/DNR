<?php

declare(strict_types=1);

function creationFormToken(): string
{
    $token = $_POST['operation_token'] ?? null;
    return is_string($token) && preg_match('/\A[a-f0-9]{32}\z/D', $token) ? $token : bin2hex(random_bytes(16));
}

function submittedCreationToken(array $input): string
{
    $token = $input['operation_token'] ?? null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) {
        throw new InvalidArgumentException('The save token is missing or invalid. Reload the form before saving.');
    }
    return $token;
}

function creationTokenInput(string $token): string
{
    return '<input type="hidden" name="operation_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function completedRecordCreation(mysqli $conn, int $userId, string $token, string $kind): ?int
{
    $row = $conn->execute_query('SELECT entity_type,entity_id FROM record_creation_operations WHERE user_id=? AND operation_token=?', [$userId,$token])->fetch_assoc();
    if (!$row) return null;
    if ($row['entity_type'] !== $kind) throw new InvalidArgumentException('This save token belongs to a different form. Reload before saving.');
    return $row['entity_id'] === null ? null : (int) $row['entity_id'];
}

/** Caller owns the transaction. Serialize retries without serializing different users. */
function beginRecordCreation(mysqli $conn, int $userId, string $token, string $kind): ?int
{
    if (!$conn->execute_query('SELECT id FROM users WHERE id=? FOR UPDATE', [$userId])->fetch_row()) {
        throw new InvalidArgumentException('The current account is unavailable.');
    }
    $id = completedRecordCreation($conn, $userId, $token, $kind);
    if ($id !== null) return $id;
    $conn->execute_query('INSERT INTO record_creation_operations (user_id,operation_token,entity_type) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE operation_token=operation_token', [$userId,$token,$kind]);
    return null;
}

function completeRecordCreation(mysqli $conn, int $userId, string $token, int $id): void
{
    $conn->execute_query('UPDATE record_creation_operations SET entity_id=? WHERE user_id=? AND operation_token=? AND entity_id IS NULL', [$id,$userId,$token]);
    if ($conn->affected_rows !== 1) throw new RuntimeException('Unable to record the completed save.');
}

/** Run after authorization and CSRF validation, before validation/uploads/duplicate warnings. */
function redirectCompletedRecordCreation(mysqli $conn, string $kind, string $destination, string $return = ''): string
{
    try {
        $token = submittedCreationToken($_POST);
        $id = completedRecordCreation($conn, (int) $_SESSION['user_id'], $token, $kind);
    }
    catch (InvalidArgumentException $exception) { abortApplication(422, $exception->getMessage()); }
    if ($id !== null) {
        $url = $destination . $id;
        if ($return !== '') {
            require_once __DIR__ . '/record_workspace_helpers.php';
            $url = recordUrlWithQuery($return, ['created_' . $kind . '_id' => $id]);
        }
        header('Location: ' . $url, true, 303);
        exit();
    }
    return $token;
}
