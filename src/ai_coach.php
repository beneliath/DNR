<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_coach_helpers.php';
require_once __DIR__ . '/ai_coach_history_helpers.php';
require_once __DIR__ . '/ai_coach_improvement_helpers.php';
startSecureSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function sendCoachError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if (!isLoggedIn()) sendCoachError(401, 'Sign in again to use the coach.');
requireLogin();
if (!aiCoachEnabled()) sendCoachError(404, 'The coach is not enabled.');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['request_id'])) {
    $requestId = $_GET['request_id'];
    if (!is_string($requestId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $requestId)) sendCoachError(400, 'Invalid request identifier.');
    $userId = (int) $_SESSION['user_id']; $role = (string) $_SESSION['role'];
    releaseApplicationSessionLock();
    try { $status = aiCoachRequestStatus($conn, $userId, $role, $requestId); }
    catch (Throwable $exception) { sendCoachError(503, 'The answer could not be retrieved. Please try again.'); }
    if ($status === null) sendCoachError(404, 'Request not found.');
    echo json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendCoachError(405, 'Use POST to ask the coach.');
}
if (!validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) sendCoachError(403, 'Reload this page before asking the coach.');
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) sendCoachError(413, 'The conversation is too long. Start a new one.');
$raw = file_get_contents('php://input', false, null, 0, 16001);
if ($raw === false || strlen($raw) > 16000) sendCoachError(413, 'The conversation is too long. Start a new one.');
try {
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid question.');
    if (in_array($input['action'] ?? '', ['cancel', 'feedback'], true)) {
        $requestId = $input['request_id'] ?? '';
        if (!is_string($requestId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $requestId)) throw new InvalidArgumentException('Invalid request identifier.');
        $userId = (int) $_SESSION['user_id']; $role = (string) $_SESSION['role'];
        releaseApplicationSessionLock();
        if ($input['action'] === 'cancel') $saved = aiCoachCancelRequest($conn, $userId, $role, $requestId);
        else $saved = aiCoachSaveFeedback($conn, $userId, $role, $requestId, is_string($input['feedback'] ?? null) ? $input['feedback'] : '', is_array($input['ui'] ?? null) ? $input['ui'] : [], is_array($input['location'] ?? null) ? $input['location'] : []);
        echo json_encode(['saved' => $saved]); exit;
    }
    $request = aiCoachValidateRequest($input, (string) ($_SESSION['role'] ?? 'reviewer'));
} catch (mysqli_sql_exception $exception) {
    sendCoachError(503, 'The request could not be updated. Please try again.');
} catch (JsonException | InvalidArgumentException $exception) {
    sendCoachError(400, 'Check your question and try again.');
}
$requestStarted = hrtime(true);
$created = false;
$historyId = aiCoachRecordRequest($conn, $request, (int) $_SESSION['user_id'], $created);
if ($historyId === null && $request['request_id'] !== '') sendCoachError(503, 'The question could not be saved for processing. Please try again.');
if ($historyId !== null && !$created) {
    $status = aiCoachRequestStatus($conn, (int) $_SESSION['user_id'], (string) $_SESSION['role'], $request['request_id']);
    if ($status === null) sendCoachError(404, 'Request not found.');
    if ($status['state'] === 'complete') echo json_encode($status['response'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    else { http_response_code(202); echo json_encode(['pending' => true, 'request_id' => $request['request_id']]); }
    exit;
}
if (microtime(true) - (float) ($_SESSION['_ai_coach_last_request'] ?? 0) < 2) {
    aiCoachCompleteRequest($conn, $historyId, ['error' => 'Please wait a moment before asking another question.'], 0, 'throttled');
    header('Retry-After: 2');
    sendCoachError(429, 'Please wait a moment before asking another question.');
}
$_SESSION['_ai_coach_last_request'] = microtime(true);
// Never hold the user's session lock while the Mac generates an answer.
releaseApplicationSessionLock();
// Finish recording even if the browser stops waiting for its answer.
ignore_user_abort(true);
try {
    $response = aiCoachImmediateReply($request, false);
    if ($response !== null) $response['telemetry'] = ['route' => $response['engine'] ?? 'verified-workflow', 'model_calls' => 0];
    $response ??= aiCoachApprovedReply($conn, $request);
    $response ??= aiCoachImmediateReply($request);
    if ($response === null && $historyId !== null && $request['request_id'] !== ''
        && filter_var(getenv('DNR_AI_COACH_ASYNC') ?: '0', FILTER_VALIDATE_BOOL) && getenv('DNR_AI_COACH_URL')) {
        if (aiCoachEnqueue($conn, $historyId, $request)) {
            http_response_code(202);
            echo json_encode(['pending' => true, 'request_id' => $request['request_id'], 'stage' => 'queued']);
            exit;
        }
        $response = aiCoachFallback([], 'busy');
    }
    $response ??= aiCoachGenerate($request);
} catch (Throwable $exception) {
    $response = ['message' => 'The coach could not prepare guidance. Please try again.', 'question' => '', 'sources' => [], 'mode' => 'manual', 'reason' => 'error'];
}
$response['history_saved'] = aiCoachCompleteRequest($conn, $historyId, $response, (int) ((hrtime(true) - $requestStarted) / 1000000), ($response['reason'] ?? '') === 'error' ? 'error' : '');
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
