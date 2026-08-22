<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
startSecureSession();
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (!canManageFollowUpTasks($_SESSION['role'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

try {
    echo json_encode([
        'results' => searchFollowUpTaskSubjects($conn, $_GET['q'] ?? '', 24),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    applicationLog('error', 'Task subject search failed', ['error' => $exception->getMessage()]);
    http_response_code(503);
    echo json_encode(['error' => 'Search is temporarily unavailable.']);
}
