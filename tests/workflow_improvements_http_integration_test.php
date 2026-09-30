<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Workflow improvement HTTP tests skipped (requires disposable database).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/follow_up_task_helpers.php';
require_once $source . '/global_search_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';
function workflowExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function workflowHidden(string $html, string $name): string {
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $matches);
    workflowExpect(isset($matches[1]), 'Missing field: ' . $name);
    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
workflowExpect(in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true), 'Use loopback HTTP');
$cookies = tempnam(sys_get_temp_dir(), 'workflow-http-');
$request = static function (string $path, ?array $post = null) use ($base, $cookies): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = curl_exec($curl);
    workflowExpect(is_string($raw), 'HTTP request failed');
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
};
$uid = $taskId = $orgId = 0;
$suffix = 'workflow-' . bin2hex(random_bytes(5));
try {
    workflowExpect($request('search.php?q=private')['status'] === 302, 'Search requires authentication');
    $password = bin2hex(random_bytes(16));
    $conn->execute_query("INSERT INTO users (username,password,role) VALUES (?,?,'editor')", [$suffix, password_hash($password, PASSWORD_DEFAULT)]);
    $uid = (int) $conn->insert_id;
    $login = $request('login.php');
    $login = $request('login.php', ['csrf_token' => workflowHidden($login['body'], 'csrf_token'), 'username' => $suffix, 'password' => $password]);
    $login = finishIntegrationTestEnrollment($request, $login);
    workflowExpect($login['status'] === 302, 'Fixture editor authenticates normally');
    for ($n = 0; $n < 22; $n++) {
        $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', [$suffix . ' <sample> ' . sprintf('%02d', $n)]);
        $orgId = (int) $conn->insert_id;
    }
    $groups = fetchGlobalSearchResults($conn, $suffix);
    workflowExpect(count($groups['organizations']['rows']) === 5 && $groups['organizations']['more'], 'Grouped search has bounded results and a more link');
    $pageTwo = fetchGlobalSearchResults($conn, $suffix, 'organizations', 2);
    workflowExpect(count($pageTwo['organizations']['rows']) === 2 && !$pageTwo['organizations']['more'], 'Search pagination reaches every matching record');
    workflowExpect(fetchGlobalSearchResults($conn, '%') === [], 'Short queries cannot scan everything');
    workflowExpect(fetchGlobalSearchResults($conn, $suffix . '%')['organizations']['rows'] === [], 'Percent characters are literal search text');
    $html = $request('search.php?q=' . rawurlencode($suffix));
    workflowExpect($html['status'] === 200 && str_contains($html['body'], '&lt;sample&gt;') && !str_contains($html['body'], '<sample>'), 'Search results escape record names');
    $conn->execute_query('UPDATE organizations SET is_deleted=1 WHERE id=?', [$orgId]);
    workflowExpect(count(fetchGlobalSearchResults($conn, $suffix, 'organizations', 2)['organizations']['rows']) === 1, 'Archived records are excluded');
    $conn->execute_query("INSERT INTO follow_up_tasks (title,status,waiting_on,subject_type,assigned_to,created_by) VALUES (?,'waiting','Host reply','general',?,?)", [$suffix, $uid, $uid]);
    $taskId = (int) $conn->insert_id;
    $task = fetchFollowUpTask($conn, $taskId);
    $queue = $request('tasks.php?scope=everyone');
    $csrf = workflowHidden($queue['body'], 'csrf_token');
    $complete = ['csrf_token' => $csrf, 'action' => 'set_status', 'task_id' => $taskId,
        'status' => 'completed', 'task_version' => $task['updated_at'], 'return_to' => 'tasks.php?scope=everyone&view=completed'];
    workflowExpect($request('tasks.php', $complete)['status'] === 302, 'Task completes in one request');
    $queue = $request('tasks.php?scope=everyone&view=completed');
    $undo = ['csrf_token' => $csrf, 'action' => 'undo_completion', 'undo_token' => workflowHidden($queue['body'], 'undo_token'), 'return_to' => 'tasks.php'];
    workflowExpect($request('tasks.php', $undo)['status'] === 302, 'Undo follows normal CSRF-protected POST');
    $restored = fetchFollowUpTask($conn, $taskId);
    workflowExpect($restored['status'] === 'waiting' && $restored['waiting_on'] === 'Host reply', 'Undo restores the original waiting state and reason');
    $complete['task_version'] = $restored['updated_at'];
    $request('tasks.php', $complete);
    $queue = $request('tasks.php?view=completed&scope=everyone');
    $undo['undo_token'] = workflowHidden($queue['body'], 'undo_token');
    $conn->execute_query('UPDATE follow_up_tasks SET details=? WHERE id=?', ['Concurrent edit', $taskId]);
    $request('tasks.php', $undo);
    workflowExpect(fetchFollowUpTask($conn, $taskId)['status'] === 'completed', 'Undo cannot overwrite a concurrent edit');
    $queue = $request('tasks.php?scope=everyone&view=completed&task_id=' . $taskId);
    workflowExpect($queue['status'] === 200 && str_contains($queue['body'], $suffix), 'Search task destination reaches a completed task');
    foreach (['engagements.php', 'contacts.php', 'organizations.php', 'speakers.php', 'inquiries.php', 'inbound_mail.php', 'reimbursements.php'] as $route) {
        $response = $request($route);
        workflowExpect($response['status'] === 200 && !preg_match('/(?:Fatal error|Warning:|Undefined variable)/', $response['body']), 'List renders cleanly: ' . $route);
    }
    $receipt = null;
    $conn->execute_query("UPDATE follow_up_tasks SET status='open',completed_at=NULL,completed_by=NULL WHERE id=?", [$taskId]);
    $task = fetchFollowUpTask($conn, $taskId);
    setFollowUpTaskStatus($conn, $taskId, 'completed', $task['updated_at'], $uid, $receipt);
    $receipt['expires'] = time() - 1;
    try { undoFollowUpTaskCompletion($conn, $receipt, $uid); throw new RuntimeException('Expired undo was accepted'); }
    catch (InvalidArgumentException $expected) {}
    $receipt['expires'] = time() + 60;
    try { undoFollowUpTaskCompletion($conn, $receipt, $uid + 1); throw new RuntimeException('Wrong actor was accepted'); }
    catch (InvalidArgumentException $expected) {}
    echo "Workflow improvements HTTP integration tests passed.\n";
} finally {
    if ($taskId) $conn->execute_query('DELETE FROM follow_up_tasks WHERE id=?', [$taskId]);
    $conn->execute_query('DELETE FROM organizations WHERE organization_name LIKE ?', [$suffix . '%']);
    if ($uid) $conn->execute_query('DELETE FROM users WHERE id=?', [$uid]);
    unlink($cookies);
}
