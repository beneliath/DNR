<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Standard task generation integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/follow_up_task_helpers.php';
function expectStandardTaskGeneration(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Standard task generation test failed: ' . $message);
}
$conn->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $users = [];
    foreach (['creator', 'caller', 'inactive'] as $kind) {
        $name = 'standard-bulk-' . $kind . '-' . $suffix;
        $status = $kind === 'inactive' ? 'inactive' : 'active';
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, account_status) VALUES (?, ?, 'editor', ?)");
        $stmt->bind_param('sss', $name, $hash, $status);
        $stmt->execute();
        $users[$kind] = (int) $conn->insert_id;
        $stmt->close();
    }
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Standard bulk {$suffix}')");
    $organization = (int) $conn->insert_id;
    $conn->query("INSERT INTO organizations (organization_name, is_deleted) VALUES ('Archived bulk {$suffix}', 1)");
    $archivedOrganization = (int) $conn->insert_id;
    $events = [];
    foreach (['caller', 'unassigned', 'inactive', 'postponed', 'canceled', 'completed', 'archived', 'closed', 'archived_org'] as $kind) {
        $lifecycle = in_array($kind, ['postponed', 'canceled', 'completed'], true) ? $kind : 'active';
        $deleted = $kind === 'archived' ? 1 : 0;
        $cancellation = $kind === 'canceled' ? 'Fixture cancellation' : null;
        $owner = $users[$kind] ?? null;
        $org = $kind === 'archived_org' ? $archivedOrganization : $organization;
        $stmt = $conn->prepare("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status, lifecycle_status, is_deleted, caller_user_id, cancellation_reason) VALUES (?, ?, '2026-10-10', '2026-10-12', 'conference', 'confirmed', ?, ?, ?, ?)");
        $stmt->bind_param('issiis', $org, $kind, $lifecycle, $deleted, $owner, $cancellation);
        $stmt->execute();
        $events[$kind] = (int) $conn->insert_id;
        $stmt->close();
    }
    $conn->query("INSERT INTO engagement_financial_reports (engagement_id) VALUES ({$events['closed']})");
    $conn->query("INSERT INTO engagement_financial_drafts (engagement_id) VALUES ({$events['unassigned']})");
    $input = ['title' => 'Check notes ' . $suffix, 'details' => 'Inspect the speaker notes', 'priority' => 'high',
        'due_anchor' => 'event_start', 'due_offset_days' => '-3', 'sort_order' => '62'];
    $id = createStandardEventTask($conn, $input, $users['creator']);
    $definition = fetchStandardEventTask($conn, $id);
    $key = $definition['template_key'];
    $before = (int) $conn->query('SELECT COUNT(*) AS total FROM follow_up_tasks')->fetch_assoc()['total'];
    $count = generateStandardTaskForOpenEngagements($conn, $id, $users['creator'], false);
    $rows = $conn->query("SELECT * FROM follow_up_tasks WHERE template_key = '{$key}' AND engagement_id IN (" . implode(',', $events) . ') ORDER BY engagement_id')->fetch_all(MYSQLI_ASSOC);
    expectStandardTaskGeneration(array_map('intval', array_column($rows, 'engagement_id')) === [$events['caller'], $events['unassigned'], $events['inactive']], 'only unarchived Active engagements with open financial closeout and active organizations qualify');
    expectStandardTaskGeneration(array_map('intval', array_column($rows, 'assigned_to')) === [$users['caller'], $users['creator'], $users['creator']], 'active Callers own copies, with creator fallback for absent or inactive Callers');
    foreach ($rows as $row) {
        expectStandardTaskGeneration($row['title'] === $input['title'] && $row['details'] === $input['details']
            && $row['priority'] === 'high' && $row['due_date'] === '2026-10-07' && $row['status'] === 'open'
            && (int) $row['due_date_overridden'] === 0, 'copies retain content, priority, scheduling, and normal generated-task behavior');
    }
    $after = (int) $conn->query('SELECT COUNT(*) AS total FROM follow_up_tasks')->fetch_assoc()['total'];
    expectStandardTaskGeneration($after - $before === $count, 'the batch should add only the selected definition, not other missing checklist tasks');
    $conn->query("UPDATE follow_up_tasks SET status = 'completed', completed_at = UTC_TIMESTAMP(), title = 'Keep my edit', due_date = '2026-10-01', due_date_overridden = 1 WHERE id = {$rows[0]['id']}");
    $conn->query("UPDATE follow_up_tasks SET status = 'canceled' WHERE id = {$rows[1]['id']}");
    expectStandardTaskGeneration(generateStandardTaskForOpenEngagements($conn, $id, $users['creator'], false) === 0, 'repeating the action must not duplicate even completed or canceled copies');
    $preserved = $conn->query("SELECT * FROM follow_up_tasks WHERE id = {$rows[0]['id']}")->fetch_assoc();
    expectStandardTaskGeneration($preserved['title'] === 'Keep my edit' && $preserved['status'] === 'completed' && $preserved['due_date'] === '2026-10-01', 'existing edits, statuses, and due-date overrides remain intact');
    $endId = createStandardEventTask($conn, array_replace($input, ['due_anchor' => 'event_end', 'due_offset_days' => '7']), $users['creator']);
    generateStandardTaskForOpenEngagements($conn, $endId, $users['creator'], false);
    $endKey = fetchStandardEventTask($conn, $endId)['template_key'];
    $due = $conn->query("SELECT due_date FROM follow_up_tasks WHERE template_key = '{$endKey}' AND engagement_id = {$events['caller']}")->fetch_assoc()['due_date'];
    expectStandardTaskGeneration($due === '2026-10-19', 'event-end rules should use each engagement end date');
    $conn->query("UPDATE standard_event_tasks SET is_archived = 1, archived_at = UTC_TIMESTAMP() WHERE id = {$id}");
    try {
        generateStandardTaskForOpenEngagements($conn, $id, $users['creator'], false);
        throw new RuntimeException('Archived definitions must be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
} finally {
    // Includes all generated copies, even those in other disposable fixture engagements.
    $conn->rollback();
}
echo "Standard task generation integration tests passed.\n";
