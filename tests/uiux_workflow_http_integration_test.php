<?php

declare(strict_types=1);

// Requires an independently started HTTP server configured for the SAME disposable
// database. No mail worker is needed; these endpoints never send correspondence.
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "UI/UX workflow HTTP integration tests skipped (requires a disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/financial_report_helpers.php';

function expectUiuxHttp(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('UI/UX HTTP integration failed: ' . $message); }
}
function uiuxHidden(string $html, string $name): string
{
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $match);
    expectUiuxHttp(isset($match[1]), 'Missing form field ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

$baseUrl = rtrim((string) (getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
expectUiuxHttp(in_array(parse_url($baseUrl, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true),
    'Use a loopback HTTP server connected to the disposable database');
$cookieFile = tempnam(sys_get_temp_dir(), 'dnr-uiux-cookie-');
$request = static function (string $path, ?array $post = null) use ($baseUrl, $cookieFile): array {
    $curl = curl_init($baseUrl . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $response = curl_exec($curl);
    expectUiuxHttp(is_string($response), 'HTTP request failed: ' . curl_error($curl));
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
    unset($curl);
    return $result;
};
$suffix = bin2hex(random_bytes(6));
$userId = $organizationId = 0;
try {
    $username = 'uiux-http-' . $suffix;
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')");
    $stmt->bind_param('ss', $username, $hash);
    $stmt->execute();
    $userId = (int) $conn->insert_id;
    $organizationName = 'UIUX HTTP ' . $suffix;
    $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
    $stmt->bind_param('s', $organizationName);
    $stmt->execute();
    $organizationId = (int) $conn->insert_id;

    $login = $request('login.php');
    expectUiuxHttp($login['status'] === 200, 'Login form should render');
    $login = $request('login.php', ['csrf_token' => uiuxHidden($login['body'], 'csrf_token'),
        'username' => $username, 'password' => $password]);
    expectUiuxHttp($login['status'] === 302 && str_contains($login['headers'], 'dashboard.php'),
        'Editor login should authenticate against the disposable fixture database');

    $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date,
        event_type, confirmation_status) VALUES ({$organizationId}, 'HTTP receipt fixture', '2026-01-01',
        '2026-01-02', 'conference', 'confirmed')");
    $engagementId = (int) $conn->insert_id;
    $conn->query("INSERT INTO presentations (engagement_id, topic_title, presentation_date, speaker_id)
        VALUES ({$engagementId}, 'Final active presentation', '2026-01-02', (SELECT MIN(id) FROM speakers))");
    $conn->query("INSERT INTO follow_up_tasks (title, status, due_date, subject_type, engagement_id, created_by)
        VALUES ('Canceled closeout prerequisite', 'canceled', '2026-01-02', 'engagement', {$engagementId}, {$userId})");
    $blockerId = (int) $conn->insert_id;
    $path = 'close_engagement.php?id=' . $engagementId;
    $form = $request($path);
    expectUiuxHttp($form['status'] === 200 && str_contains($form['body'], 'Canceled closeout prerequisite'),
        'Closeout form should expose the canceled prerequisite');
    $fields = ['csrf_token' => uiuxHidden($form['body'], 'csrf_token'), 'draft_version' => '',
        'report_version' => '', 'giving_income_received' => '123.45', 'lodging_received' => '',
        'travel_received' => '0', 'notes' => 'Awaiting lodging'];
    $saved = $request($path, $fields + ['action' => 'save_draft']);
    expectUiuxHttp($saved['status'] === 302, 'Partial receipt draft should save despite the task hold');
    $draft = fetchEngagementFinancialDraft($conn, $engagementId);
    expectUiuxHttp($draft !== null && $draft['giving_income_received'] === '123.45'
        && $draft['lodging_received'] === null && $draft['travel_received'] === '0.00',
        'The submitted form should persist blank as NULL and confirmed zero as 0.00');
    expectUiuxHttp(fetchOrganizationFinancialSummary($conn, $organizationId)['closed_event_count'] === 0,
        'Draft receipts should be excluded from finalized organization totals');
    $fields['draft_version'] = $draft['updated_at'];
    $fields['lodging_received'] = '15';
    $held = $request($path, $fields + ['action' => 'finalize', 'confirm_final' => 'yes']);
    expectUiuxHttp($held['status'] === 200 && str_contains($held['body'], 'must be marked completed')
        && fetchEngagementFinancialReport($conn, $engagementId) === null,
        'A forged enabled finalize submission should still respect the canceled-task blocker');
    $conn->query("UPDATE follow_up_tasks SET status='completed', completed_by={$userId},
        completed_at=UTC_TIMESTAMP() WHERE id={$blockerId}");
    $missing = $fields;
    $missing['lodging_received'] = '';
    $rejected = $request($path, $missing + ['action' => 'finalize', 'confirm_final' => 'yes']);
    expectUiuxHttp($rejected['status'] === 200 && fetchEngagementFinancialReport($conn, $engagementId) === null,
        'Finalization should reject a missing amount after prerequisites are satisfied');
    $unconfirmed = $request($path, $fields + ['action' => 'finalize']);
    expectUiuxHttp($unconfirmed['status'] === 200 && str_contains($unconfirmed['body'], 'Confirm that these are the final'),
        'All three entered amounts still require explicit final confirmation');
    $stale = $fields;
    $stale['draft_version'] = '';
    $rejected = $request($path, $stale + ['action' => 'finalize', 'confirm_final' => 'yes']);
    expectUiuxHttp($rejected['status'] === 200 && fetchEngagementFinancialReport($conn, $engagementId) === null,
        'Finalization must reject an obsolete draft version');
    $final = $request($path, $fields + ['action' => 'finalize', 'confirm_final' => 'yes']);
    $report = fetchEngagementFinancialReport($conn, $engagementId);
    $lifecycle = $conn->query("SELECT lifecycle_status FROM engagements WHERE id={$engagementId}")->fetch_assoc();
    expectUiuxHttp($final['status'] === 302 && $report !== null
        && $report['giving_income_received'] === '123.45' && $report['lodging_received'] === '15.00'
        && $report['travel_received'] === '0.00' && fetchEngagementFinancialDraft($conn, $engagementId) === null
        && $lifecycle['lifecycle_status'] === 'completed',
        'Finalization should atomically create the confirmed report, remove the draft, and complete the event');
    $summary = fetchOrganizationFinancialSummary($conn, $organizationId);
    expectUiuxHttp($summary['closed_event_count'] === 1 && $summary['lifetime_giving'] === '123.45',
        'Finalized amounts should enter organization analytics exactly once');

    foreach ([0, 1, 2] as $moveCount) {
        $decision = $moveCount === 0 ? 'resolved' : 'carry_forward';
        $action = 'Preserve next action ' . $moveCount;
        $stmt = $conn->prepare("INSERT INTO booking_inquiries (title, organization_id, preferred_start_date,
            preferred_end_date, next_action, next_action_due_date, owner_user_id, created_by, priority)
            VALUES (?, ?, '2099-01-01', '2099-01-02', ?, '2098-12-31', ?, ?, 'high')");
        $stmt->bind_param('sisii', $action, $organizationId, $action, $userId, $userId);
        $stmt->execute();
        $inquiryId = (int) $conn->insert_id;
        if ($moveCount === 0) {
            $editForm = $request('edit_inquiry.php?id=' . $inquiryId);
            $originalVersion = uiuxHidden($editForm['body'], 'inquiry_version');
            $conn->query("UPDATE booking_inquiries SET request_summary='Newer edit by another user',
                updated_at=DATE_ADD(updated_at, INTERVAL 1 SECOND) WHERE id={$inquiryId}");
            $staleEdit = ['id' => $inquiryId, 'save_inquiry' => '1',
                'csrf_token' => uiuxHidden($editForm['body'], 'csrf_token'), 'inquiry_version' => $originalVersion,
                'title' => 'Stale local draft', 'organization_id' => $organizationId,
                'event_type' => 'conference', 'source' => 'phone', 'priority' => 'high',
                'preferred_start_date' => '2099-01-01', 'preferred_end_date' => '2099-01-02'];
            $conflict = $request('edit_inquiry.php', $staleEdit);
            expectUiuxHttp($conflict['status'] === 200
                && uiuxHidden($conflict['body'], 'inquiry_version') === $originalVersion,
                'A stale inquiry save should preserve its original version after the conflict');
            $staleEdit['inquiry_version'] = uiuxHidden($conflict['body'], 'inquiry_version');
            $conflict = $request('edit_inquiry.php', $staleEdit);
            $current = $conn->query("SELECT title, request_summary FROM booking_inquiries WHERE id={$inquiryId}")->fetch_assoc();
            expectUiuxHttp($conflict['status'] === 200 && $current['title'] === $action
                && $current['request_summary'] === 'Newer edit by another user',
                'Repeated submission after a draft-return conflict must not overwrite another user’s changes');
        }
        $taskIds = [];
        for ($index = 0; $index < 2; $index++) {
            $assignee = $index === 0 ? (string) $userId : 'NULL';
            $conn->query("INSERT INTO follow_up_tasks (title, status, subject_type, inquiry_id, created_by, assigned_to)
                VALUES ('Movable {$suffix} task {$index}', 'open', 'inquiry', {$inquiryId}, {$userId}, {$assignee})");
            $taskIds[] = (int) $conn->insert_id;
        }
        if ($moveCount === 0) {
            foreach (['mine' => 1, 'everyone' => 2, 'unassigned' => 1] as $scope => $expectedCount) {
                $queue = $request('tasks.php?' . http_build_query(['scope' => $scope, 'view' => 'all',
                    'q' => $suffix, 'subject_type' => 'inquiry', 'subject_id' => $inquiryId]));
                preg_match('/class="summary-card is-selected"[^>]*>.*?<strong>([0-9]+)<\/strong>/s', $queue['body'], $count);
                expectUiuxHttp($queue['status'] === 200 && (int) ($count[1] ?? -1) === $expectedCount
                    && str_contains($queue['body'], 'Showing ' . $expectedCount . ' of ' . $expectedCount . ' task'),
                    'Task summary and rows should share the ' . $scope . ' owner, record, and search filters');
            }
        }
        $form = $request('convert_inquiry.php?id=' . $inquiryId);
        expectUiuxHttp($form['status'] === 200, 'Conversion form should render');
        $post = ['id' => $inquiryId, 'convert_inquiry' => '1', 'csrf_token' => uiuxHidden($form['body'], 'csrf_token'),
            'inquiry_version' => uiuxHidden($form['body'], 'inquiry_version'), 'acknowledge_conflicts' => '1',
            'next_action_decision' => $decision, 'next_action_reason' => $decision === 'resolved' ? 'Confirmed during booking' : ''];
        // An unchecked checkbox group is absent entirely in a browser POST.
        if ($moveCount > 0) { $post['task_ids'] = array_slice($taskIds, 0, $moveCount); }
        if ($moveCount === 0) {
            $unresolved = $post;
            $unresolved['next_action_decision'] = '';
            $rejected = $request('convert_inquiry.php', $unresolved);
            expectUiuxHttp($rejected['status'] === 200
                && $conn->query("SELECT stage FROM booking_inquiries WHERE id={$inquiryId}")->fetch_assoc()['stage'] === 'new',
                'Conversion must require explicit reconciliation before clearing the next action');
        }
        $converted = $request('convert_inquiry.php', $post);
        expectUiuxHttp($converted['status'] === 302, 'Conversion should accept none, some, or all task selections');
        $inquiry = $conn->query("SELECT * FROM booking_inquiries WHERE id={$inquiryId}")->fetch_assoc();
        $bookedId = (int) $inquiry['converted_engagement_id'];
        $taskList = implode(',', $taskIds);
        $moved = (int) $conn->query("SELECT COUNT(*) AS total FROM follow_up_tasks WHERE id IN ({$taskList})
            AND engagement_id={$bookedId} AND inquiry_id IS NULL")->fetch_assoc()['total'];
        $remaining = (int) $conn->query("SELECT COUNT(*) AS total FROM follow_up_tasks WHERE id IN ({$taskList})
            AND inquiry_id={$inquiryId} AND engagement_id IS NULL")->fetch_assoc()['total'];
        expectUiuxHttp($bookedId > 0 && $inquiry['stage'] === 'booked' && $moved === $moveCount
            && $remaining === 2 - $moveCount, 'Only explicitly selected tasks should move from the inquiry');
        $history = $conn->query("SELECT reason FROM booking_inquiry_stage_history WHERE booking_inquiry_id={$inquiryId}
            AND to_stage='booked'")->fetch_assoc()['reason'];
        $chron = $conn->query("SELECT entry_text FROM engagement_chron_entries WHERE engagement_id={$bookedId}")->fetch_assoc()['entry_text'];
        expectUiuxHttp(str_contains($history, $action) && str_contains($chron, $action)
            && str_contains($history, '2098-12-31') && str_contains($chron, '2098-12-31')
            && ($decision !== 'resolved' || (str_contains($history, 'Confirmed during booking')
                && str_contains($chron, 'Confirmed during booking'))),
            'Inquiry history and engagement Activity should preserve the action, due date, and resolution reason');
        $carried = $conn->query("SELECT title, due_date, assigned_to, priority FROM follow_up_tasks
            WHERE engagement_id={$bookedId} AND title='Preserve next action {$moveCount}'")->fetch_all(MYSQLI_ASSOC);
        expectUiuxHttp($decision === 'resolved' ? $carried === [] : (count($carried) === 1
            && $carried[0]['due_date'] === '2098-12-31' && (int) $carried[0]['assigned_to'] === $userId
            && $carried[0]['priority'] === 'high'),
            'Carry-forward should create one owned task preserving due date and priority; resolution should create none');
    }
    echo "UI/UX workflow HTTP integration tests passed (draft/finalize, canceled hold, NULL/zero, queue scope, stale inquiry edits, none/some/all conversion, action history).\n";
} finally {
    if ($userId > 0) { $conn->query("DELETE FROM follow_up_tasks WHERE created_by={$userId}"); }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM booking_inquiries WHERE organization_id={$organizationId}");
        $conn->query("DELETE FROM engagements WHERE organization_id={$organizationId}");
        $conn->query("DELETE FROM organizations WHERE id={$organizationId}");
    }
    if ($userId > 0) { $conn->query("DELETE FROM users WHERE id={$userId}"); }
    if (is_string($cookieFile)) { @unlink($cookieFile); }
}
