ALTER TABLE follow_up_tasks
    ADD INDEX idx_follow_up_task_assignee_queue
        (assigned_to, status, due_date, priority, id);
