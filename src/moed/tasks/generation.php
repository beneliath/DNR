<?php

declare(strict_types=1);

function generateStandardTaskForOpenEngagements(
    mysqli $conn,
    int $template_id,
    int $created_by,
    bool $manage_transaction = true
): int {
    if ($template_id < 1 || $created_by < 1) {
        throw new InvalidArgumentException('Select a valid standard task and creator.');
    }
    if ($manage_transaction) {
        $conn->begin_transaction();
    }
    try {
        $template = fetchStandardEventTask($conn, $template_id, true);
        if (!$template || !empty($template['is_archived'])) {
            throw new InvalidArgumentException('Only active standard tasks can be added to engagements.');
        }
        $creator = $conn->prepare("SELECT id FROM users WHERE id = ? AND account_status = 'active' FOR UPDATE");
        $creator->bind_param('i', $created_by);
        $creator->execute();
        $active_creator = $creator->get_result()->num_rows === 1;
        $creator->close();
        if (!$active_creator) {
            throw new InvalidArgumentException('An active account is required to generate tasks.');
        }

        $anchor = match ($template['due_anchor']) {
            'event_start' => 'event_start_date',
            'event_end' => 'event_end_date',
            default => throw new RuntimeException('A standard event task has an invalid due-date rule.'),
        };
        // One locking INSERT SELECT avoids materializing the engagement directory
        // and two database round trips per row. Share locks preserve the same
        // ordering against concurrent closeout, archive, date and owner changes.
        // Existing copies (including archived/completed ones) are left untouched.
        $insert = $conn->prepare(
            "INSERT INTO follow_up_tasks
                (title, details, status, priority, due_date, subject_type, engagement_id,
                 assigned_to, created_by, template_key, due_date_overridden)
             SELECT ?, ?, 'open', ?, DATE_ADD(engagement.{$anchor}, INTERVAL ? DAY),
                    'engagement', engagement.id, COALESCE(caller.id, ?), ?, ?, 0
             FROM engagements engagement
             INNER JOIN organizations organization ON organization.id = engagement.organization_id
             LEFT JOIN engagement_financial_reports report ON report.engagement_id = engagement.id
             LEFT JOIN users caller ON caller.id = engagement.caller_user_id AND caller.account_status = 'active'
             WHERE engagement.is_deleted = 0 AND organization.is_deleted = 0
               AND engagement.lifecycle_status = 'active' AND report.engagement_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM follow_up_tasks existing
                   WHERE existing.engagement_id = engagement.id AND existing.template_key = ?
               )
             ORDER BY engagement.id
             FOR SHARE"
        );
        $insert->bind_param('sssiiiss', $template['title'], $template['details'],
            $template['priority'], $template['due_offset_days'], $created_by, $created_by,
            $template['template_key'], $template['template_key']);
        try {
            $insert->execute();
            $inserted = $insert->affected_rows;
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1441) {
                throw new InvalidArgumentException('A generated task due date is outside the supported date range.', 0, $exception);
            }
            throw $exception;
        } finally {
            $insert->close();
        }
        if ($manage_transaction) {
            $conn->commit();
        }
        return $inserted;
    } catch (Throwable $exception) {
        if ($manage_transaction) {
            $conn->rollback();
        }
        throw $exception;
    }
}

function initialEngagementChecklistAssigneeId($caller_user_id, $creator_user_id)
{
    $caller_user_id = $caller_user_id === null ? null : (int) $caller_user_id;
    $creator_user_id = (int) $creator_user_id;
    if ($creator_user_id < 1 || ($caller_user_id !== null && $caller_user_id < 1)) {
        throw new InvalidArgumentException('Unable to select the initial checklist owner.');
    }
    return $caller_user_id ?? $creator_user_id;
}

function rescheduleGeneratedEngagementTasks(
    mysqli $conn,
    $engagement_id,
    $event_start_date,
    $event_end_date
) {
    $engagement_id = (int) $engagement_id;
    if ($engagement_id < 1
        || !validIsoDate($event_start_date)
        || !validIsoDate($event_end_date)
    ) {
        throw new InvalidArgumentException('Unable to reschedule the engagement checklist.');
    }

    $stmt = $conn->prepare(
        "UPDATE follow_up_tasks task
         INNER JOIN standard_event_tasks template
             ON template.template_key = task.template_key
         SET task.due_date = DATE_ADD(
                IF(template.due_anchor = 'event_end', ?, ?),
                INTERVAL template.due_offset_days DAY
             )
         WHERE task.engagement_id = ?
           AND task.template_key IS NOT NULL
           AND task.due_date_overridden = 0
           AND task.status IN ('open', 'in_progress', 'waiting') AND task.is_archived = 0"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare checklist rescheduling.');
    }
    $stmt->bind_param('ssi', $event_end_date, $event_start_date, $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to reschedule the engagement checklist.');
    }
    $updated = $stmt->affected_rows;
    $stmt->close();
    return $updated;
}

function generateEngagementFollowUpChecklist(
    mysqli $conn,
    $engagement_id,
    $assigned_to,
    $created_by,
    $manage_transaction = true
) {
    $engagement_id = (int) $engagement_id;
    $assigned_to = $assigned_to === null ? null : (int) $assigned_to;
    $created_by = (int) $created_by;
    if ($engagement_id < 1 || $created_by < 1) {
        throw new InvalidArgumentException('Unable to create a checklist for that engagement.');
    }

    $manage_transaction = (bool) $manage_transaction;
    if ($manage_transaction) {
        $conn->begin_transaction();
    }
    try {
        lockFollowUpTaskEngagements($conn, [$engagement_id]);
        $engagement_stmt = $conn->prepare(
            "SELECT e.event_start_date, e.event_end_date
             FROM engagements e
             INNER JOIN organizations o ON o.id = e.organization_id
             WHERE e.id = ? AND e.is_deleted = 0 AND o.is_deleted = 0
               AND e.lifecycle_status = 'active'"
        );
        if (!$engagement_stmt) {
            throw new RuntimeException('Unable to load the engagement checklist dates.');
        }
        $engagement_stmt->bind_param('i', $engagement_id);
        $engagement_stmt->execute();
        $engagement = $engagement_stmt->get_result()->fetch_assoc();
        $engagement_stmt->close();
        if (!$engagement) {
            throw new InvalidArgumentException('Checklists can only be added to active engagements.');
        }

        if ($assigned_to !== null) {
            $user_stmt = $conn->prepare(
                "SELECT id FROM users
                 WHERE id = ? AND account_status = 'active' FOR UPDATE"
            );
            $user_stmt->bind_param('i', $assigned_to);
            $user_stmt->execute();
            $has_user = $user_stmt->get_result()->num_rows === 1;
            $user_stmt->close();
            if (!$has_user) {
                throw new InvalidArgumentException('Select a valid checklist assignee.');
            }
        }

        $templates = engagementFollowUpChecklistTemplates(
            $engagement['event_start_date'],
            $engagement['event_end_date'],
            fetchStandardEventTaskTemplates($conn, 'active')
        );
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO follow_up_tasks
                (title, details, status, priority, due_date, subject_type, engagement_id,
                 assigned_to, created_by, template_key, due_date_overridden)
             VALUES (?, ?, 'open', ?, ?, 'engagement', ?, ?, ?, ?, 0)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the engagement checklist.');
        }
        $inserted = 0;
        foreach ($templates as $template) {
            $title = $template['title'];
            $details = $template['details'];
            $priority = $template['priority'];
            $due_date = $template['due_date'];
            $template_key = $template['key'];
            $stmt->bind_param(
                'ssssiiis',
                $title,
                $details,
                $priority,
                $due_date,
                $engagement_id,
                $assigned_to,
                $created_by,
                $template_key
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to add the engagement checklist.');
            }
            $inserted += $stmt->affected_rows;
        }
        $stmt->close();
        if ($manage_transaction) {
            $conn->commit();
        }
        return $inserted;
    } catch (Throwable $exception) {
        if ($manage_transaction) {
            $conn->rollback();
        }
        throw $exception;
    }
}
