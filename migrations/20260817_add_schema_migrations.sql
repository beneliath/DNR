CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(255) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO schema_migrations (migration_name, checksum) VALUES
('20260814_add_last_login_at.sql', REPEAT('0', 64)),
('20260814_add_must_change_password.sql', REPEAT('0', 64)),
('20260814_add_shared_calendar.sql', REPEAT('0', 64)),
('20260814_add_two_factor_authentication.sql', REPEAT('0', 64)),
('20260814_add_user_timestamps.sql', REPEAT('0', 64)),
('20260815_add_audit_log.sql', REPEAT('0', 64)),
('20260815_add_contact_archiving.sql', REPEAT('0', 64)),
('20260815_add_event_title.sql', REPEAT('0', 64)),
('20260815_split_contact_names.sql', REPEAT('0', 64)),
('20260817_add_engagement_chron_entries.sql', REPEAT('0', 64)),
('20260817_add_presentation_archiving.sql', REPEAT('0', 64)),
('20260817_add_schema_migrations.sql', REPEAT('0', 64));
