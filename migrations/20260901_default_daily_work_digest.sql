-- New accounts receive the standard work digest unless the user changes the
-- preference. Existing per-user values are intentionally left untouched.
ALTER TABLE users
    MODIFY COLUMN task_digest_enabled TINYINT(1) NOT NULL DEFAULT 1,
    MODIFY COLUMN task_digest_time TIME NOT NULL DEFAULT '07:00:00',
    MODIFY COLUMN task_digest_days TINYINT UNSIGNED NOT NULL DEFAULT 31;
