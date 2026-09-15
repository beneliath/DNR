<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Review regression HTTP tests skipped (requires disposable database and HTTP server).\n";
    exit;
}
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src') . '/bootstrap.php';
require_once __DIR__ . '/integration_auth_helpers.php';
function expectReviewRegression(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function reviewRegressionHttp(string $path, string $session, ?array $post = null): array {
    $curl = curl_init(rtrim(getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $session, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
}
function reviewRegressionForm(string $html): array {
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[.//input[@name="task_version"]]')->item(0);
    expectReviewRegression($form instanceof DOMElement, 'Task edit form is present');
    $fields = [];
    foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $input) {
        $name = $input->getAttribute('name');
        if ($name === '' || $input->hasAttribute('disabled')) continue;
        if (in_array($input->getAttribute('type'), ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) continue;
        if ($input->tagName === 'select') {
            $option = $xpath->query('.//option[@selected]', $input)->item(0) ?? $xpath->query('.//option', $input)->item(0);
            $fields[$name] = $option?->getAttribute('value') ?? '';
        } else {
            $fields[$name] = $input->tagName === 'textarea' ? $input->textContent : $input->getAttribute('value');
        }
    }
    return $fields;
}
$uid = $taskId = $templateId = 0;
$sessions = [];
try {
    $rows = $conn->prepare("SELECT 1 AS id, NULL AS label UNION ALL SELECT 2, 'Second'");
    $rows->execute();
    expectReviewRegression(iterator_to_array(\Dnr\Infrastructure\StatementRows::stream($rows))
        === [['id' => 1, 'label' => null], ['id' => 2, 'label' => 'Second']],
        'Streamed rows retain their values after later fetches, including NULL fields');
    $rows->close();
    $suffix = bin2hex(random_bytes(6));
    $conn->execute_query("INSERT INTO users (username,password,role) VALUES (?,?,'editor')",
        ['review-regression-' . $suffix, password_hash('ReviewRegressionFixture!123', PASSWORD_DEFAULT)]);
    $uid = (int) $conn->insert_id;
    $user = $conn->execute_query('SELECT * FROM users WHERE id = ?', [$uid])->fetch_assoc();
    session_start();
    $_SESSION = ['user_id' => $uid, 'username' => $user['username'], 'role' => 'editor',
        'auth_version' => (int) $user['auth_version'], 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
    completeIntegrationTestMfaSession();
    $session = $sessions[] = session_id();
    session_write_close();
    $conn->execute_query("INSERT INTO follow_up_tasks (title,subject_type,created_by) VALUES ('Original task','general',?)", [$uid]);
    $taskId = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO standard_event_tasks (template_key,title) VALUES (?, 'Original template')", ['review-' . $suffix]);
    $templateId = (int) $conn->insert_id;
    foreach ([['edit_task.php', 'follow_up_tasks', $taskId, 'save_task'],
        ['edit_standard_task.php', 'standard_event_tasks', $templateId, 'save_standard_task']] as [$route, $table, $id, $action]) {
        $path = $route . '?id=' . $id;
        $form = reviewRegressionForm(reviewRegressionHttp($path, $session)['body']);
        $oldVersion = $form['task_version'];
        $conn->execute_query("UPDATE {$table} SET title = 'Newer edit', updated_at = updated_at + INTERVAL 1 SECOND WHERE id = ?", [$id]);
        $form['title'] = 'Stale draft'; $form[$action] = '1';
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = reviewRegressionHttp($path, $session, $form);
            expectReviewRegression($response['status'] === 200 && str_contains($response['body'], 'changed in another session'), $route . ' must reject both stale attempts');
            $form = reviewRegressionForm($response['body']); $form[$action] = '1';
            expectReviewRegression($form['task_version'] === $oldVersion && $form['title'] === 'Stale draft', 'Conflict preserves draft and stale version together');
            expectReviewRegression($conn->execute_query("SELECT title FROM {$table} WHERE id = ?", [$id])->fetch_assoc()['title'] === 'Newer edit', 'Repeated save cannot overwrite the newer content');
        }
        $fresh = reviewRegressionForm(reviewRegressionHttp($path, $session)['body']);
        $fresh['title'] = 'Reviewed edit'; $fresh[$action] = '1';
        expectReviewRegression(reviewRegressionHttp($path, $session, $fresh)['status'] === 302, 'Reloaded current form remains editable');
    }

    $conn->begin_transaction();
    for ($i = 0; $i < 501; $i++) {
        $conn->execute_query('INSERT INTO booking_inquiries (title,stage,owner_user_id) VALUES (?,?,?)',
            [$i === 0 ? '=1+1' : 'Capacity ' . $suffix . ' ' . $i, $i < 250 ? 'new' : 'proposal_sent', $uid]);
    }
    $conn->commit();
    $seen = [];
    for ($page = 1; $page <= 6; $page++) {
        $response = reviewRegressionHttp('inquiries.php?owner=me&per_page=100&page=' . $page, $session);
        expectReviewRegression($response['status'] === 200 && str_contains($response['body'], 'of 501 inquiries'), 'Board shows complete filtered total');
        expectReviewRegression(str_contains($response['body'], 'id="stage-new">New</h2><strong>250</strong>')
            && str_contains($response['body'], 'id="stage-proposal_sent">Proposal Sent</h2><strong>251</strong>'),
            'Stage totals include all filtered records on every page');
        preg_match_all('/<h3><a href="view_inquiry.php\?id=(\d+)"/', $response['body'], $matches);
        expectReviewRegression(count($matches[1]) === ($page === 6 ? 1 : 100), 'Every board page has its expected records');
        array_push($seen, ...$matches[1]);
    }
    expectReviewRegression(count(array_unique($seen)) === 501, 'Pagination reaches every matching inquiry exactly once');
    $csv = reviewRegressionHttp('inquiries.php?owner=me&per_page=20&page=6&export=csv', $session);
    $lines = explode("\n", trim($csv['body']));
    expectReviewRegression($csv['status'] === 200 && count($lines) === 502, 'CSV exports all filtered records regardless of board page');
    expectReviewRegression(str_contains($csv['body'], "'=1+1"), 'Streaming export retains spreadsheet formula protection');
    $exportIds = array_map(static fn(string $line): string => str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1));
    sort($seen); sort($exportIds);
    expectReviewRegression($seen === $exportIds, 'Export and paginated board contain the same inquiries');

    session_id($session); session_start();
    $_SESSION['_session_rotated_at'] = time() - 1000;
    session_write_close();
    $first = reviewRegressionHttp('edit_task.php?id=' . $taskId, $session);
    $second = reviewRegressionHttp('edit_task.php?id=' . $taskId, $session);
    preg_match('/Set-Cookie: PHPSESSID=([^;\r\n]+)/i', $first['headers'], $cookie1);
    preg_match('/Set-Cookie: PHPSESSID=([^;\r\n]+)/i', $second['headers'], $cookie2);
    expectReviewRegression($first['status'] === 200 && $second['status'] === 200
        && !empty($cookie1[1]) && $cookie1[1] === ($cookie2[1] ?? null) && $cookie1[1] !== $session,
        'Old-cookie requests receive the same authenticated successor cookie');
    $sessions[] = $cookie1[1];
} finally {
    $conn->rollback();
    if ($uid) $conn->execute_query('DELETE FROM booking_inquiries WHERE owner_user_id = ?', [$uid]);
    if ($taskId) $conn->execute_query('DELETE FROM follow_up_tasks WHERE id = ?', [$taskId]);
    if ($templateId) $conn->execute_query('DELETE FROM standard_event_tasks WHERE id = ?', [$templateId]);
    if ($uid) $conn->execute_query('DELETE FROM users WHERE id = ?', [$uid]);
    foreach ($sessions as $id) { session_id($id); session_start(); session_destroy(); }
}
echo "Review regression HTTP tests passed (repeated task conflicts, complete inquiry pagination/export, rotation cookies).\n";
