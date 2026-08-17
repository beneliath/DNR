-- Add an optional multi-line description to engagements.
SET @has_engagement_event_description = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'engagements'
      AND column_name = 'event_description'
);
SET @add_engagement_event_description = IF(
    @has_engagement_event_description = 0,
    'ALTER TABLE engagements ADD COLUMN event_description TEXT NULL AFTER event_title',
    'SELECT 1'
);
PREPARE add_engagement_event_description_statement FROM @add_engagement_event_description;
EXECUTE add_engagement_event_description_statement;
DEALLOCATE PREPARE add_engagement_event_description_statement;
