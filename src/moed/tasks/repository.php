<?php

declare(strict_types=1);

function insertFollowUpTask(mysqli $conn, array $task, int $created_by): int
{
    lockFollowUpTaskInquiries($conn, [$task['inquiry_id'] ?? null]);
    lockFollowUpTaskEngagements($conn, [$task['engagement_id'] ?? null]);
    $completed_by = $task['status'] === 'completed' ? $created_by : null;
    $completed_at = $task['status'] === 'completed' ? gmdate('Y-m-d H:i:s') : null;
    $stmt = $conn->prepare(
        'INSERT INTO follow_up_tasks
            (title, details, status, priority, due_date, waiting_on,
             subject_type, engagement_id, organization_id, contact_id, inquiry_id,
             assigned_to, created_by, completed_by, completed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the task.');
    }
    $stmt->bind_param(
        'sssssssiiiiiiis',
        $task['title'],
        $task['details'],
        $task['status'],
        $task['priority'],
        $task['due_date'],
        $task['waiting_on'],
        $task['subject_type'],
        $task['engagement_id'],
        $task['organization_id'],
        $task['contact_id'],
        $task['inquiry_id'],
        $task['assigned_to'],
        $created_by,
        $completed_by,
        $completed_at
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to save the task.');
    }
    $task_id = (int) $conn->insert_id;
    $stmt->close();
    return $task_id;
}

function followUpTaskSelectSql()
{
    return "SELECT
                t.*,
                assignee.username AS assignee_username,
                creator.username AS creator_username,
                completer.username AS completer_username,
                COALESCE(NULLIF(TRIM(e.event_title), ''), eo.organization_name) AS engagement_label,
                o.organization_name AS organization_label,
                CONCAT(c.contact_last_name, ', ', c.contact_first_name) AS contact_label,
                inquiry.title AS inquiry_label
            FROM follow_up_tasks t
            LEFT JOIN users assignee ON assignee.id = t.assigned_to
            LEFT JOIN users creator ON creator.id = t.created_by
            LEFT JOIN users completer ON completer.id = t.completed_by
            LEFT JOIN engagements e ON e.id = t.engagement_id
            LEFT JOIN organizations eo ON eo.id = e.organization_id
            LEFT JOIN organizations o ON o.id = t.organization_id
            LEFT JOIN contacts c ON c.id = t.contact_id
            LEFT JOIN booking_inquiries inquiry ON inquiry.id = t.inquiry_id";
}

function fetchFollowUpTask(mysqli $conn, $task_id, $lock = false)
{
    $task_id = (int) $task_id;
    if ($task_id < 1) {
        return null;
    }
    if ($lock) {
        $sql = 'SELECT * FROM follow_up_tasks WHERE id = ? FOR UPDATE';
    } else {
        $sql = followUpTaskSelectSql() . ' WHERE t.id = ?';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $task ?: null;
}

function fetchFollowUpTasksForSubject(
    mysqli $conn,
    $subject_type,
    $subject_id = null,
    $limit = null
)
{
    if ($limit !== null) {
        $limit = (int) $limit;
        if ($limit < 1) {
            throw new InvalidArgumentException('The follow-up task limit must be at least 1.');
        }
    }

    if ($subject_type === 'general') {
        $where = "t.subject_type = 'general'";
        $bind = false;
    } elseif (in_array($subject_type, ['engagement', 'organization', 'contact', 'inquiry'], true)
        && (int) $subject_id > 0
    ) {
        $where = 't.' . $subject_type . '_id = ?';
        $bind = true;
        $subject_id = (int) $subject_id;
    } else {
        return [];
    }

    $sql = followUpTaskSelectSql()
        . " WHERE {$where}
            AND t.status IN ('open', 'in_progress', 'waiting') AND t.is_archived = 0
            ORDER BY
                t.due_date IS NULL,
                t.due_date ASC,
                FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
                t.id ASC";
    if ($limit !== null) {
        $sql .= ' LIMIT ?';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($bind && $limit !== null) {
        $stmt->bind_param('ii', $subject_id, $limit);
    } elseif ($bind) {
        $stmt->bind_param('i', $subject_id);
    } elseif ($limit !== null) {
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}
