<?php

declare(strict_types=1);
require_once __DIR__ . '/follow_up_task_helpers.php';

function workflowTasksAvailable(mysqli $conn): bool
{
    static $available = null;
    return $available ??= $conn->query("SHOW COLUMNS FROM engagement_email_messages LIKE 'follow_up_task_id'")->num_rows > 0;
}

/** Called inside the inquiry save transaction. Legacy fields are a task summary, not separate work. */
function saveInquiryNextActionTask(mysqli $conn, int $id, array $data, ?int $previousTaskId, int $actor): void
{
    if (!workflowTasksAvailable($conn)) return;
    $selected = (int) ($data['next_action_task_choice'] ?? 0);
    if ($selected > 0) {
        $task = fetchFollowUpTask($conn, $selected, true);
        if (!$task || $task['subject_type'] !== 'inquiry' || (int) $task['inquiry_id'] !== $id
            || $task['is_archived'] || !in_array($task['status'], ['open','in_progress','waiting'], true)) {
            throw new InvalidArgumentException('Choose an active task belonging to this inquiry.');
        }
    } elseif (trim((string) ($data['next_action'] ?? '')) === '') {
        $conn->execute_query('UPDATE booking_inquiries SET next_action_task_id=NULL, next_action=NULL, next_action_due_date=NULL WHERE id=?', [$id]);
        return;
    } else {
        $task = $previousTaskId ? fetchFollowUpTask($conn, $previousTaskId, true) : null;
        if ($task && ($task['subject_type'] !== 'inquiry' || (int) $task['inquiry_id'] !== $id || $task['is_archived']
            || !in_array($task['status'], ['open','in_progress','waiting'], true))) $task = null;
        if ($task) {
            $conn->execute_query('UPDATE follow_up_tasks SET title=?, due_date=?, assigned_to=?, updated_at=GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(updated_at, INTERVAL 1 MICROSECOND)) WHERE id=?',
                [$data['next_action'], $data['next_action_due_date'], $data['owner_user_id'], $task['id']]);
            $task = fetchFollowUpTask($conn, (int) $task['id'], true);
        } else {
            $taskId = insertFollowUpTask($conn, [
                'title'=>$data['next_action'], 'details'=>null, 'status'=>'open', 'priority'=>$data['priority'],
                'due_date'=>$data['next_action_due_date'], 'waiting_on'=>null, 'subject_type'=>'inquiry',
                'inquiry_id'=>$id, 'engagement_id'=>null, 'organization_id'=>null, 'contact_id'=>null,
                'assigned_to'=>$data['owner_user_id'],
            ], $actor);
            $task = fetchFollowUpTask($conn, $taskId, true);
        }
    }
    $conn->execute_query('UPDATE booking_inquiries SET next_action_task_id=?, next_action=?, next_action_due_date=?, owner_user_id=? WHERE id=?',
        [$task['id'], $task['title'], $task['due_date'], $task['assigned_to'], $id]);
}

/** Create follow-up work in the same transaction as the outbound message. */
function createEmailFollowUpTask(mysqli $conn, int $messageId, string $type, int $parentId, int $actor, ?string $due): void
{
    if ($due === null) return;
    if (!workflowTasksAvailable($conn)) throw new InvalidArgumentException('Reply tracking is being updated. Please try again shortly.');
    if (!validIsoDate($due)) throw new InvalidArgumentException('Choose a valid follow-up date.');
    $taskId = insertFollowUpTask($conn, [
        'title'=>'Review reply to email #' . $messageId, 'details'=>'Correspondence: outbound_mail.php?id=' . $messageId,
        'status'=>'waiting', 'priority'=>'normal', 'due_date'=>$due, 'waiting_on'=>'Email reply',
        'subject_type'=>$type, 'engagement_id'=>$type==='engagement'?$parentId:null,
        'inquiry_id'=>$type==='inquiry'?$parentId:null, 'organization_id'=>null, 'contact_id'=>null, 'assigned_to'=>$actor,
    ], $actor);
    $conn->execute_query('UPDATE engagement_email_messages SET follow_up_task_id=? WHERE id=?', [$taskId,$messageId]);
}

/** Thread headers are evidence of a possible reply, never authorization to complete a task. */
function emailFollowUpState(mysqli $conn, int $messageId): ?array
{
    if (!workflowTasksAvailable($conn)) return null;
    $message = $conn->execute_query('SELECT m.*,t.status AS task_status,t.due_date,t.is_archived FROM engagement_email_messages m JOIN follow_up_tasks t ON t.id=m.follow_up_task_id WHERE m.id=?', [$messageId])->fetch_assoc();
    if (!$message) return null;
    $message['reply_id'] = null;
    $deliveries = $conn->execute_query('SELECT smtp_message_id,recipient_email FROM engagement_email_deliveries WHERE message_id=? AND smtp_message_id IS NOT NULL', [$messageId])->fetch_all(MYSQLI_ASSOC);
    foreach ($deliveries as $delivery) {
        $candidates = $conn->execute_query("SELECT i.id,i.raw_headers FROM inbound_email_messages i
            WHERE i.status='processed' AND i.received_at>=? AND LOWER(i.sender_address)=LOWER(?)
            AND (EXISTS (SELECT 1 FROM engagement_chron_entries c WHERE c.inbound_email_message_id=i.id AND c.engagement_id=?)
              OR EXISTS (SELECT 1 FROM booking_inquiry_chron_entries c WHERE c.inbound_email_message_id=i.id AND c.booking_inquiry_id=?))
            ORDER BY i.received_at DESC LIMIT 100", [$message['created_at'],$delivery['recipient_email'],$message['engagement_id'],$message['booking_inquiry_id']])->fetch_all(MYSQLI_ASSOC);
        foreach ($candidates as $candidate) {
            $headers = preg_replace('/\r?\n[ \t]+/', ' ', $candidate['raw_headers']);
            preg_match_all('/^(?:In-Reply-To|References):([^\r\n]*)/mi', $headers, $matches);
            preg_match_all('/<[^<>\s]+>/', implode(' ', $matches[1]), $ids);
            if (in_array('<' . trim($delivery['smtp_message_id'], '<>') . '>', $ids[0], true)) {
                $message['reply_id'] = (int) $candidate['id'];
                break 2;
            }
        }
    }
    $message['label'] = !in_array($message['task_status'], ['open','in_progress','waiting'], true) || $message['is_archived']
        ? 'Follow-up closed' : ($message['reply_id'] ? 'Reply received — review needed' : 'Awaiting reply');
    return $message;
}
