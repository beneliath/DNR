<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Parent archive task tests skipped (requires disposable database and HTTP server).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
if (!getenv('DNR_PUBLIC_BASE_URL')) putenv('DNR_PUBLIC_BASE_URL=https://example.test');
require_once $source . '/bootstrap.php';
require_once $source . '/booking_inquiry_helpers.php';
require_once $source . '/notification_helpers.php';
require_once $source . '/calendar_helpers.php';
require_once $source . '/mattermost_integration_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';

function expectParentWork(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function parentWorkHttp(string $path, string $session, ?array $post = null): array
{
    $curl = curl_init(rtrim((string) getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $session, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'body' => substr($response, curl_getinfo($curl, CURLINFO_HEADER_SIZE))];
}

function parentWorkQueue(string $session, array $parent, array $query, array $expected, array $counts): void
{
    $page = parentWorkHttp('tasks.php?' . http_build_query(array_merge(
        ['scope' => 'everyone', 'q' => $parent['token']], $query
    )), $session);
    expectParentWork($page['status'] === 200, 'Work Queue loads for ' . json_encode($query));
    foreach ($parent['tasks'] as $key => $task) {
        expectParentWork(str_contains($page['body'], $task['title']) === in_array($key, $expected, true),
            'Queue visibility matches parent, task status, ownership, and filters: ' . $key . ' ' . json_encode($query));
    }
    $dom = new DOMDocument(); @$dom->loadHTML($page['body']); $xpath = new DOMXPath($dom);
    foreach ($counts as $view => $count) {
        $node = $xpath->query('//a[contains(@class,"summary-card") and contains(@href,"view=' . $view . '")]//strong')->item(0);
        expectParentWork($node !== null && (int) $node->textContent === $count, 'Summary matches visible work: ' . $view);
    }
    expectParentWork(str_contains($page['body'], 'Showing ' . count($expected) . ' of ' . count($expected) . ' task'),
        'Pagination totals match the visible tasks');
}

$suffix = bin2hex(random_bytes(5));
$owner = $org = $general = 0; $session = ''; $inquiryIds = [];
try {
    $conn->execute_query("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')",
        ['parent-work-' . $suffix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $owner = (int) $conn->insert_id;
    $user = $conn->query("SELECT * FROM users WHERE id = {$owner}")->fetch_assoc();
    session_start();
    $_SESSION = ['user_id' => $owner, 'username' => $user['username'], 'role' => 'editor',
        'auth_version' => (int) $user['auth_version'], 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
    completeIntegrationTestMfaSession();
    $session = session_id(); $csrf = $_SESSION['_csrf_token']; session_write_close(); session_id('');
    $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ['Parent work ' . $suffix]);
    $org = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type)
        VALUES (?, ?, '2027-01-10', '2027-01-11', 'conference')", [$org, 'Parent event ' . $suffix]);
    $event = (int) $conn->insert_id;
    $parents = [['type' => 'engagement', 'id' => $event]];
    foreach (['declined', 'booked'] as $stage) {
        $conn->execute_query('INSERT INTO booking_inquiries (title, stage, organization_id, created_by, converted_at, converted_engagement_id, decline_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?)', ['Parent inquiry ' . $suffix . ' ' . $stage, $stage, $org, $owner,
                $stage === 'booked' ? gmdate('Y-m-d H:i:s') : null, $stage === 'booked' ? $event : null,
                $stage === 'declined' ? 'Dates unavailable' : null]);
        $id = (int) $conn->insert_id; $inquiryIds[] = $id;
        $parents[] = ['type' => 'inquiry', 'id' => $id];
    }
    $today = applicationBusinessDate(); $overdue = applicationBusinessDateOffset(-1); $upcoming = applicationBusinessDateOffset(2);
    $baselineAll = fetchDashboardTaskSummary($conn, $owner, $today)['all'];
    $conn->execute_query("INSERT INTO follow_up_tasks (title, due_date, assigned_to, created_by)
        VALUES (?, ?, ?, ?)", ['Unrelated general ' . $suffix, $today, $owner, $owner]);
    $general = (int) $conn->insert_id;
    foreach ($parents as $index => &$parent) {
        $parent['token'] = 'parentwork' . $suffix . $index; $parent['tasks'] = [];
        foreach ([
            'overdue' => ['open', $overdue, $owner, 0],
            'today' => ['in_progress', $today, $owner, 0],
            'waiting' => ['waiting', $upcoming, null, 0],
            'completed' => ['completed', $overdue, $owner, 0],
            'canceled' => ['canceled', $overdue, $owner, 0],
            'archived' => ['open', $overdue, $owner, 1],
            'undated' => ['open', null, $owner, 0],
        ] as $key => [$status, $due, $assignee, $archived]) {
            $title = $parent['token'] . ' ' . $key;
            $conn->execute_query('INSERT INTO follow_up_tasks (title, details, status, waiting_on, due_date,
                subject_type, engagement_id, inquiry_id, assigned_to, created_by, completed_at, completed_by,
                is_archived, archived_at, archived_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, 'Retain these task notes', $status, $status === 'waiting' ? 'Host reply' : null,
                    $due, $parent['type'], $parent['type'] === 'engagement' ? $parent['id'] : null,
                    $parent['type'] === 'inquiry' ? $parent['id'] : null, $assignee, $owner,
                    $status === 'completed' ? gmdate('Y-m-d H:i:s') : null, $status === 'completed' ? $owner : null,
                    $archived, $archived ? gmdate('Y-m-d H:i:s') : null, $archived ? $owner : null]);
            $taskId = (int) $conn->insert_id;
            $parent['tasks'][$key] = $conn->query("SELECT * FROM follow_up_tasks WHERE id = {$taskId}")->fetch_assoc();
        }
    }
    unset($parent);
    foreach ($parents as $parent) {
        foreach ([false, true, false] as $archived) {
            $action = $archived ? 'archive' : 'restore';
            if ($parent['type'] === 'engagement') {
                $response = parentWorkHttp('engagements.php', $session,
                    ['action' => $action, 'engagement_id' => $parent['id'], 'csrf_token' => $csrf]);
                $record = $conn->query("SELECT is_deleted FROM engagements WHERE id = {$parent['id']}")->fetch_assoc();
                expectParentWork((bool) $record['is_deleted'] === $archived, 'Engagement archive state changes');
            } else {
                $inquiry = fetchBookingInquiry($conn, $parent['id']);
                // The first pass already starts with the parent restored.
                if ($archived || !empty($inquiry['archived_at'])) {
                    $response = parentWorkHttp('view_inquiry.php?id=' . $parent['id'], $session,
                        ['action' => $action, 'id' => $parent['id'], 'inquiry_version' => $inquiry['updated_at'], 'csrf_token' => $csrf]);
                } else { $response = ['status' => 302]; }
                expectParentWork(!empty(fetchBookingInquiry($conn, $parent['id'])['archived_at']) === $archived,
                    'Booked and declined inquiry archive state changes');
            }
            expectParentWork($response['status'] === 302, 'Real parent archive/restore endpoint succeeds');
            $active = $archived ? [] : ['overdue', 'today', 'waiting', 'undated'];
            $counts = ['all' => count($active), 'overdue' => (int) !$archived, 'today' => (int) !$archived,
                'upcoming' => (int) !$archived, 'waiting' => (int) !$archived, 'completed' => 2, 'archived' => 1];
            parentWorkQueue($session, $parent, [], $active, $counts);
            foreach (['overdue' => 'overdue', 'today' => 'today', 'upcoming' => 'waiting', 'waiting' => 'waiting'] as $view => $key) {
                parentWorkQueue($session, $parent, ['view' => $view], $archived ? [] : [$key], $counts);
            }
            parentWorkQueue($session, $parent, ['scope' => 'mine'], $archived ? [] : ['overdue', 'today', 'undated'],
                ['all' => $archived ? 0 : 3]);
            parentWorkQueue($session, $parent, ['scope' => 'unassigned'], $archived ? [] : ['waiting'],
                ['all' => $archived ? 0 : 1]);
            parentWorkQueue($session, $parent, ['subject_type' => $parent['type'], 'subject_id' => $parent['id']], $active, $counts);
            parentWorkQueue($session, $parent, ['view' => 'completed'], ['completed', 'canceled'], $counts);
            parentWorkQueue($session, $parent, ['view' => 'archived'], ['archived'], $counts);
            $summary = fetchDashboardTaskSummary($conn, $owner, $today);
            expectParentWork($summary['active'] === ($archived ? 7 : 10), 'Dashboard active total follows parent visibility');
            expectParentWork($summary['all'] === $baselineAll + ($archived ? 9 : 13), 'Everyone total includes unassigned work consistently');
            expectParentWork(fetchTaskReminderCounts($conn, $owner, 'editor', $today)['active'] === $summary['active'],
                'Sidebar and reminder totals match Dashboard');
            $dashboard = parentWorkHttp('dashboard.php', $session);
            expectParentWork($dashboard['status'] === 200, 'Dashboard renders with the same parent filter');
            if ($archived) {
                foreach ($parent['tasks'] as $task) {
                    expectParentWork(!str_contains($dashboard['body'], $task['title']), 'Archived parent tasks leave Dashboard action items');
                }
            }
            $mattermostSummary = mattermostTaskSummaryForUser($conn, $owner);
            expectParentWork($mattermostSummary['overdue'] === ($archived ? 2 : 3),
                'Mattermost reminder totals follow parent visibility');
            $digest = fetchDailyTaskDigestData($conn, $owner, 'editor', $today);
            $digestRows = array_merge(...array_map(static fn($key) => $digest[$key], ['overdue', 'today', 'upcoming', 'waiting', 'undated']));
            foreach ([fetchDashboardMyTasks($conn, $owner, 50), $digestRows,
                mattermostTasksForUser($conn, ['id' => $owner, 'role' => 'editor'], 25)] as $rows) {
                $ids = array_map('intval', array_column($rows, 'id'));
                foreach (['overdue', 'today', 'undated'] as $key) {
                    expectParentWork(in_array((int) $parent['tasks'][$key]['id'], $ids, true) === !$archived,
                        'Active work and digests follow parent archive state');
                }
                expectParentWork(in_array($general, $ids, true), 'Unrelated general work stays visible');
            }
            $calendarIds = array_map('intval', array_column(fetchCalendarViewerTasks($conn, $overdue, $upcoming, $owner), 'id'));
            expectParentWork(in_array((int) $parent['tasks']['overdue']['id'], $calendarIds, true) === !$archived,
                'Calendar work follows parent archive state');
            foreach ($parent['tasks'] as $task) {
                expectParentWork($task === $conn->query("SELECT * FROM follow_up_tasks WHERE id = {$task['id']}")->fetch_assoc(),
                    'Parent archiving and restoration preserve every task field, including individual archive and completion state');
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    throw $exception;
} finally {
    foreach ($inquiryIds as $id) $conn->query("DELETE FROM booking_inquiries WHERE id = {$id}");
    if ($org > 0) {
        $conn->query("DELETE FROM engagements WHERE organization_id = {$org}");
        $conn->query("DELETE FROM organizations WHERE id = {$org}");
    }
    if ($general > 0) $conn->query("DELETE FROM follow_up_tasks WHERE id = {$general}");
    if ($owner > 0) $conn->query("DELETE FROM users WHERE id = {$owner}");
    if ($session !== '') { session_id($session); session_start(); $_SESSION = []; session_destroy(); }
}
echo "Parent archive task HTTP integration tests passed.\n";
