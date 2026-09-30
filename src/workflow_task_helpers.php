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
function emailHeadersReferenceMessage(string $rawHeaders, string $smtpMessageId): bool
{
    $headers = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders);
    preg_match_all('/^(?:In-Reply-To|References):([^\r\n]*)/mi', $headers, $matches);
    preg_match_all('/<[^<>\s]+>/', implode(' ', $matches[1]), $ids);
    return in_array('<' . trim($smtpMessageId, '<>') . '>', $ids[0], true);
}

/** Load email follow-up labels for a task page with a fixed number of queries. */
function emailFollowUpStatesForTasks(mysqli $conn, array $taskIds): array
{
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn (int $id): bool => $id > 0)));
    if (!$taskIds || !workflowTasksAvailable($conn)) return [];

    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $messages = $conn->execute_query(
        "SELECT m.id, m.follow_up_task_id, t.status AS task_status, t.is_archived
         FROM engagement_email_messages m
         JOIN follow_up_tasks t ON t.id = m.follow_up_task_id
         WHERE m.follow_up_task_id IN ({$placeholders})
         ORDER BY m.id",
        $taskIds
    )->fetch_all(MYSQLI_ASSOC);

    $states = [];
    $openMessageIds = [];
    $taskIdByMessageId = [];
    foreach ($messages as $message) {
        $taskId = (int) $message['follow_up_task_id'];
        // A task normally has one message; use the first one consistently if it has more.
        if (isset($states[$taskId])) continue;
        $closed = !in_array($message['task_status'], ['open', 'in_progress', 'waiting'], true)
            || (bool) $message['is_archived'];
        $messageId = (int) $message['id'];
        $states[$taskId] = ['id' => $messageId, 'label' => $closed ? 'Follow-up closed' : 'Awaiting reply'];
        if (!$closed) {
            $openMessageIds[] = $messageId;
            $taskIdByMessageId[$messageId] = $taskId;
        }
    }
    if (!$openMessageIds) return $states;

    $placeholders = implode(',', array_fill(0, count($openMessageIds), '?'));
    $candidates = $conn->execute_query(
        "SELECT ranked.message_id, ranked.smtp_message_id, i.raw_headers
         FROM (
             SELECT d.id AS delivery_id, d.message_id, d.smtp_message_id, i.id AS inbound_id,
                    ROW_NUMBER() OVER (PARTITION BY d.id ORDER BY i.received_at DESC) AS candidate_rank
             FROM engagement_email_deliveries d
             JOIN engagement_email_messages m ON m.id = d.message_id
             JOIN inbound_email_messages i ON i.status = 'processed'
                 AND i.received_at >= m.created_at
                 AND LOWER(i.sender_address) = LOWER(d.recipient_email)
             WHERE d.message_id IN ({$placeholders}) AND d.smtp_message_id IS NOT NULL
                 AND (EXISTS (SELECT 1 FROM engagement_chron_entries c
                              WHERE c.inbound_email_message_id = i.id AND c.engagement_id = m.engagement_id)
                   OR EXISTS (SELECT 1 FROM booking_inquiry_chron_entries c
                              WHERE c.inbound_email_message_id = i.id AND c.booking_inquiry_id = m.booking_inquiry_id))
         ) ranked
         JOIN inbound_email_messages i ON i.id = ranked.inbound_id
         WHERE ranked.candidate_rank <= 100
         ORDER BY ranked.delivery_id, ranked.candidate_rank",
        $openMessageIds
    );
    while ($candidate = $candidates->fetch_assoc()) {
        $taskId = $taskIdByMessageId[(int) $candidate['message_id']];
        if ($states[$taskId]['label'] === 'Awaiting reply'
            && emailHeadersReferenceMessage($candidate['raw_headers'], $candidate['smtp_message_id'])) {
            $states[$taskId]['label'] = 'Reply received — review needed';
        }
    }
    return $states;
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
            if (emailHeadersReferenceMessage($candidate['raw_headers'], $delivery['smtp_message_id'])) {
                $message['reply_id'] = (int) $candidate['id'];
                break 2;
            }
        }
    }
    $message['label'] = !in_array($message['task_status'], ['open','in_progress','waiting'], true) || $message['is_archived']
        ? 'Follow-up closed' : ($message['reply_id'] ? 'Reply received — review needed' : 'Awaiting reply');
    return $message;
}
