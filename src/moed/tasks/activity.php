<?php

declare(strict_types=1);

const EVENT_PRESENTATION_TASK_TITLE = 'Populate event record with one or more presentations';
const EVENT_CONFIRM_PRESENTATIONS_TASK_KEY = 'standard.confirm_presentations';
const EVENT_CLOSEOUT_TASK_KEY = 'standard.financial_closeout';
const SCHEDULED_PRESENTATION_SQL = "p.topic_title REGEXP '[^[:space:]]' AND p.presentation_date IS NOT NULL AND p.presentation_time IS NOT NULL";

/** The caller owns the engagement transaction. Only outstanding generated tasks can transition. */
function completePerformedStandardEventTasks(mysqli $conn, int $engagementId, ?int $actorId): void
{
    $scheduled = SCHEDULED_PRESENTATION_SQL;
    $conn->execute_query(
        "UPDATE follow_up_tasks task
         LEFT JOIN standard_event_tasks template ON template.template_key = task.template_key
         LEFT JOIN engagement_financial_reports report
           ON report.engagement_id = task.engagement_id AND task.template_key = ?
         SET task.status = 'completed', task.waiting_on = NULL,
             task.completed_by = IF(report.engagement_id IS NULL, ?, report.closed_by),
             task.completed_at = GREATEST(task.created_at, COALESCE(report.closed_at, UTC_TIMESTAMP())),
             task.updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(task.updated_at, INTERVAL 1 MICROSECOND))
         WHERE task.engagement_id = ? AND task.subject_type = 'engagement' AND task.is_archived = 0
           AND task.status IN ('open', 'in_progress', 'waiting')
           AND (report.engagement_id IS NOT NULL OR (
               (task.template_key = ? OR (COALESCE(template.title, task.title) = ?
                   AND (template.id IS NOT NULL OR task.template_key REGEXP '^(standard|custom|deployment)[.]')))
               AND EXISTS (SELECT 1 FROM presentations p
                           WHERE p.engagement_id = task.engagement_id AND p.is_archived = 0
                             AND (task.template_key <> ? OR ({$scheduled})))
           ))",
        [EVENT_CLOSEOUT_TASK_KEY, $actorId, $engagementId, EVENT_CONFIRM_PRESENTATIONS_TASK_KEY,
            EVENT_PRESENTATION_TASK_TITLE, EVENT_CONFIRM_PRESENTATIONS_TASK_KEY]
    );
}
