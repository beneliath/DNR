-- Let each opted-in user choose a delivery time and any combination of days.
-- Bit 0 is Monday through bit 6 for Sunday; 127 preserves the existing
-- every-day behavior for users who already enabled digests.
ALTER TABLE users
    ADD COLUMN task_digest_time TIME NOT NULL DEFAULT '07:00:00'
        AFTER task_digest_enabled,
    ADD COLUMN task_digest_days TINYINT UNSIGNED NOT NULL DEFAULT 127
        AFTER task_digest_time,
    ADD CONSTRAINT chk_users_task_digest_days
        CHECK (task_digest_days BETWEEN 1 AND 127);
