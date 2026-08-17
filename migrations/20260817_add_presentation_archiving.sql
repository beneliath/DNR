-- Add reversible archive state and archive attribution to presentations.
-- Safe to run more than once on MySQL 8.

SET @has_presentation_is_archived = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'presentations'
      AND column_name = 'is_archived'
);
SET @add_presentation_is_archived = IF(
    @has_presentation_is_archived = 0,
    'ALTER TABLE presentations ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER expected_attendance',
    'SELECT 1'
);
PREPARE add_presentation_is_archived_statement FROM @add_presentation_is_archived;
EXECUTE add_presentation_is_archived_statement;
DEALLOCATE PREPARE add_presentation_is_archived_statement;

SET @has_presentation_archived_by = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'presentations'
      AND column_name = 'archived_by'
);
SET @add_presentation_archived_by = IF(
    @has_presentation_archived_by = 0,
    'ALTER TABLE presentations ADD COLUMN archived_by INT NULL AFTER is_archived',
    'SELECT 1'
);
PREPARE add_presentation_archived_by_statement FROM @add_presentation_archived_by;
EXECUTE add_presentation_archived_by_statement;
DEALLOCATE PREPARE add_presentation_archived_by_statement;

SET @has_presentation_archived_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'presentations'
      AND column_name = 'archived_at'
);
SET @add_presentation_archived_at = IF(
    @has_presentation_archived_at = 0,
    'ALTER TABLE presentations ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER created_at',
    'SELECT 1'
);
PREPARE add_presentation_archived_at_statement FROM @add_presentation_archived_at;
EXECUTE add_presentation_archived_at_statement;
DEALLOCATE PREPARE add_presentation_archived_at_statement;

SET @has_presentation_archiver_fk = (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'presentations'
      AND constraint_name = 'fk_presentation_archiver'
      AND constraint_type = 'FOREIGN KEY'
);
SET @add_presentation_archiver_fk = IF(
    @has_presentation_archiver_fk = 0,
    'ALTER TABLE presentations ADD CONSTRAINT fk_presentation_archiver FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE add_presentation_archiver_fk_statement FROM @add_presentation_archiver_fk;
EXECUTE add_presentation_archiver_fk_statement;
DEALLOCATE PREPARE add_presentation_archiver_fk_statement;

SET @has_presentation_archive_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'presentations'
      AND index_name = 'idx_presentation_engagement_active_schedule'
);
SET @add_presentation_archive_index = IF(
    @has_presentation_archive_index = 0,
    'ALTER TABLE presentations ADD INDEX idx_presentation_engagement_active_schedule (engagement_id, is_archived, presentation_date, presentation_time, id)',
    'SELECT 1'
);
PREPARE add_presentation_archive_index_statement FROM @add_presentation_archive_index;
EXECUTE add_presentation_archive_index_statement;
DEALLOCATE PREPARE add_presentation_archive_index_statement;
