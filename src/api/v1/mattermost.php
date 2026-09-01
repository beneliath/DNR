<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/mattermost_integration_helpers.php';
require_once dirname(__DIR__, 2) . '/mattermost_email_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function mattermostApiRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    $payload['request_id'] ??= applicationRequestId();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function mattermostApiBody(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 32768) {
        mattermostApiRespond([
            'error' => 'Request body is too large.',
            'code' => 'body_too_large',
        ], 413);
    }
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        mattermostApiRespond([
            'error' => 'Request body must be valid JSON.',
            'code' => 'invalid_json',
        ], 400);
    }
    if (!is_array($decoded)) {
        mattermostApiRespond([
            'error' => 'Request body must be a JSON object.',
            'code' => 'invalid_json',
        ], 400);
    }
    return $decoded;
}

function mattermostApiHeader(string $name): string
{
    $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$serverName] ?? ''));
}

function mattermostApiRequireMethod(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $method) {
        header('Allow: ' . $method);
        mattermostApiRespond([
            'error' => 'Method not allowed.',
            'code' => 'method_not_allowed',
        ], 405);
    }
}

function mattermostApiRequireService(): string
{
    if (!mattermostIntegrationConfigured()) {
        mattermostApiRespond([
            'error' => 'The Mattermost integration is not configured in MOED.',
            'code' => 'integration_unconfigured',
        ], 503);
    }
    $authorization = mattermostApiHeader('Authorization');
    if ($authorization === '') {
        $authorization = trim((string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }
    $provided = str_starts_with($authorization, 'Bearer ')
        ? trim(substr($authorization, 7))
        : '';
    if ($provided === '' || !hash_equals(mattermostIntegrationToken(), $provided)) {
        applicationLog('warning', 'Mattermost API authentication failed');
        mattermostApiRespond([
            'error' => 'Unauthorized.',
            'code' => 'unauthorized',
        ], 401);
    }
    $instanceId = mattermostApiHeader('X-Mattermost-Instance-ID');
    if ($instanceId === '' || !hash_equals(mattermostIntegrationInstanceId(), $instanceId)) {
        mattermostApiRespond([
            'error' => 'This Mattermost instance is not authorized.',
            'code' => 'instance_not_authorized',
        ], 403);
    }
    return $instanceId;
}

function mattermostApiRequireUser(mysqli $conn, string $instanceId): array
{
    $mattermostUserId = mattermostApiHeader('X-Mattermost-User-ID');
    $mattermostUsername = mattermostApiHeader('X-Mattermost-Username');
    if ($mattermostUserId === '') {
        mattermostApiRespond([
            'error' => 'Mattermost user identity is required.',
            'code' => 'identity_required',
        ], 400);
    }
    $user = mattermostLinkedUser(
        $conn,
        $instanceId,
        $mattermostUserId,
        $mattermostUsername
    );
    if ($user === null) {
        mattermostApiRespond([
            'error' => 'Link your MOED account with `/moed connect CODE` first.',
            'code' => 'account_not_linked',
        ], 403);
    }
    setDatabaseAuditContext($conn, (int) $user['id'], (string) $user['username']);
    return $user;
}

$conn = applicationDatabaseConnection();
$action = trim((string) ($_GET['action'] ?? 'status'));

try {
    $instanceId = mattermostApiRequireService();

    if ($action === 'status') {
        mattermostApiRequireMethod('GET');
        mattermostApiRespond([
            'ok' => true,
            'integration_version' => '3',
            'application' => applicationBrandName(),
            'instance_id' => $instanceId,
        ]);
    }

    if ($action === 'connect') {
        mattermostApiRequireMethod('POST');
        $body = mattermostApiBody();
        $user = redeemMattermostLinkCode(
            $conn,
            $instanceId,
            mattermostApiHeader('X-Mattermost-User-ID'),
            mattermostApiHeader('X-Mattermost-Username'),
            (string) ($body['code'] ?? '')
        );
        mattermostApiRespond([
            'ok' => true,
            'message' => 'Mattermost is now linked to your MOED account.',
            'user' => $user,
        ]);
    }

    if ($action === 'reply_notifications') {
        mattermostApiRequireMethod('GET');
        mattermostApiRespond([
            'ok' => true,
            'notifications' => mattermostPendingReplyNotifications($conn, $instanceId, 20),
        ]);
    }

    if ($action === 'reply_notification_ack') {
        mattermostApiRequireMethod('POST');
        $body = mattermostApiBody();
        $notificationId = filter_var($body['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId) {
            throw new InvalidArgumentException('A valid reply notification is required.');
        }
        mattermostApiRespond([
            'ok' => acknowledgeMattermostReplyNotification(
                $conn,
                $instanceId,
                (int) $notificationId
            ),
        ]);
    }

    $user = mattermostApiRequireUser($conn, $instanceId);
    $publicUser = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'display_name' => (string) $user['display_name'],
        'role' => (string) $user['role'],
    ];

    if ($action === 'me') {
        mattermostApiRequireMethod('GET');
        mattermostApiRespond(['ok' => true, 'user' => $publicUser]);
    }

    if ($action === 'today') {
        mattermostApiRequireMethod('GET');
        mattermostApiRespond([
            'ok' => true,
            'user' => $publicUser,
            'business_date' => applicationBusinessDate(),
            'task_summary' => mattermostTaskSummaryForUser($conn, (int) $user['id']),
            'tasks' => mattermostTasksForUser($conn, $user, 8),
            'engagements' => mattermostUpcomingEngagements($conn, 5),
            'dashboard_url' => mattermostPublicUrl('dashboard.php'),
        ]);
    }

    if ($action === 'tasks') {
        mattermostApiRequireMethod('GET');
        mattermostApiRespond([
            'ok' => true,
            'user' => $publicUser,
            'business_date' => applicationBusinessDate(),
            'task_summary' => mattermostTaskSummaryForUser($conn, (int) $user['id']),
            'tasks' => mattermostTasksForUser($conn, $user, 15),
            'work_queue_url' => mattermostPublicUrl('tasks.php', ['view' => 'my']),
        ]);
    }

    if ($action === 'event_search') {
        mattermostApiRequireMethod('GET');
        $query = trim((string) ($_GET['q'] ?? ''));
        mattermostApiRespond([
            'ok' => true,
            'user' => $publicUser,
            'query' => $query,
            'engagements' => mattermostSearchEngagements($conn, $query, 10),
        ]);
    }

    if ($action === 'event') {
        mattermostApiRequireMethod('GET');
        $engagementId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$engagementId) {
            throw new InvalidArgumentException('A valid engagement ID is required.');
        }
        $engagement = mattermostEngagement($conn, (int) $engagementId);
        if ($engagement === null) {
            mattermostApiRespond([
                'error' => 'That engagement was not found.',
                'code' => 'engagement_not_found',
            ], 404);
        }
        mattermostApiRespond([
            'ok' => true,
            'user' => $publicUser,
            'engagement' => $engagement,
        ]);
    }

    if ($action === 'email_compose') {
        mattermostApiRequireMethod('GET');
        if (!in_array((string) $user['role'], ['editor', 'admin'], true)) {
            mattermostApiRespond([
                'error' => 'Your MOED role cannot send engagement email.',
                'code' => 'insufficient_role',
            ], 403);
        }
        $engagementId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$engagementId) {
            throw new InvalidArgumentException('A valid linked engagement is required.');
        }
        $response = mattermostEmailComposerPayload($conn, (int) $engagementId);
        $response['user'] = $publicUser;
        mattermostApiRespond($response);
    }

    if ($action === 'email_send') {
        mattermostApiRequireMethod('POST');
        if (!in_array((string) $user['role'], ['editor', 'admin'], true)) {
            mattermostApiRespond([
                'error' => 'Your MOED role cannot send engagement email.',
                'code' => 'insufficient_role',
            ], 403);
        }
        $body = mattermostApiBody();
        $engagementId = filter_var($body['engagement_id'] ?? null, FILTER_VALIDATE_INT);
        $idempotencyKey = mattermostApiHeader('Idempotency-Key');
        if (!$engagementId) {
            throw new InvalidArgumentException('A valid linked engagement is required.');
        }
        $existingResponse = mattermostIdempotentResponse(
            $conn,
            $instanceId,
            $idempotencyKey,
            (int) $user['id'],
            'send_email'
        );
        if ($existingResponse !== null) {
            mattermostApiRespond($existingResponse);
        }
        $context = mattermostEmailContext($conn, (int) $engagementId);
        $messageId = queueEngagementEmail(
            $conn,
            $context['engagement'],
            $context['presentations'],
            normalizeEngagementEmailContactIds($body['contact_ids'] ?? null),
            trim((string) ($body['template_key'] ?? 'custom')),
            $body['subject'] ?? '',
            mattermostEmailBodyWithContext(
                $body['body'] ?? '',
                $body['mattermost_context'] ?? ''
            ),
            !empty($body['include_event_brief']),
            (int) $user['id'],
            (string) $user['username'],
            $instanceId,
            $idempotencyKey
        );
        $response = mattermostEmailMessagePayload($conn, $messageId);
        $response['user'] = $publicUser;
        storeMattermostIdempotentResponse(
            $conn,
            $instanceId,
            $idempotencyKey,
            (int) $user['id'],
            'send_email',
            $response
        );
        mattermostApiRespond($response);
    }

    if ($action === 'email_status') {
        mattermostApiRequireMethod('GET');
        if (!in_array((string) $user['role'], ['editor', 'admin'], true)) {
            mattermostApiRespond([
                'error' => 'Your MOED role cannot view engagement email delivery.',
                'code' => 'insufficient_role',
            ], 403);
        }
        $messageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$messageId) {
            throw new InvalidArgumentException('A valid outbound message is required.');
        }
        mattermostApiRespond(mattermostEmailMessagePayload($conn, (int) $messageId));
    }

    if ($action === 'create_task') {
        mattermostApiRequireMethod('POST');
        if (!canManageFollowUpTasks((string) $user['role'])) {
            mattermostApiRespond([
                'error' => 'Your MOED role cannot create follow-up work.',
                'code' => 'insufficient_role',
            ], 403);
        }
        $body = mattermostApiBody();
        $engagementId = filter_var($body['engagement_id'] ?? null, FILTER_VALIDATE_INT);
        $idempotencyKey = mattermostApiHeader('Idempotency-Key');
        if (!$engagementId) {
            throw new InvalidArgumentException('A valid linked engagement is required.');
        }
        $existingResponse = mattermostIdempotentResponse(
            $conn,
            $instanceId,
            $idempotencyKey,
            (int) $user['id'],
            'create_task'
        );
        if ($existingResponse !== null) {
            mattermostApiRespond($existingResponse);
        }

        $conn->begin_transaction();
        try {
            $task = normalizeFollowUpTaskInput($conn, [
                'title' => (string) ($body['title'] ?? ''),
                'details' => (string) ($body['details'] ?? ''),
                'status' => 'open',
                'priority' => (string) ($body['priority'] ?? 'normal'),
                'due_date' => (string) ($body['due_date'] ?? ''),
                'waiting_on' => '',
                'assigned_to' => (string) $user['id'],
                'subject' => 'engagement:' . (int) $engagementId,
            ]);
            $taskId = insertFollowUpTask($conn, $task, (int) $user['id']);
            $createdTask = fetchFollowUpTask($conn, $taskId);
            if (!$createdTask) {
                throw new RuntimeException('The created task is not available.');
            }
            $response = [
                'ok' => true,
                'message' => 'MOED task created.',
                'user' => $publicUser,
                'task' => mattermostTaskPayload($createdTask, (string) $user['role']),
            ];
            storeMattermostIdempotentResponse(
                $conn,
                $instanceId,
                $idempotencyKey,
                (int) $user['id'],
                'create_task',
                $response
            );
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
        mattermostApiRespond($response);
    }

    if ($action === 'save_chron') {
        mattermostApiRequireMethod('POST');
        if (!canManageFollowUpTasks((string) $user['role'])) {
            mattermostApiRespond([
                'error' => 'Your MOED role cannot add engagement Chron entries.',
                'code' => 'insufficient_role',
            ], 403);
        }
        $body = mattermostApiBody();
        $engagementId = filter_var($body['engagement_id'] ?? null, FILTER_VALIDATE_INT);
        $entryText = (string) ($body['entry_text'] ?? '');
        $idempotencyKey = mattermostApiHeader('Idempotency-Key');
        if (!$engagementId || mattermostEngagement($conn, (int) $engagementId) === null) {
            throw new InvalidArgumentException('The linked engagement is no longer available.');
        }
        $existingResponse = mattermostIdempotentResponse(
            $conn,
            $instanceId,
            $idempotencyKey,
            (int) $user['id'],
            'save_chron'
        );
        if ($existingResponse !== null) {
            mattermostApiRespond($existingResponse);
        }

        $conn->begin_transaction();
        try {
            insertEntityChronLogEntry(
                $conn,
                'engagement',
                (int) $engagementId,
                $entryText,
                (int) $user['id'],
                (string) $user['username']
            );
            $response = [
                'ok' => true,
                'message' => 'Post saved to the MOED Chron.',
                'user' => $publicUser,
                'url' => mattermostPublicUrl('view_engagement.php', ['id' => (int) $engagementId]),
            ];
            storeMattermostIdempotentResponse(
                $conn,
                $instanceId,
                $idempotencyKey,
                (int) $user['id'],
                'save_chron',
                $response
            );
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
        mattermostApiRespond($response);
    }

    if ($action === 'task_action') {
        mattermostApiRequireMethod('POST');
        if (!canManageFollowUpTasks((string) $user['role'])) {
            mattermostApiRespond([
                'error' => 'Your MOED role cannot update follow-up work.',
                'code' => 'insufficient_role',
            ], 403);
        }
        $body = mattermostApiBody();
        $taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT);
        $taskAction = trim((string) ($body['task_action'] ?? ''));
        $expectedVersion = trim((string) ($body['expected_version'] ?? ''));
        $idempotencyKey = mattermostApiHeader('Idempotency-Key');
        if (!$taskId || !in_array($taskAction, [
            'assign_to_me', 'start', 'complete', 'reopen',
        ], true)) {
            throw new InvalidArgumentException('Select a valid task action.');
        }
        $existingResponse = mattermostIdempotentResponse(
            $conn,
            $instanceId,
            $idempotencyKey,
            (int) $user['id'],
            $taskAction
        );
        if ($existingResponse !== null) {
            mattermostApiRespond($existingResponse);
        }

        if ($taskAction === 'assign_to_me') {
            assignFollowUpTaskToUser(
                $conn,
                (int) $taskId,
                (int) $user['id'],
                $expectedVersion
            );
        } else {
            $status = match ($taskAction) {
                'start' => 'in_progress',
                'complete' => 'completed',
                'reopen' => 'open',
            };
            setFollowUpTaskStatus(
                $conn,
                (int) $taskId,
                $status,
                $expectedVersion,
                (int) $user['id']
            );
        }
        $task = fetchFollowUpTask($conn, (int) $taskId);
        if (!$task) {
            throw new RuntimeException('The updated task is no longer available.');
        }
        $response = [
            'ok' => true,
            'message' => match ($taskAction) {
                'assign_to_me' => 'Task assigned to you.',
                'start' => 'Task started.',
                'complete' => 'Task completed.',
                'reopen' => 'Task reopened.',
            },
            'user' => $publicUser,
            'task' => mattermostTaskPayload($task, (string) $user['role']),
        ];
        storeMattermostIdempotentResponse(
            $conn,
            $instanceId,
            $idempotencyKey,
            (int) $user['id'],
            $taskAction,
            $response
        );
        mattermostApiRespond($response);
    }

    mattermostApiRespond([
        'error' => 'Unknown Mattermost API action.',
        'code' => 'not_found',
    ], 404);
} catch (InvalidArgumentException $exception) {
    mattermostApiRespond([
        'error' => $exception->getMessage(),
        'code' => 'invalid_request',
    ], 400);
} catch (Throwable $exception) {
    applicationLog('error', 'Mattermost API request failed', [
        'action' => $action,
        'error' => $exception->getMessage(),
    ]);
    mattermostApiRespond([
        'error' => 'MOED could not complete the Mattermost request.',
        'code' => 'internal_error',
    ], 500);
}
