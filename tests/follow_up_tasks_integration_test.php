<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Follow-up task integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/functions.php';
require_once $source_directory . '/follow_up_task_helpers.php';

function expectFollowUpTaskIntegration($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException("Follow-up task integration test failed: {$message}");
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
requireFollowUpTaskSchema($conn);
$suffix = bin2hex(random_bytes(4));

$username = 'task-test-' . $suffix;
$password_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$user_stmt = $conn->prepare(
    "INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')"
);
$user_stmt->bind_param('ss', $username, $password_hash);
$user_stmt->execute();
$user_id = $conn->insert_id;
$user_stmt->close();

$organization_name = 'Task Test Organization ' . $suffix;
$organization_stmt = $conn->prepare(
    'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
);
$organization_stmt->bind_param('s', $organization_name);
$organization_stmt->execute();
$organization_id = $conn->insert_id;
$organization_stmt->close();

$event_title = 'Task Test Engagement ' . $suffix;
$engagement_stmt = $conn->prepare(
    "INSERT INTO engagements
        (organization_id, event_title, event_start_date, event_end_date,
         event_type, confirmation_status)
     VALUES (?, ?, '2026-09-10', '2026-09-12', 'conference', 'confirmed')"
);
$engagement_stmt->bind_param('is', $organization_id, $event_title);
$engagement_stmt->execute();
$engagement_id = $conn->insert_id;
$engagement_stmt->close();

$custom_template_title = 'Custom automatic task ' . $suffix;
$custom_template_details = 'Copied from the configurable standard-task list.';
$custom_template_id = createStandardEventTask(
    $conn,
    [
        'title' => $custom_template_title,
        'details' => $custom_template_details,
        'priority' => 'normal',
        'due_anchor' => 'event_start',
        'due_offset_days' => '-5',
        'sort_order' => '100',
    ],
    $user_id
);
$template_key_stmt = $conn->prepare(
    'SELECT template_key FROM standard_event_tasks WHERE id = ?'
);
$template_key_stmt->bind_param('i', $custom_template_id);
$template_key_stmt->execute();
$custom_template_key = (string) $template_key_stmt->get_result()->fetch_assoc()['template_key'];
$template_key_stmt->close();

$conn->begin_transaction();
try {
    $inserted = generateEngagementFollowUpChecklist(
        $conn,
        $engagement_id,
        $user_id,
        $user_id,
        false
    );
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}
expectFollowUpTaskIntegration(
    $inserted === 10,
    'automatic generation should add the nine seeded tasks and a newly configured standard task.'
);
$custom_task_stmt = $conn->prepare(
    'SELECT title, details, due_date, assigned_to
     FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?'
);
$custom_task_stmt->bind_param('is', $engagement_id, $custom_template_key);
$custom_task_stmt->execute();
$custom_task = $custom_task_stmt->get_result()->fetch_assoc();
$custom_task_stmt->close();
expectFollowUpTaskIntegration(
    $custom_task
        && $custom_task['title'] === $custom_template_title
        && $custom_task['details'] === $custom_template_details
        && $custom_task['due_date'] === '2026-09-05'
        && (int) $custom_task['assigned_to'] === $user_id,
    'new standard definitions should be copied, dated, and assigned during event creation.'
);
expectFollowUpTaskIntegration(
    generateEngagementFollowUpChecklist($conn, $engagement_id, $user_id, $user_id) === 0,
    'repeated checklist generation should not duplicate standard tasks.'
);

rescheduleGeneratedEngagementTasks($conn, $engagement_id, '2026-09-20', '2026-09-22');
$rescheduled_stmt = $conn->prepare(
    'SELECT due_date, due_date_overridden
     FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?'
);
$rescheduled_stmt->bind_param('is', $engagement_id, $custom_template_key);
$rescheduled_stmt->execute();
$rescheduled = $rescheduled_stmt->get_result()->fetch_assoc();
$rescheduled_stmt->close();
expectFollowUpTaskIntegration(
    $rescheduled
        && $rescheduled['due_date'] === '2026-09-15'
        && (int) $rescheduled['due_date_overridden'] === 0,
    'generated open task dates should follow a changed engagement date range.'
);

$override_stmt = $conn->prepare(
    'UPDATE follow_up_tasks
     SET due_date = ?, due_date_overridden = 1
     WHERE engagement_id = ? AND template_key = ?'
);
$overridden_due_date = '2026-12-31';
$override_stmt->bind_param('sis', $overridden_due_date, $engagement_id, $custom_template_key);
$override_stmt->execute();
$override_stmt->close();
rescheduleGeneratedEngagementTasks($conn, $engagement_id, '2026-10-01', '2026-10-03');

$preserved_stmt = $conn->prepare(
    'SELECT due_date FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?'
);
$preserved_stmt->bind_param('is', $engagement_id, $custom_template_key);
$preserved_stmt->execute();
$preserved_due_date = $preserved_stmt->get_result()->fetch_assoc()['due_date'] ?? null;
$preserved_stmt->close();
expectFollowUpTaskIntegration(
    $preserved_due_date === $overridden_due_date,
    'a manually overridden generated due date should survive later engagement rescheduling.'
);

$task_stmt = $conn->prepare(
    'SELECT id, updated_at FROM follow_up_tasks
     WHERE engagement_id = ? ORDER BY id LIMIT 1'
);
$task_stmt->bind_param('i', $engagement_id);
$task_stmt->execute();
$task = $task_stmt->get_result()->fetch_assoc();
$task_stmt->close();
setFollowUpTaskStatus($conn, $task['id'], 'completed', $task['updated_at'], $user_id);

$completed_stmt = $conn->prepare(
    'SELECT status, completed_by, completed_at FROM follow_up_tasks WHERE id = ?'
);
$completed_stmt->bind_param('i', $task['id']);
$completed_stmt->execute();
$completed = $completed_stmt->get_result()->fetch_assoc();
$completed_stmt->close();
expectFollowUpTaskIntegration(
    $completed['status'] === 'completed'
        && (int) $completed['completed_by'] === $user_id
        && $completed['completed_at'] !== null,
    'completion should record status, actor, and timestamp together.'
);

$invalid_subject_rejected = false;
try {
    $invalid_stmt = $conn->prepare(
        "INSERT INTO follow_up_tasks
            (title, subject_type, engagement_id, organization_id, created_by)
         VALUES ('Invalid task subject', 'engagement', ?, ?, ?)"
    );
    $invalid_stmt->bind_param('iii', $engagement_id, $organization_id, $user_id);
    $invalid_stmt->execute();
} catch (mysqli_sql_exception $exception) {
    $invalid_subject_rejected = true;
}
expectFollowUpTaskIntegration(
    $invalid_subject_rejected,
    'the database must reject tasks linked to more than one primary subject.'
);

$delete_engagement_stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
$delete_engagement_stmt->bind_param('i', $engagement_id);
$delete_engagement_stmt->execute();
$delete_engagement_stmt->close();
$remaining_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM follow_up_tasks WHERE engagement_id = ?');
$remaining_stmt->bind_param('i', $engagement_id);
$remaining_stmt->execute();
$remaining = (int) $remaining_stmt->get_result()->fetch_assoc()['total'];
$remaining_stmt->close();
expectFollowUpTaskIntegration(
    $remaining === 0,
    'tasks should be removed with their deleted engagement through referential integrity.'
);

$delete_org_stmt = $conn->prepare('DELETE FROM organizations WHERE id = ?');
$delete_org_stmt->bind_param('i', $organization_id);
$delete_org_stmt->execute();
$delete_org_stmt->close();

$delete_template_stmt = $conn->prepare('DELETE FROM standard_event_tasks WHERE id = ?');
$delete_template_stmt->bind_param('i', $custom_template_id);
$delete_template_stmt->execute();
$delete_template_stmt->close();

$delete_user_stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$delete_user_stmt->bind_param('i', $user_id);
$delete_user_stmt->execute();
$delete_user_stmt->close();

echo "Follow-up task integration tests passed.\n";
