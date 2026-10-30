-- Retire audience definitions without changing contact memberships or saved rules.
-- Names remain reserved so restoring a definition cannot collide with a replacement.
ALTER TABLE marketing_tags
    ADD archived_at DATETIME(6) NULL DEFAULT NULL,
    ADD INDEX idx_marketing_tags_archive (audience_id, archived_at, name);
ALTER TABLE marketing_segments
    ADD archived_at DATETIME(6) NULL DEFAULT NULL,
    ADD INDEX idx_marketing_segments_archive (audience_id, archived_at, name);
ALTER TABLE marketing_group_categories
    ADD archived_at DATETIME(6) NULL DEFAULT NULL,
    ADD INDEX idx_marketing_groups_archive (audience_id, archived_at, name);
