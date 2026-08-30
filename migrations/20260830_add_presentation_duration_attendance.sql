ALTER TABLE presentations
    ADD COLUMN duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER presentation_time,
    ADD COLUMN actual_attendance INT NULL AFTER expected_attendance,
    ADD CONSTRAINT chk_presentation_duration_minutes
        CHECK (duration_minutes BETWEEN 1 AND 1440),
    ADD CONSTRAINT chk_presentation_actual_attendance
        CHECK (actual_attendance IS NULL OR actual_attendance >= 0);
