<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("Engagement cancellation tests require a disposable environment.\n");
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once __DIR__ . '/integration_auth_helpers.php';
$checks = 0;
$expect = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$csrf = static function (array $response): string {
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $response['body'], $match);
    if (empty($match[1])) throw new RuntimeException('Missing cancellation test CSRF token.');
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
};
$suffix = 'cancel-' . bin2hex(random_bytes(5));
$clients = $actors = $cookies = [];
try {
    foreach (['editor', 'admin', 'reviewer'] as $role) {
        $password = bin2hex(random_bytes(16));
        $username = $suffix . '-' . $role;
        $conn->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)', [$username, password_hash($password, PASSWORD_DEFAULT), $role]);
        $actors[$role] = (int) $conn->insert_id;
        $cookie = tempnam(sys_get_temp_dir(), 'cancel-http-');
        $cookies[] = $cookie;
        $client = static function (string $path, ?array $post = null) use ($cookie): array {
            $curl = curl_init('http://127.0.0.1/' . $path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 20]);
            if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
            $raw = curl_exec($curl);
            if (!is_string($raw)) throw new RuntimeException(curl_error($curl));
            $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
        };
        $login = $client('login.php');
        $login = $client('login.php', ['csrf_token' => $csrf($login), 'username' => $username, 'password' => $password]);
        $login = finishIntegrationTestEnrollment($client, $login);
        $expect($login['status'] === 302, 'Cancellation fixture login: ' . $role);
        $clients[$role] = $client;
    }
    $conn->execute_query('INSERT INTO organizations(organization_name) VALUES (?)', [$suffix]);
    $organizationId = (int) $conn->insert_id;
    $events = [];
    foreach (['main', 'other', 'archived', 'closed', 'admin'] as $label) {
        $conn->execute_query("INSERT INTO engagements(organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
            VALUES (?,?,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'conference','under_review')", [$organizationId, $suffix . ' ' . $label]);
        $events[$label] = (int) $conn->insert_id;
    }
    $conn->execute_query('UPDATE engagements SET is_deleted=1 WHERE id=?', [$events['archived']]);
    $conn->execute_query("UPDATE engagements SET lifecycle_status='completed' WHERE id=?", [$events['closed']]);
    $conn->execute_query('INSERT INTO engagement_financial_reports(engagement_id,closed_by) VALUES (?,?)', [$events['closed'], $actors['admin']]);
    $tasks = [];
    foreach (['open', 'in_progress', 'waiting', 'completed', 'canceled', 'already_archived', 'unrelated'] as $kind) {
        $status = in_array($kind, ['already_archived', 'unrelated'], true) ? 'open' : $kind;
        $conn->execute_query("INSERT INTO follow_up_tasks(title,status,subject_type,engagement_id,created_by,waiting_on,completed_by,completed_at,due_date)
            VALUES (?,?,'engagement',?,?,?,?,?,?)", [$suffix . ' ' . $kind, $status,
            $kind === 'unrelated' ? $events['other'] : $events['main'], $actors['editor'], $status === 'waiting' ? 'Host' : null,
            $status === 'completed' ? $actors['editor'] : null, $status === 'completed' ? '2026-01-02 03:04:05' : null,
            in_array($kind, ['completed', 'canceled'], true) ? '2026-01-01' : '2027-01-01']);
        $tasks[$kind] = (int) $conn->insert_id;
    }
    $conn->execute_query("UPDATE follow_up_tasks SET is_archived=1,archived_by=?,archived_at='2026-01-02 03:04:05' WHERE id=?", [$actors['admin'], $tasks['already_archived']]);
    $event = static fn(int $id): array => $conn->execute_query('SELECT * FROM engagements WHERE id=?', [$id])->fetch_assoc();
    $taskRows = static fn(): array => $conn->execute_query('SELECT id,status,waiting_on,completed_by,completed_at,is_archived,archived_by,archived_at FROM follow_up_tasks WHERE engagement_id=? ORDER BY id', [$events['main']])->fetch_all(MYSQLI_ASSOC);
    $before = $event($events['main']); $beforeTasks = $taskRows();
    $path = 'engagements.php?lifecycle=all&q=' . urlencode($suffix);
    $editor = $clients['editor']; $admin = $clients['admin']; $reviewer = $clients['reviewer'];
    $page = $editor($path); $token = $csrf($page);
    $expect($page['status'] === 200 && str_contains($page['body'], 'data-cancel-engagement="' . $events['main'] . '"')
        && str_contains($page['body'], 'id="engagement-cancel-dialog"'), 'Editors see the cancel action and typed confirmation dialog');
    $expect(!str_contains($page['body'], 'data-cancel-engagement="' . $events['closed'] . '"'), 'Final financial closeout prevents cancellation in the UI');
    $reviewPage = $reviewer($path);
    $expect(!str_contains($reviewPage['body'], 'data-cancel-engagement=') && !str_contains($reviewPage['body'], 'id="engagement-cancel-dialog"'), 'Reviewer cannot see cancellation controls');
    $post = ['csrf_token' => $token, 'action' => 'cancel', 'engagement_id' => $events['main'],
        'expected_version' => $before['updated_at'], 'cancel_confirmation' => 'CANCEL',
        'cancellation_reason' => 'The host canceled this fixture.', 'return_to' => $path];
    $expect($reviewer('engagements.php', array_replace($post, ['csrf_token' => $csrf($reviewPage)]))['status'] === 403, 'Direct reviewer cancellation is forbidden');
    $expect($editor('engagements.php', array_replace($post, ['csrf_token' => 'invalid']))['status'] === 400, 'Cancellation requires a valid CSRF token');
    foreach (['', 'cancel', 'CANCEL ', ['CANCEL']] as $phrase) {
        $expect($editor('engagements.php', array_replace($post, ['cancel_confirmation' => $phrase]))['status'] === 400, 'Server requires exact scalar CANCEL confirmation');
    }
    $editor($path . '&action=cancel&engagement_id=' . $events['main'] . '&cancel_confirmation=CANCEL');
    foreach (['', str_repeat('x', 1001)] as $reason) {
        $editor('engagements.php', array_replace($post, ['cancellation_reason' => $reason]));
        $expect(str_contains($editor($path)['body'], 'Enter a cancellation reason'), 'Cancellation reason validation reaches the list');
    }
    $editor('engagements.php', array_replace($post, ['expected_version' => '2000-01-01 00:00:00']));
    $expect(str_contains($editor($path)['body'], 'changed after the list was opened'), 'Stale list cancellation is rejected');
    $expect($event($events['main']) === $before && $taskRows() === $beforeTasks, 'Rejected requests and GET leave the engagement and every task unchanged');
    foreach (['archived', 'closed'] as $label) {
        $row = $event($events[$label]);
        $editor('engagements.php', array_replace($post, ['engagement_id' => $row['id'], 'expected_version' => $row['updated_at']]));
        $expect($event($events[$label]) === $row, 'Direct cancellation preserves an ' . $label . ' engagement');
    }
    $result = $editor('engagements.php', $post);
    $expect($result['status'] === 302 && str_contains($result['headers'], 'Location: ' . $path), 'Cancellation returns to the original filtered list');
    $canceled = $event($events['main']);
    $expect($canceled['lifecycle_status'] === 'canceled' && $canceled['cancellation_reason'] === $post['cancellation_reason']
        && (int) $canceled['lifecycle_changed_by'] === $actors['editor'] && $canceled['lifecycle_changed_at'] !== $before['lifecycle_changed_at']
        && !$canceled['is_deleted'], 'Lifecycle, reason, and actor are recorded without archiving the engagement');
    $afterTasks = array_column($taskRows(), null, 'id');
    foreach ($tasks as $kind => $id) {
        if ($kind === 'unrelated') continue;
        $row = $afterTasks[$id];
        $expect((int) $row['is_archived'] === 1 && $row['archived_at'] !== null, 'All associated task states are archived: ' . $kind);
        if ($kind !== 'already_archived') $expect((int) $row['archived_by'] === $actors['editor'], 'Task archive records the canceling editor');
    }
    $expect($afterTasks[$tasks['completed']]['status'] === 'completed'
        && $afterTasks[$tasks['completed']]['completed_at'] === '2026-01-02 03:04:05'
        && (int) $afterTasks[$tasks['completed']]['completed_by'] === $actors['editor'], 'Completed task evidence survives archiving');
    foreach (['open', 'in_progress', 'waiting'] as $kind) $expect($afterTasks[$tasks[$kind]]['status'] === 'canceled' && $afterTasks[$tasks[$kind]]['waiting_on'] === null, 'Pending task stops when the engagement is canceled');
    $expect($afterTasks[$tasks['already_archived']] === array_column($beforeTasks, null, 'id')[$tasks['already_archived']], 'Prior archive history remains unchanged');
    $expect((int) $conn->execute_query('SELECT is_archived FROM follow_up_tasks WHERE id=?', [$tasks['unrelated']])->fetch_assoc()['is_archived'] === 0, 'Other engagements’ tasks remain active');
    $editor('engagements.php', $post);
    $expect($event($events['main']) === $canceled && array_column($taskRows(), null, 'id') === $afterTasks, 'A repeated cancellation cannot rewrite history');
    $adminPage = $admin($path); $adminRow = $event($events['admin']);
    $expect(str_contains($adminPage['body'], 'data-cancel-engagement="' . $events['admin'] . '"'), 'Administrators see the cancellation action');
    $admin('engagements.php', array_replace($post, ['csrf_token' => $csrf($adminPage), 'engagement_id' => $adminRow['id'], 'expected_version' => $adminRow['updated_at']]));
    $expect($event($events['admin'])['lifecycle_status'] === 'canceled', 'An administrator can cancel an engagement with no tasks');
    echo "Engagement cancellation HTTP: $checks checks passed; disposable records only.\n";
} finally {
    foreach ($cookies as $cookie) @unlink($cookie);
}
