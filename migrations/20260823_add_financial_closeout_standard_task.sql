-- Keep the financial closeout reminder as a required built-in event task.
-- The upsert updates existing installations without rewriting tasks that were
-- already copied onto events.
INSERT INTO standard_event_tasks
    (template_key, title, details, priority, due_anchor,
     due_offset_days, sort_order, is_archived, archived_by, archived_at)
VALUES
    ('standard.financial_closeout',
     'Complete the event financial closeout',
     'Finalize the event financial report with all giving/income, lodging, and travel received.',
     'high', 'event_end', 7, 90, 0, NULL, NULL)
ON DUPLICATE KEY UPDATE
    title = 'Complete the event financial closeout',
    details = 'Finalize the event financial report with all giving/income, lodging, and travel received.',
    priority = 'high',
    due_anchor = 'event_end',
    due_offset_days = 7,
    sort_order = 90,
    is_archived = 0,
    archived_by = NULL,
    archived_at = NULL;
