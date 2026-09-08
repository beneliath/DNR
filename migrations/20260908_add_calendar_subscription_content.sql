-- Existing private links keep their event, presentation, and birthday content.
ALTER TABLE calendar_subscriptions
    ADD COLUMN include_events TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN include_presentations TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN work_scope ENUM('none', 'my', 'all') NOT NULL DEFAULT 'none',
    ADD COLUMN include_birthdays TINYINT UNSIGNED NOT NULL DEFAULT 1;

-- Task completion, reassignment, rescheduling, and deletion must invalidate feeds.
CREATE TRIGGER calendar_tasks_after_insert AFTER INSERT ON follow_up_tasks
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_tasks_after_update AFTER UPDATE ON follow_up_tasks
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_tasks_after_delete AFTER DELETE ON follow_up_tasks
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
