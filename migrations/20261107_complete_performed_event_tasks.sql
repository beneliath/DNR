-- Reconcile outstanding checklist copies once. Presentations have a creation
-- timestamp but no recorded creator. Final reports retain their original closer.
UPDATE follow_up_tasks task
LEFT JOIN standard_event_tasks template ON template.template_key = task.template_key
LEFT JOIN engagement_financial_reports report
  ON report.engagement_id = task.engagement_id AND task.template_key = 'standard.financial_closeout'
LEFT JOIN (
    SELECT engagement_id, MIN(created_at) AS first_saved_at
    FROM presentations WHERE is_archived = 0 GROUP BY engagement_id
) presented ON presented.engagement_id = task.engagement_id
SET task.status = 'completed', task.waiting_on = NULL, task.completed_by = report.closed_by,
    task.completed_at = GREATEST(task.created_at, COALESCE(report.closed_at, presented.first_saved_at, UTC_TIMESTAMP())),
    task.updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(task.updated_at, INTERVAL 1 MICROSECOND))
WHERE task.subject_type = 'engagement' AND task.is_archived = 0
  AND task.status IN ('open', 'in_progress', 'waiting')
  AND (report.engagement_id IS NOT NULL OR (
      presented.engagement_id IS NOT NULL
      AND COALESCE(template.title, task.title) = 'Populate event record with one or more presentations'
      AND (template.id IS NOT NULL OR task.template_key REGEXP '^(standard|custom|deployment)[.]')
  ));
