-- Recoverable background work, native schedule types, compact image delivery,
-- checklist rescheduling, and stable calendar-feed revisions.

ALTER TABLE engagement_map_geocode_queue
    MODIFY status ENUM('pending', 'processing', 'retry', 'failed')
        NOT NULL DEFAULT 'pending',
    ADD COLUMN processing_started_at DATETIME NULL AFTER next_attempt_at,
    DROP INDEX idx_geocode_queue_ready,
    ADD INDEX idx_geocode_queue_ready (
        status, next_attempt_at, processing_started_at, attempts
    );

ALTER TABLE presentations
    ADD COLUMN presentation_time_value TIME NULL AFTER presentation_time;

UPDATE presentations
SET presentation_time_value = STR_TO_DATE(presentation_time, '%h:%i %p')
WHERE presentation_time IS NOT NULL AND TRIM(presentation_time) <> '';

ALTER TABLE presentations
    DROP CHECK chk_presentation_time_format,
    DROP INDEX idx_presentation_engagement_active_schedule,
    DROP COLUMN presentation_time,
    CHANGE COLUMN presentation_time_value presentation_time TIME NULL,
    ADD INDEX idx_presentation_engagement_active_schedule (
        engagement_id, is_archived, presentation_date, presentation_time, id
    ),
    ADD INDEX idx_presentation_calendar_schedule (
        is_archived, presentation_date, presentation_time, id, engagement_id
    );

ALTER TABLE follow_up_tasks
    ADD COLUMN due_date_overridden TINYINT(1) NOT NULL DEFAULT 0
        AFTER template_key,
    ADD INDEX idx_follow_up_task_template (template_key);

ALTER TABLE users
    ADD COLUMN profile_picture_thumbnail BLOB NULL AFTER profile_picture,
    ADD COLUMN profile_picture_thumbnail_mime VARCHAR(32) NULL
        AFTER profile_picture_thumbnail;

ALTER TABLE contacts
    ADD COLUMN contact_photo_thumbnail BLOB NULL AFTER contact_photo,
    ADD COLUMN contact_photo_thumbnail_mime VARCHAR(32) NULL
        AFTER contact_photo_thumbnail;

CREATE TABLE calendar_feed_revision (
    id TINYINT UNSIGNED PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_calendar_feed_revision_singleton CHECK (id = 1)
);

INSERT INTO calendar_feed_revision (id, revision) VALUES (1, 1);

DROP TRIGGER IF EXISTS calendar_organizations_after_insert;
DROP TRIGGER IF EXISTS calendar_organizations_after_update;
DROP TRIGGER IF EXISTS calendar_organizations_after_delete;
DROP TRIGGER IF EXISTS calendar_engagements_after_insert;
DROP TRIGGER IF EXISTS calendar_engagements_after_update;
DROP TRIGGER IF EXISTS calendar_engagements_after_delete;
DROP TRIGGER IF EXISTS calendar_presentations_after_insert;
DROP TRIGGER IF EXISTS calendar_presentations_after_update;
DROP TRIGGER IF EXISTS calendar_presentations_after_delete;

CREATE TRIGGER calendar_organizations_after_insert AFTER INSERT ON organizations
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_organizations_after_update AFTER UPDATE ON organizations
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_organizations_after_delete AFTER DELETE ON organizations
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;

CREATE TRIGGER calendar_engagements_after_insert AFTER INSERT ON engagements
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_engagements_after_update AFTER UPDATE ON engagements
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_engagements_after_delete AFTER DELETE ON engagements
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;

CREATE TRIGGER calendar_presentations_after_insert AFTER INSERT ON presentations
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_presentations_after_update AFTER UPDATE ON presentations
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_presentations_after_delete AFTER DELETE ON presentations
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
