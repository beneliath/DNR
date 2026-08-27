-- Required standard-task policy belongs to persisted task definitions instead
-- of a PHP template-key allowlist. Existing applied task migrations remain
-- immutable; this forward migration preserves the financial closeout invariant.
ALTER TABLE standard_event_tasks
    ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order;

UPDATE standard_event_tasks
SET is_required = 1,
    is_archived = 0,
    archived_by = NULL,
    archived_at = NULL
WHERE template_key = 'standard.financial_closeout';

ALTER TABLE standard_event_tasks
    ADD CONSTRAINT chk_standard_event_task_required_active CHECK (
        is_required = 0 OR is_archived = 0
    ),
    ADD INDEX idx_standard_event_task_required (is_required, is_archived, sort_order);
