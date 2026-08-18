<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1') {
    echo "Follow-up task integration tests skipped (set DNR_INTEGRATION_TEST=1).\n";
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

$inserted = generateEngagementFollowUpChecklist(
    $conn,
    $engagement_id,
    $user_id,
    $user_id
);
expectFollowUpTaskIntegration($inserted === 9, 'the first checklist generation should add all nine tasks.');
expectFollowUpTaskIntegration(
    generateEngagementFollowUpChecklist($conn, $engagement_id, $user_id, $user_id) === 0,
    'repeated checklist generation should not duplicate standard tasks.'
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

$delete_user_stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$delete_user_stmt->bind_param('i', $user_id);
$delete_user_stmt->execute();
$delete_user_stmt->close();

echo "Follow-up task integration tests passed.\n";
