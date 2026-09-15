<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Task archiving HTTP integration tests skipped (requires disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once __DIR__ . '/integration_auth_helpers.php';
require_once $sourceDirectory . '/follow_up_task_helpers.php';
require_once $sourceDirectory . '/notification_helpers.php';
require_once $sourceDirectory . '/dashboard_helpers.php';
require_once $sourceDirectory . '/calendar_helpers.php';
function expectTaskArchive(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function taskArchiveHttp(string $path, string $sessionId, ?array $post = null): array {
    $curl = curl_init(rtrim((string) getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sessionId, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
}
function taskArchiveDom(string $html): DOMXPath {
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    return new DOMXPath($dom);
}
function taskArchiveFields(string $html, int $taskId, string $action): array {
    $xpath = taskArchiveDom($html);
    $form = $xpath->query('//form[input[@name="action" and @value="' . $action . '"] and input[@name="task_id" and @value="' . $taskId . '"]]')->item(0);
    expectTaskArchive($form instanceof DOMElement, 'The task has a ' . $action . ' POST form');
    $fields = [];
    foreach ($xpath->query('.//input', $form) as $input) $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    return $fields;
}
$suffix = bin2hex(random_bytes(5));
$users = $sessions = []; $orgId = 0;
try {
    foreach (['editor', 'admin', 'reviewer'] as $role) {
        $conn->execute_query('INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            ['archive-' . $role . '-' . $suffix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role]);
        $id = $users[$role] = (int) $conn->insert_id;
        $user = $conn->query("SELECT * FROM users WHERE id = {$id}")->fetch_assoc();
        session_start();
        $_SESSION = ['user_id' => $id, 'username' => $user['username'], 'role' => $role,
            'auth_version' => (int) $user['auth_version'], 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        completeIntegrationTestMfaSession();
        $sessions[$role] = [session_id(), $_SESSION['_csrf_token']];
        session_write_close(); session_id('');
    }
    [$editor, $csrf] = $sessions['editor']; $owner = $users['editor'];
    $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ['Archive test ' . $suffix]);
    $orgId = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
        VALUES (?, ?, '2026-09-10', '2026-09-12', 'conference', 'under_review')", [$orgId, 'Archive event ' . $suffix]);
    $eventId = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO follow_up_tasks (title, details, status, waiting_on, due_date, subject_type, engagement_id, assigned_to, created_by)
        VALUES (?, 'Preserve notes', 'waiting', 'Host reply', '2026-09-01', 'engagement', ?, ?, ?)", ['Archive task ' . $suffix, $eventId, $owner, $owner]);
    $taskId = (int) $conn->insert_id;
    $before = fetchFollowUpTask($conn, $taskId);
    $recordPath = 'view_engagement.php?id=' . $eventId;
    $queue = 'tasks.php?scope=everyone&subject_type=engagement&subject_id=' . $eventId;
    $archivedQueue = $queue . '&view=archived';
    foreach (['editor', 'admin', 'reviewer'] as $role) {
        $page = taskArchiveHttp($recordPath, $sessions[$role][0]);
        $xpath = taskArchiveDom($page['body']);
        expectTaskArchive($page['status'] === 200 && $xpath->query('//button[@aria-label="Archive task"]')->length === ($role === 'reviewer' ? 0 : 1), 'Archive controls follow task-management permissions');
    }
    $fields = taskArchiveFields(taskArchiveHttp($recordPath, $editor)['body'], $taskId, 'archive');
    expectTaskArchive(str_starts_with($fields['return_to'], $recordPath) && str_ends_with($fields['return_to'], '#follow-up-work'), 'Archive returns to the engagement task tab');
    expectTaskArchive(taskArchiveHttp('tasks.php', $editor, array_replace($fields, ['csrf_token' => 'invalid']))['status'] === 400, 'Archive requires valid CSRF');
    expectTaskArchive(taskArchiveHttp('tasks.php', $sessions['reviewer'][0], array_replace($fields, ['csrf_token' => $sessions['reviewer'][1]]))['status'] === 403, 'Reviewer cannot archive');
    $result = taskArchiveHttp('tasks.php', $editor, $fields);
    expectTaskArchive($result['status'] === 302 && str_contains($result['headers'], 'Location: ' . $fields['return_to']), 'Editor archives and returns to the record');
    $archived = fetchFollowUpTask($conn, $taskId);
    foreach (['status', 'waiting_on', 'due_date', 'details', 'assigned_to', 'completed_at', 'completed_by'] as $key) {
        expectTaskArchive($archived[$key] === $before[$key], 'Archiving preserves ' . $key);
    }
    expectTaskArchive((int) $archived['is_archived'] === 1 && (int) $archived['archived_by'] === $owner && $archived['archived_at'] !== null, 'Archive metadata is recorded');
    expectTaskArchive(fetchFollowUpTasksForSubject($conn, 'engagement', $eventId) === [], 'Archived tasks leave record tasks and next action');
    expectTaskArchive(fetchDashboardMyTasks($conn, $owner) === [], 'Archived tasks leave dashboard work');
    expectTaskArchive(fetchDashboardTaskSummary($conn, $owner, '2026-09-15')['active'] === 0, 'Archived tasks leave dashboard counts');
    expectTaskArchive(fetchTaskReminderCounts($conn, $owner, 'editor', '2026-09-15')['active'] === 0, 'Archived tasks leave reminders');
    expectTaskArchive(fetchCalendarViewerTasks($conn, '2026-09-01', '2026-09-30', $owner) === [], 'Archived tasks leave calendar feeds');
    expectTaskArchive(fetchDailyTaskDigestData($conn, $owner, 'editor', '2026-09-15')['waiting'] === [], 'Archived tasks leave daily digests');
    $activePage = taskArchiveHttp($queue, $editor);
    expectTaskArchive(!str_contains($activePage['body'], 'Archive task ' . $suffix), 'Active queue excludes archived work');
    $archivePage = taskArchiveHttp($archivedQueue, $editor);
    $xpath = taskArchiveDom($archivePage['body']);
    expectTaskArchive($archivePage['status'] === 200 && str_contains($archivePage['body'], 'Archive task ' . $suffix)
        && $xpath->query('//button[@aria-label="Restore task"]')->length === 1
        && $xpath->query('//button[@aria-label="Complete task"]')->length === 0
        && $xpath->query('//a[@aria-label="Edit task"]')->length === 0
        && str_contains($archivePage['body'], 'Showing 1 of 1 task'), 'Archived queue shows restore and matching counts without editing controls');
    $restore = taskArchiveFields($archivePage['body'], $taskId, 'restore');
    taskArchiveHttp('tasks.php', $editor, array_replace($restore, ['task_version' => $before['updated_at']]));
    expectTaskArchive((int) fetchFollowUpTask($conn, $taskId)['is_archived'] === 1, 'A stale restore form cannot change the task');
    taskArchiveHttp('tasks.php', $sessions['reviewer'][0], array_replace($restore, ['csrf_token' => $sessions['reviewer'][1]]));
    expectTaskArchive((int) fetchFollowUpTask($conn, $taskId)['is_archived'] === 1, 'Reviewers cannot restore');
    taskArchiveHttp('tasks.php', $editor, array_replace($restore, ['action' => 'set_status', 'status' => 'completed']));
    expectTaskArchive(fetchFollowUpTask($conn, $taskId)['status'] === 'waiting', 'Archived tasks cannot be completed through a crafted request');
    expectTaskArchive(taskArchiveHttp('edit_task.php?id=' . $taskId, $editor)['status'] === 302, 'Archived tasks redirect from editing to the archive');
    $restored = taskArchiveHttp('tasks.php', $editor, $restore);
    expectTaskArchive($restored['status'] === 302 && (int) fetchFollowUpTask($conn, $taskId)['is_archived'] === 0
        && count(fetchFollowUpTasksForSubject($conn, 'engagement', $eventId)) === 1, 'Restoring returns the task to its original waiting state and record');
    // Stale archive forms must not hide a task that was restored or edited elsewhere.
    taskArchiveHttp('tasks.php', $editor, $fields);
    expectTaskArchive((int) fetchFollowUpTask($conn, $taskId)['is_archived'] === 0, 'Old archive forms are rejected');
    $current = fetchFollowUpTask($conn, $taskId);
    setFollowUpTaskStatus($conn, $taskId, 'completed', $current['updated_at'], $owner);
    $completed = fetchFollowUpTask($conn, $taskId);
    $fields = taskArchiveFields(taskArchiveHttp($queue . '&view=completed', $sessions['admin'][0])['body'], $taskId, 'archive');
    taskArchiveHttp('tasks.php', $sessions['admin'][0], $fields);
    $restore = taskArchiveFields(taskArchiveHttp($archivedQueue, $sessions['admin'][0])['body'], $taskId, 'restore');
    taskArchiveHttp('tasks.php', $sessions['admin'][0], $restore);
    $current = fetchFollowUpTask($conn, $taskId);
    expectTaskArchive($current['status'] === 'completed' && $current['completed_at'] === $completed['completed_at']
        && $current['completed_by'] === $completed['completed_by'], 'Locked admins can archive and restore without changing completion history');
    generateEngagementFollowUpChecklist($conn, $eventId, $owner, $owner);
    $generated = $conn->query("SELECT * FROM follow_up_tasks WHERE engagement_id = {$eventId} AND template_key IS NOT NULL LIMIT 1")->fetch_assoc();
    expectTaskArchive($generated !== null, 'Checklist fixture was generated');
    setFollowUpTaskArchived($conn, (int) $generated['id'], true, $generated['updated_at'], $owner);
    expectTaskArchive(generateEngagementFollowUpChecklist($conn, $eventId, $owner, $owner) === 0, 'Adding missing checklist tasks does not recreate archived tasks');
} finally {
    if ($orgId > 0) {
        $conn->query("DELETE FROM engagements WHERE organization_id = {$orgId}");
        $conn->query("DELETE FROM organizations WHERE id = {$orgId}");
    }
    foreach ($users as $id) $conn->query("DELETE FROM users WHERE id = {$id}");
    foreach ($sessions as [$id]) { session_id($id); session_start(); session_destroy(); session_id(''); }
}
echo "Task archiving HTTP integration tests passed.\n";
