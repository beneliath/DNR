-- The original scheduling timestamp and editor are unknown for historical rows.
UPDATE follow_up_tasks task
SET task.status = 'completed', task.waiting_on = NULL, task.completed_by = NULL,
    task.completed_at = GREATEST(task.created_at, UTC_TIMESTAMP()),
    task.updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(task.updated_at, INTERVAL 1 MICROSECOND))
WHERE task.template_key = 'standard.confirm_presentations' AND task.subject_type = 'engagement'
  AND task.is_archived = 0 AND task.status IN ('open', 'in_progress', 'waiting')
  AND EXISTS (SELECT 1 FROM presentations p
              WHERE p.engagement_id = task.engagement_id AND p.is_archived = 0
                AND p.topic_title REGEXP '[^[:space:]]'
                AND p.presentation_date IS NOT NULL AND p.presentation_time IS NOT NULL);
