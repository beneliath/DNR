<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Standard task generation HTTP tests skipped (requires a disposable database and HTTP server).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once __DIR__ . '/integration_auth_helpers.php';
require_once $source . '/follow_up_task_helpers.php';
$base = rtrim((string) (getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Use a loopback test server.');
function expectStandardTaskHttp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Standard task HTTP test failed: ' . $message);
}
$request = static function (string $path, ?array $post, string $cookie = '') use ($base): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => $cookie]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
};
$users = $sessions = [];
$organization = 0;
try {
    foreach (['admin', 'editor', 'reviewer'] as $role) {
        $name = 'standard-http-' . bin2hex(random_bytes(5));
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $hash, $role); $stmt->execute();
        $users[$role] = (int) $conn->insert_id; $stmt->close();
        session_id(''); startSecureSession();
        $_SESSION = ['user_id' => $users[$role], 'username' => $name, 'role' => $role, 'authenticated_role' => $role,
            'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        completeIntegrationTestMfaSession();
        $sessions[$role] = ['cookie' => session_name() . '=' . session_id(), 'id' => session_id(), 'csrf' => $_SESSION['_csrf_token']];
        session_write_close();
    }
    $admin = $sessions['admin']; $editor = $sessions['editor']; $reviewer = $sessions['reviewer'];
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Standard task HTTP fixture')");
    $organization = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status) VALUES ({$organization}, 'HTTP fixture', '2026-10-10', '2026-10-12', 'conference', 'confirmed')");
    $engagement = (int) $conn->insert_id;
    $page = $request('add_standard_task.php', null, $admin['cookie']);
    expectStandardTaskHttp($page['status'] === 200
        && preg_match('/<input[^>]*name="generate_existing_engagements"[^>]*>/', $page['body'], $checkbox) === 1
        && !str_contains($checkbox[0], ' checked'), 'the creation option should be available and unchecked by default');
    $input = ['csrf_token' => $admin['csrf'], 'save_standard_task' => '1', 'title' => 'HTTP standard task', 'details' => 'Task notes',
        'priority' => 'high', 'due_anchor' => 'event_start', 'due_offset_days' => '-3', 'sort_order' => '62'];
    $save = $request('add_standard_task.php', $input, $admin['cookie']);
    expectStandardTaskHttp($save['status'] === 302 && preg_match('/Location: view_standard_task.php\?id=(\d+)/i', $save['headers'], $match) === 1, 'saving without the option should create the definition');
    $id = (int) $match[1]; $path = 'view_standard_task.php?id=' . $id;
    expectStandardTaskHttp((int) fetchStandardEventTask($conn, $id)['generated_count'] === 0, 'opting out should leave existing engagements untouched');
    $page = $request($path, null, $admin['cookie']);
    expectStandardTaskHttp(str_contains($page['body'], 'Add to Active, Open Engagements'), 'saved definitions should offer the batch action');
    $editorPage = $request('add_standard_task.php', null, $editor['cookie']);
    expectStandardTaskHttp($editorPage['status'] === 200 && !str_contains($editorPage['body'], 'name="generate_existing_engagements"'), 'editors may create future definitions but must not see the bulk option');
    expectStandardTaskHttp(!str_contains($request($path, null, $editor['cookie'])['body'], 'Add to Active, Open Engagements'), 'editors must not see the saved bulk action');
    expectStandardTaskHttp($request('add_standard_task.php', array_replace($input, ['csrf_token' => $editor['csrf'], 'generate_existing_engagements' => '1']), $editor['cookie'])['status'] === 403, 'editors cannot forge bulk generation while creating a definition');
    $editorSave = $request('add_standard_task.php', array_replace($input, ['csrf_token' => $editor['csrf']]), $editor['cookie']);
    expectStandardTaskHttp($editorSave['status'] === 302, 'editors can still save definitions without bulk generation');
    $action = ['csrf_token' => $admin['csrf'], 'action' => 'generate_existing_engagements'];
    expectStandardTaskHttp($request($path, array_replace($action, ['csrf_token' => 'invalid']), $admin['cookie'])['status'] === 400, 'batch generation must require CSRF validation');
    expectStandardTaskHttp($request($path, array_replace($action, ['csrf_token' => $reviewer['csrf']]), $reviewer['cookie'])['status'] === 403, 'reviewers cannot generate tasks');
    expectStandardTaskHttp($request($path, array_replace($action, ['csrf_token' => $editor['csrf']]), $editor['cookie'])['status'] === 403, 'editors cannot invoke the saved bulk action');
    expectStandardTaskHttp((int) fetchStandardEventTask($conn, $id)['generated_count'] === 0, 'rejected requests must not generate copies');
    expectStandardTaskHttp($request($path, $action, $admin['cookie'])['status'] === 302, 'administrators can generate from a saved definition');
    $count = (int) fetchStandardEventTask($conn, $id)['generated_count'];
    expectStandardTaskHttp($count > 0, 'the saved-definition action must create tasks');
    $request($path, $action, $admin['cookie']);
    expectStandardTaskHttp((int) fetchStandardEventTask($conn, $id)['generated_count'] === $count, 'repeated submissions must not duplicate tasks');
    $save = $request('add_standard_task.php', array_replace($input, ['generate_existing_engagements' => '1']), $admin['cookie']);
    expectStandardTaskHttp($save['status'] === 302 && preg_match('/Location: view_standard_task.php\?id=(\d+)/i', $save['headers'], $match) === 1, 'the checked option should save and generate in one request');
    expectStandardTaskHttp((int) fetchStandardEventTask($conn, (int) $match[1])['generated_count'] > 0, 'creating with the option must generate copies');
    $beforeTemplates = (int) $conn->query('SELECT COUNT(*) AS total FROM standard_event_tasks')->fetch_assoc()['total'];
    $beforeTasks = (int) $conn->query('SELECT COUNT(*) AS total FROM follow_up_tasks')->fetch_assoc()['total'];
    $conn->query("UPDATE engagements SET event_start_date = '9999-12-31', event_end_date = '9999-12-31' WHERE id = {$engagement}");
    $invalid = $request('add_standard_task.php', array_replace($input, ['generate_existing_engagements' => '1', 'due_offset_days' => '7']), $admin['cookie']);
    expectStandardTaskHttp($invalid['status'] === 200 && str_contains($invalid['body'], 'outside the supported date range')
        && preg_match('/<input[^>]*name="generate_existing_engagements"[^>]* checked/', $invalid['body']) === 1
        && str_contains($invalid['body'], 'value="HTTP standard task"'), 'batch failures should preserve the draft and checked option');
    expectStandardTaskHttp((int) $conn->query('SELECT COUNT(*) AS total FROM standard_event_tasks')->fetch_assoc()['total'] === $beforeTemplates
        && (int) $conn->query('SELECT COUNT(*) AS total FROM follow_up_tasks')->fetch_assoc()['total'] === $beforeTasks,
        'a failed batch should roll back the new definition and every partial copy');
} finally {
    setDatabaseAuditContext($conn);
    foreach ($users as $id) {
        $conn->query("DELETE FROM follow_up_tasks WHERE created_by = {$id}");
        $conn->query("DELETE FROM standard_event_tasks WHERE created_by = {$id}");
    }
    if ($organization) {
        $conn->query("DELETE FROM engagements WHERE organization_id = {$organization}");
        $conn->query("DELETE FROM organizations WHERE id = {$organization}");
    }
    foreach ($users as $id) $conn->query("DELETE FROM users WHERE id = {$id}");
    foreach ($sessions as $session) { session_id($session['id']); startSecureSession(); session_destroy(); }
}
echo "Standard task generation HTTP integration tests passed.\n";
