-- Add an optional title to engagements while preserving existing records.
-- Safe to run more than once on MySQL 8.

SET @has_engagement_event_title = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'engagements'
      AND column_name = 'event_title'
);
SET @add_engagement_event_title = IF(
    @has_engagement_event_title = 0,
    'ALTER TABLE engagements ADD COLUMN event_title VARCHAR(255) NULL AFTER organization_id',
    'SELECT 1'
);
PREPARE add_engagement_event_title_statement FROM @add_engagement_event_title;
EXECUTE add_engagement_event_title_statement;
DEALLOCATE PREPARE add_engagement_event_title_statement;
