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
    $conn->execute_query('INSERT INTO engagement_financial_reports (engagement_id, closed_by) VALUES (?, ?)',
        [$events['closed'], $users['caller']]);
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

    $conn->execute_query("INSERT INTO presentations (engagement_id, topic_title, speaker_id, is_archived, archived_at)
        VALUES (?, 'First', (SELECT MIN(id) FROM speakers), 0, NULL),
               (?, 'Second', (SELECT MIN(id) FROM speakers), 0, NULL),
               (?, 'Archived', (SELECT MIN(id) FROM speakers), 1, UTC_TIMESTAMP())",
        [$events['caller'], $events['caller'], $events['unassigned']]);
    $populatedId = createStandardEventTask($conn, array_replace($input, ['title' => EVENT_PRESENTATION_TASK_TITLE]), $users['creator']);
    $populatedKey = fetchStandardEventTask($conn, $populatedId)['template_key'];
    $readPresentationTask = static fn(int $event) => $conn->execute_query(
        'SELECT status, completed_by, completed_at FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?',
        [$event, $populatedKey])->fetch_assoc();
    generateStandardTaskForOpenEngagements($conn, $populatedId, $users['creator'], false);
    $completed = $readPresentationTask($events['caller']);
    expectStandardTaskGeneration($completed['status'] === 'completed' && $completed['completed_at'] !== null
        && (int) $completed['completed_by'] === $users['creator']
        && $readPresentationTask($events['unassigned'])['status'] === 'open'
        && $readPresentationTask($events['inactive'])['status'] === 'open',
        'bulk generation must satisfy only engagements with an active presentation, including those with several');
    $conn->execute_query('DELETE FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?', [$events['caller'], $populatedKey]);
    generateEngagementFollowUpChecklist($conn, $events['caller'], $users['caller'], $users['creator'], false);
    expectStandardTaskGeneration($readPresentationTask($events['caller'])['status'] === 'completed'
        && $conn->execute_query('SELECT status FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?',
            [$events['caller'], 'standard.confirm_presentations'])->fetch_column() === 'open',
        'a checklist generated after presentations are saved must complete only the population task');

    // Recreate pre-patch task states and apply the actual one-time migration.
    $conn->execute_query("UPDATE follow_up_tasks SET status='waiting', waiting_on='Speaker', completed_at=NULL, completed_by=NULL
        WHERE engagement_id=? AND template_key=?", [$events['caller'], $populatedKey]);
    $conn->execute_query("UPDATE follow_up_tasks SET status='canceled' WHERE engagement_id=? AND template_key=?",
        [$events['inactive'], $populatedKey]);
    $conn->execute_query("INSERT INTO presentations (engagement_id, speaker_id, topic_title)
        VALUES (?, (SELECT MIN(id) FROM speakers), 'Canceled task'), (?, (SELECT MIN(id) FROM speakers), 'Archived task')",
        [$events['inactive'], $events['postponed']]);
    $conn->execute_query("INSERT INTO follow_up_tasks (engagement_id, subject_type, title, template_key, status, is_archived, archived_at)
        VALUES (?, 'engagement', 'Receipts', ?, 'in_progress', 0, NULL),
               (?, 'engagement', 'Archived copy', ?, 'open', 1, UTC_TIMESTAMP()),
               (?, 'engagement', ?, NULL, 'open', 0, NULL)",
        [$events['closed'], EVENT_CLOSEOUT_TASK_KEY, $events['postponed'], $populatedKey, $events['caller'], EVENT_PRESENTATION_TASK_TITLE]);
    $backfill = file_get_contents(__DIR__ . '/../migrations/20261107_complete_performed_event_tasks.sql');
    $conn->query($backfill);
    $closeout = $conn->execute_query('SELECT status, completed_by, completed_at FROM follow_up_tasks
        WHERE engagement_id=? AND template_key=?', [$events['closed'], EVENT_CLOSEOUT_TASK_KEY])->fetch_assoc();
    expectStandardTaskGeneration($closeout['status'] === 'completed' && (int) $closeout['completed_by'] === $users['caller']
        && $closeout['completed_at'] !== null && $readPresentationTask($events['caller'])['status'] === 'completed'
        && $readPresentationTask($events['caller'])['completed_by'] === null
        && $readPresentationTask($events['unassigned'])['status'] === 'open'
        && $readPresentationTask($events['inactive'])['status'] === 'canceled'
        && $readPresentationTask($events['postponed'])['status'] === 'open'
        && $conn->execute_query('SELECT status FROM follow_up_tasks WHERE engagement_id=? AND template_key IS NULL',
            [$events['caller']])->fetch_column() === 'open',
        'historical completion requires the activity and its generated task and preserves cancellations, archives and manual work');
    $conn->query($backfill);
    expectStandardTaskGeneration($conn->affected_rows === 0, 'replaying the backfill must preserve completion history');

    $confirmKey = EVENT_CONFIRM_PRESENTATIONS_TASK_KEY;
    $confirmId = (int) $conn->execute_query('SELECT id FROM standard_event_tasks WHERE template_key = ?', [$confirmKey])->fetch_column();
    $readConfirmation = static fn(int $event) => $conn->execute_query(
        'SELECT status, completed_by, completed_at, waiting_on FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?',
        [$event, $confirmKey])->fetch_assoc();
    $conn->execute_query("UPDATE presentations SET presentation_date='2026-10-10' WHERE engagement_id=?", [$events['caller']]);
    $conn->execute_query("UPDATE presentations SET topic_title=?, presentation_time='00:00:00'
        WHERE engagement_id=? AND topic_title='Second'", [" \t ", $events['caller']]);
    $conn->execute_query("UPDATE presentations SET presentation_date='2026-10-10', presentation_time='09:00:00'
        WHERE engagement_id IN (?, ?, ?)", [$events['unassigned'], $events['inactive'], $events['postponed']]);
    $deleteCallerConfirmation = static fn() => $conn->execute_query('DELETE FROM follow_up_tasks WHERE engagement_id=? AND template_key=?',
        [$events['caller'], $confirmKey]);
    $deleteCallerConfirmation();
    generateStandardTaskForOpenEngagements($conn, $confirmId, $users['creator'], false);
    expectStandardTaskGeneration($readConfirmation($events['caller'])['status'] === 'open'
        && $readConfirmation($events['unassigned'])['status'] === 'open',
        'confirmation requires all three details on the same active presentation');
    $conn->execute_query("UPDATE presentations SET presentation_time='00:00:00' WHERE engagement_id=? AND topic_title='First'",
        [$events['caller']]);
    $deleteCallerConfirmation();
    generateStandardTaskForOpenEngagements($conn, $confirmId, $users['creator'], false);
    expectStandardTaskGeneration($readConfirmation($events['caller'])['status'] === 'completed'
        && (int) $readConfirmation($events['caller'])['completed_by'] === $users['creator'],
        'bulk generation must recognize a saved schedule including midnight');
    $deleteCallerConfirmation();
    generateEngagementFollowUpChecklist($conn, $events['caller'], $users['caller'], $users['creator'], false);
    expectStandardTaskGeneration($readConfirmation($events['caller'])['status'] === 'completed',
        'a later checklist must recognize a previously scheduled presentation');

    $conn->execute_query("UPDATE follow_up_tasks SET status='waiting', waiting_on='Speaker', completed_at=NULL, completed_by=NULL
        WHERE engagement_id=? AND template_key=?", [$events['caller'], $confirmKey]);
    $conn->execute_query("UPDATE follow_up_tasks SET status='canceled', completed_at=NULL, completed_by=NULL
        WHERE engagement_id=? AND template_key=?", [$events['inactive'], $confirmKey]);
    $conn->execute_query("INSERT INTO follow_up_tasks (engagement_id, subject_type, title, template_key, status, is_archived, archived_at)
        VALUES (?, 'engagement', 'Archived confirmation', ?, 'open', 1, UTC_TIMESTAMP()),
               (?, 'engagement', 'Confirm presentation topics, dates, and times', NULL, 'open', 0, NULL)",
        [$events['postponed'], $confirmKey, $events['caller']]);
    $confirmationBackfill = file_get_contents(__DIR__ . '/../migrations/20261107_complete_confirmed_presentation_tasks.sql');
    $conn->query($confirmationBackfill);
    $historical = $readConfirmation($events['caller']);
    expectStandardTaskGeneration($historical['status'] === 'completed' && $historical['completed_at'] !== null
        && $historical['completed_by'] === null && $historical['waiting_on'] === null
        && $readConfirmation($events['unassigned'])['status'] === 'open'
        && $readConfirmation($events['inactive'])['status'] === 'canceled'
        && $readConfirmation($events['postponed'])['status'] === 'open'
        && $conn->execute_query("SELECT status FROM follow_up_tasks WHERE engagement_id=? AND template_key IS NULL
            AND title='Confirm presentation topics, dates, and times'", [$events['caller']])->fetch_column() === 'open',
        'historical scheduling must match the event and generated task and preserve archived, canceled and manual tasks');
    $conn->query($confirmationBackfill);
    expectStandardTaskGeneration($conn->affected_rows === 0, 'replaying schedule reconciliation must preserve completion history');

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
