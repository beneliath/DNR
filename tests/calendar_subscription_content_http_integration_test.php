<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Calendar subscription content HTTP tests skipped (disposable server required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/calendar_helpers.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Loopback server required.');
}
function expectCalendarContent(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$request = static function (string $path, ?array $post = null, string $cookie = '', string $etag = '') use ($base): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Calendar subscription integration test']);
    if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    if ($etag !== '') curl_setopt($curl, CURLOPT_HTTPHEADER, ['If-None-Match: ' . $etag]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException(curl_error($curl));
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $length);
    preg_match('/^ETag:\s*(.+)$/mi', $headers, $match);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers,
        'body' => str_replace("\r\n ", '', substr($raw, $length)), 'etag' => trim($match[1] ?? '')];
};
$userIds = [];
$sessionId = '';
$organizationId = 0;
try {
    foreach (['owner', 'other'] as $suffix) {
        $name = 'calendar-content-' . $suffix . '-' . bin2hex(random_bytes(4));
        $hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username,password,role) VALUES (?,?,'reviewer')");
        $stmt->bind_param('ss', $name, $hash);
        $stmt->execute();
        $userIds[] = (int) $conn->insert_id;
    }
    [$ownerId, $otherId] = $userIds;
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Calendar content HTTP fixture')");
    $organizationId = (int) $conn->insert_id;
    $date = applicationBusinessDate();
    $birthday = substr($date, 5, 2) . '/' . substr($date, 8, 2);
    $conn->query("INSERT INTO engagements (organization_id,event_title,event_type,confirmation_status,event_start_date,event_end_date)
        VALUES ($organizationId,'Calendar event fixture','conference','confirmed','$date','$date')");
    $engagementId = (int) $conn->insert_id;
    $speakerId = (int) $conn->query('SELECT id FROM speakers ORDER BY id LIMIT 1')->fetch_assoc()['id'];
    $conn->query("INSERT INTO presentations (engagement_id,speaker_id,topic_title,presentation_date,presentation_time)
        VALUES ($engagementId,$speakerId,'Calendar presentation fixture','$date','09:00:00')");
    $presentationId = (int) $conn->insert_id;
    $conn->query("INSERT INTO contacts (organization_id,contact_first_name,contact_last_name,contact_email,contact_phone,contact_birthday)
        VALUES ($organizationId,'Calendar','Birthday Fixture','calendar@example.test','+12025550124','$birthday')");
    $contactId = (int) $conn->insert_id;
    $taskIds = [];
    foreach ([[$ownerId, 'open', $date], [$otherId, 'waiting', $date], [null, 'in_progress', $date],
        [$ownerId, 'completed', $date], [$ownerId, 'canceled', $date], [$ownerId, 'open', null],
        [$ownerId, 'open', '2000-01-01']] as [$assignee, $status, $due]) {
        $completed = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare("INSERT INTO follow_up_tasks (title,details,waiting_on,assigned_to,status,due_date,created_by,completed_at)
            VALUES ('Calendar work fixture','PRIVATE TASK DETAIL','PRIVATE WAITING NOTE',?,?,?,?,?)");
        $stmt->bind_param('issis', $assignee, $status, $due, $ownerId, $completed);
        $stmt->execute();
        $taskIds[] = (int) $conn->insert_id;
    }
    $uids = ['events' => "engagement-$engagementId", 'presentations' => "presentation-$presentationId",
        'birthdays' => "contact-birthday-$contactId", 'my_work' => 'task-' . $taskIds[0],
        'other_work' => 'task-' . $taskIds[1], 'unassigned_work' => 'task-' . $taskIds[2]];
    $assertContent = static function (array $response, array $expected) use ($uids, $taskIds): void {
        expectCalendarContent($response['status'] === 200, 'Calendar feed must return HTTP 200');
        foreach ($uids as $key => $uid) {
            expectCalendarContent(str_contains($response['body'], 'UID:' . $uid . '@dnr-calendar') === in_array($key, $expected, true), 'Incorrect feed inclusion: ' . $key);
        }
        foreach (array_slice($taskIds, 3) as $taskId) {
            expectCalendarContent(!str_contains($response['body'], "UID:task-$taskId@dnr-calendar"), 'Completed, canceled, undated, and out-of-window work must be absent');
        }
        expectCalendarContent(!str_contains($response['body'], 'PRIVATE'), 'Private task details must be excluded');
    };

    // An insert using the old schema fields represents an existing subscription.
    $legacyToken = bin2hex(random_bytes(24));
    $legacyHash = calendarTokenHash($legacyToken);
    $stmt = $conn->prepare("INSERT INTO calendar_subscriptions (user_id,label,token_hash) VALUES (?,'Legacy',?)");
    $stmt->bind_param('is', $ownerId, $legacyHash);
    $stmt->execute();
    $assertContent($request('calendar.php?token=' . $legacyToken), ['events', 'presentations', 'birthdays']);

    startSecureSession();
    $sessionId = session_id();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['user_id' => $ownerId, 'username' => 'Calendar fixture', 'role' => 'reviewer',
        'authenticated_role' => 'reviewer', 'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => $csrf];
    $cookie = session_name() . '=' . $sessionId;
    session_write_close();
    expectCalendarContent($request('view_calendar.php')['status'] === 302, 'Subscription management requires login');
    expectCalendarContent($request('view_calendar.php', ['action' => 'create', 'label' => 'No CSRF', 'content' => ['events']], $cookie)['status'] === 400, 'Creation requires CSRF');
    foreach ([[], ['unknown'], [['events']]] as $invalid) {
        $before = count(calendarSubscriptionsForUser($conn, $ownerId));
        $request('view_calendar.php', ['csrf_token' => $csrf, 'action' => 'create', 'label' => 'Invalid', 'content' => $invalid], $cookie);
        expectCalendarContent(count(calendarSubscriptionsForUser($conn, $ownerId)) === $before, 'Invalid selections must not create a token');
        $request('view_calendar.php', null, $cookie);
    }
    foreach ([['events'], ['presentations'], ['birthdays'], ['my_work'], ['all_work'],
        ['events', 'presentations', 'my_work', 'all_work', 'birthdays']] as $content) {
        $response = $request('view_calendar.php', ['csrf_token' => $csrf, 'action' => 'create', 'label' => 'Selected content', 'content' => $content], $cookie);
        expectCalendarContent($response['status'] === 302, 'Creation should redirect');
        expectCalendarContent(str_contains($response['headers'], 'Location: view_calendar.php#new-calendar-link'), 'Successful creation should jump to the new-link pane');
        $page = $request('view_calendar.php', null, $cookie);
        $dom = new DOMDocument();
        @$dom->loadHTML($page['body']);
        $url = (new DOMXPath($dom))->query('//input[@id="calendar-url"]')->item(0)?->getAttribute('value');
        expectCalendarContent(is_string($url) && $url !== '', 'Created link must be shown once');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = (string) $query['token'];
        $stored = calendarSubscriptionForToken($conn, $token);
        expectCalendarContent($stored !== null && (int) $stored['user_id'] === $ownerId, 'Settings must belong to the authenticated owner');
        foreach (normalizeCalendarSubscriptionContent($content) as $key => $value) {
            expectCalendarContent((string) $stored[$key] === (string) $value, 'Subscription must persist selected setting: ' . $key);
        }
        $expected = array_values(array_intersect($content, ['events', 'presentations', 'birthdays']));
        if (in_array('all_work', $content, true)) $expected = array_merge($expected, ['my_work', 'other_work', 'unassigned_work']);
        elseif (in_array('my_work', $content, true)) $expected[] = 'my_work';
        $feedPath = 'calendar.php?token=' . $token;
        $feed = $request($feedPath . '&user_id=' . $otherId . '&content[]=all_work');
        $assertContent($feed, $expected);
        expectCalendarContent($feed['etag'] !== '' && $request($feedPath, null, '', $feed['etag'])['status'] === 304, 'Unchanged subscription should support conditional refresh');
        expectCalendarContent(!str_contains($request('view_calendar.php', null, $cookie)['body'], 'id="calendar-url"'), 'Token must only be shown once');
        revokeCalendarSubscription($conn, $ownerId, (int) $stored['id']);
        expectCalendarContent($request($feedPath, null, '', $feed['etag'])['status'] === 404, 'Revocation must precede conditional cache handling');
    }
    $mine = createCalendarSubscription($conn, $ownerId, 'My work', ['my_work']);
    $other = createCalendarSubscription($conn, $otherId, 'Other owner', ['my_work']);
    $minePath = 'calendar.php?token=' . $mine['token'];
    $mineFeed = $request($minePath);
    $otherFeed = $request('calendar.php?token=' . $other['token'], null, '', $mineFeed['etag']);
    $assertContent($otherFeed, ['other_work']);
    expectCalendarContent($otherFeed['etag'] !== $mineFeed['etag'], 'Different owners must have distinct cache validators');
    $conn->query("UPDATE follow_up_tasks SET assigned_to=$otherId WHERE id={$taskIds[0]}");
    $reassigned = $request($minePath, null, '', $mineFeed['etag']);
    $assertContent($reassigned, []);
    $all = createCalendarSubscription($conn, $ownerId, 'Everyone', ['all_work']);
    $allPath = 'calendar.php?token=' . $all['token'];
    $allFeed = $request($allPath);
    $conn->query("UPDATE follow_up_tasks SET status='completed', completed_at=UTC_TIMESTAMP() WHERE id={$taskIds[0]}");
    $completedFeed = $request($allPath, null, '', $allFeed['etag']);
    $assertContent($completedFeed, ['other_work', 'unassigned_work']);
    $conn->query("DELETE FROM follow_up_tasks WHERE id={$taskIds[1]}");
    $deletedFeed = $request($allPath, null, '', $completedFeed['etag']);
    $assertContent($deletedFeed, ['unassigned_work']);
    $conn->query("UPDATE users SET account_status='inactive' WHERE id=$ownerId");
    expectCalendarContent($request($allPath, null, '', $deletedFeed['etag'])['status'] === 404, 'Inactive owners must lose feed access');
} finally {
    if ($sessionId !== '') @unlink(session_save_path() . '/sess_' . $sessionId);
    if ($userIds !== []) {
        $ids = implode(',', array_map('intval', $userIds));
        $conn->query("DELETE FROM follow_up_tasks WHERE created_by IN ($ids)");
        $conn->query("DELETE FROM calendar_subscriptions WHERE user_id IN ($ids)");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM engagements WHERE organization_id=$organizationId");
        $conn->query("DELETE FROM contacts WHERE organization_id=$organizationId");
        $conn->query("DELETE FROM organizations WHERE id=$organizationId");
    }
    foreach ($userIds as $id) $conn->query("DELETE FROM users WHERE id=$id");
}
echo "Calendar subscription content HTTP tests passed.\n";
