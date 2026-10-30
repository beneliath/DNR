<?php

declare(strict_types=1);

/** Archive independently of status, rejecting stale forms and repeated actions. */
function setFollowUpTaskArchived(
    mysqli $conn,
    int $taskId,
    bool $archived,
    string $expectedVersion,
    int $actorUserId
): void {
    if ($taskId < 1 || $actorUserId < 1 || $expectedVersion === '') {
        throw new InvalidArgumentException('Select a valid task action and reload the work queue.');
    }
    $archiveValue = $archived ? 1 : 0;
    $archivedBy = $archived ? $actorUserId : null;
    $archivedAt = $archived ? gmdate('Y-m-d H:i:s') : null;
    $stmt = $conn->prepare(
        'UPDATE follow_up_tasks SET is_archived = ?, archived_by = ?, archived_at = ?
         WHERE id = ? AND updated_at = ? AND is_archived <> ?'
    );
    $stmt->bind_param('iisisi', $archiveValue, $archivedBy, $archivedAt, $taskId, $expectedVersion, $archiveValue);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();
    if ($changed !== 1) {
        throw new InvalidArgumentException('That task changed in another session or is no longer available. Reload the work queue before updating it.');
    }
}

function requireUnarchivedFollowUpTask(array $task): void
{
    if (!empty($task['is_archived'])) {
        throw new InvalidArgumentException('Restore this archived task before updating it.');
    }
}

function setFollowUpTaskStatus(
    mysqli $conn,
    $task_id,
    $status,
    $expected_version,
    $actor_user_id,
    ?array &$completionUndo = null
) {
    $task_id = (int) $task_id;
    $actor_user_id = (int) $actor_user_id;
    $status = (string) $status;
    if ($task_id < 1 || $actor_user_id < 1
        || !array_key_exists($status, followUpTaskStatuses())
    ) {
        throw new InvalidArgumentException('Select a valid task action.');
    }
    if ($status === 'waiting') {
        throw new InvalidArgumentException('Edit the task to describe what it is waiting on.');
    }

    $conn->begin_transaction();
    try {
        $task_hint = fetchFollowUpTask($conn, $task_id);
        if (!$task_hint) {
            throw new InvalidArgumentException('That task is no longer available.');
        }
        lockFollowUpTaskEngagements($conn, [$task_hint['engagement_id'] ?? null]);
        $task = fetchFollowUpTask($conn, $task_id, true);
        if (!$task) {
            throw new InvalidArgumentException('That task is no longer available.');
        }
        if ((int) ($task['engagement_id'] ?? 0)
            !== (int) ($task_hint['engagement_id'] ?? 0)
        ) {
            throw new InvalidArgumentException(
                'That task changed in another session. Reload the work queue before updating it.'
            );
        }
        if ((string) $expected_version === ''
            || !hash_equals((string) $task['updated_at'], (string) $expected_version)
        ) {
            throw new InvalidArgumentException('That task changed in another session. Reload the work queue before updating it.');
        }

        requireUnarchivedFollowUpTask($task);
        $completed_by = $status === 'completed' ? $actor_user_id : null;
        $completed_at = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        if ($status === 'completed' && $task['status'] === 'completed') {
            $completed_by = $task['completed_by'];
            $completed_at = $task['completed_at'];
        }
        $stmt = $conn->prepare(
            'UPDATE follow_up_tasks
             SET status = ?, waiting_on = NULL, completed_by = ?, completed_at = ?,
                 updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(updated_at, INTERVAL 1 MICROSECOND))
             WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the task status update.');
        }
        $stmt->bind_param('sisi', $status, $completed_by, $completed_at, $task_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update the task status.');
        }
        $stmt->close();
        $updated = fetchFollowUpTask($conn, $task_id, true);
        $receipt = $status === 'completed' && $task['status'] !== 'completed' ? [
            'task_id' => $task_id, 'title' => $task['title'],
            'status' => $task['status'], 'waiting_on' => $task['waiting_on'],
            'version' => $updated['updated_at'],
            'fingerprint' => hash('sha256', json_encode($updated, JSON_THROW_ON_ERROR)),
            'actor' => $actor_user_id, 'expires' => time() + 600,
        ] : null;
        $conn->commit();
        $completionUndo = $receipt;
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** Restore only the exact completion this session made; never overwrite a newer edit. */
function undoFollowUpTaskCompletion(mysqli $conn, array $receipt, int $actorUserId): void
{
    if (($receipt['expires'] ?? 0) < time() || ($receipt['actor'] ?? 0) !== $actorUserId) {
        throw new InvalidArgumentException('Undo expired. You can reopen the task from Tasks.');
    }
    $conn->begin_transaction();
    try {
        $hint = fetchFollowUpTask($conn, $receipt['task_id']);
        if (!$hint) throw new InvalidArgumentException('That task is no longer available.');
        lockFollowUpTaskEngagements($conn, [$hint['engagement_id'] ?? null]);
        $task = fetchFollowUpTask($conn, $receipt['task_id'], true);
        if (!$task || $task['status'] !== 'completed'
            || !hash_equals($receipt['fingerprint'], hash('sha256', json_encode($task, JSON_THROW_ON_ERROR)))) {
            throw new InvalidArgumentException('This task changed after completion. Open it to review the latest details.');
        }
        requireUnarchivedFollowUpTask($task);
        $stmt = $conn->prepare('UPDATE follow_up_tasks SET status = ?, waiting_on = ?,
            completed_by = NULL, completed_at = NULL,
            updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(updated_at, INTERVAL 1 MICROSECOND)) WHERE id = ?');
        $stmt->bind_param('ssi', $receipt['status'], $receipt['waiting_on'], $receipt['task_id']);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function assignFollowUpTaskToUser(
    mysqli $conn,
    $task_id,
    $assignee_id,
    $expected_version
) {
    $task_id = (int) $task_id;
    $assignee_id = (int) $assignee_id;
    if ($task_id < 1 || $assignee_id < 1) {
        throw new InvalidArgumentException('Select a valid task assignment.');
    }

    $conn->begin_transaction();
    try {
        $user_stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE id = ? AND account_status = 'active' FOR UPDATE"
        );
        if (!$user_stmt) {
            throw new RuntimeException('Unable to verify the task assignee.');
        }
        $user_stmt->bind_param('i', $assignee_id);
        $user_stmt->execute();
        $user_exists = $user_stmt->get_result()->num_rows === 1;
        $user_stmt->close();
        if (!$user_exists) {
            throw new InvalidArgumentException('That task assignee is no longer available.');
        }

        $task = fetchFollowUpTask($conn, $task_id, true);
        if (!$task) {
            throw new InvalidArgumentException('That task is no longer available.');
        }
        if ((string) $expected_version === ''
            || !hash_equals((string) $task['updated_at'], (string) $expected_version)
        ) {
            throw new InvalidArgumentException('That task changed in another session. Reload the work queue before assigning it.');
        }
        requireUnarchivedFollowUpTask($task);
        $stmt = $conn->prepare('UPDATE follow_up_tasks SET assigned_to = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the task assignment.');
        }
        $stmt->bind_param('ii', $assignee_id, $task_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to assign the task.');
        }
        $stmt->close();
        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
