<?php
declare(strict_types=1);
require_once __DIR__ . '/follow_up_task_helpers.php';
require_once __DIR__ . '/bulk_delete_helpers.php';

function taskBulkOperation(array $input): array
{
    $operation = is_string($input['operation'] ?? null) ? $input['operation'] : '';
    if (!in_array($operation, ['assign','due','complete'], true)) throw new InvalidArgumentException('Choose a bulk update.');
    $value = $operation === 'assign' ? filter_var($input['assignee'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]])
        : ($operation === 'due' ? ($input['due_date'] ?? '') : 'completed');
    if ($operation === 'assign' && $value === false) throw new InvalidArgumentException('Choose an owner or Unassigned.');
    if ($operation === 'due' && (!is_string($value) || ($value !== '' && !validIsoDate($value)))) throw new InvalidArgumentException('Choose a valid due date or leave it blank to clear dates.');
    return ['operation'=>$operation, 'value'=>$value];
}

function applyTaskBulkItem(mysqli $conn, array $snapshot, array $operation, int $actor): void
{
    $conn->begin_transaction();
    try {
        lockFollowUpTaskEngagements($conn, [$snapshot['engagement_id']]);
        lockFollowUpTaskInquiries($conn, [$snapshot['inquiry_id']]);
        $task = fetchFollowUpTask($conn, (int) $snapshot['id'], true);
        if (!$task || !hash_equals($snapshot['updated_at'], $task['updated_at'])) throw new InvalidArgumentException('Changed since preview; reload and review again');
        requireUnarchivedFollowUpTask($task);
        if (!in_array($task['status'], ['open','in_progress','waiting'], true)) throw new InvalidArgumentException('Only active tasks can be updated in bulk');
        $visible = $conn->execute_query('SELECT t.id FROM follow_up_tasks t WHERE t.id=? AND ' . followUpTaskUnarchivedParentSql(), [$task['id']])->fetch_assoc();
        if (!$visible) throw new InvalidArgumentException('The related record is archived');
        if ($operation['operation'] === 'assign') {
            $assignee = $operation['value'] ?: null;
            if ($assignee && !$conn->execute_query("SELECT id FROM users WHERE id=? AND account_status='active' FOR UPDATE", [$assignee])->fetch_assoc()) throw new InvalidArgumentException('The selected owner is no longer active');
            $conn->execute_query('UPDATE follow_up_tasks SET assigned_to=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),DATE_ADD(updated_at,INTERVAL 1 MICROSECOND)) WHERE id=?', [$assignee,$task['id']]);
        } elseif ($operation['operation'] === 'due') {
            $conn->execute_query('UPDATE follow_up_tasks SET due_date=?,due_date_overridden=1,updated_at=GREATEST(CURRENT_TIMESTAMP(6),DATE_ADD(updated_at,INTERVAL 1 MICROSECOND)) WHERE id=?', [$operation['value'] ?: null,$task['id']]);
        } else {
            $conn->execute_query("UPDATE follow_up_tasks SET status='completed',waiting_on=NULL,completed_by=?,completed_at=UTC_TIMESTAMP(),updated_at=GREATEST(CURRENT_TIMESTAMP(6),DATE_ADD(updated_at,INTERVAL 1 MICROSECOND)) WHERE id=?", [$actor,$task['id']]);
        }
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
