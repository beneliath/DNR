-- Keep a task's workflow state and history when it leaves active work.
ALTER TABLE follow_up_tasks
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN archived_at DATETIME NULL,
    ADD COLUMN archived_by INT NULL,
    ADD CONSTRAINT fk_follow_up_task_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_follow_up_task_archive (is_archived, updated_at, id);
