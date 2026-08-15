-- Add reversible archive state to contacts.
-- Safe to run more than once on MySQL 8.

SET @has_contact_is_deleted = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'is_deleted'
);
SET @add_contact_is_deleted = IF(
    @has_contact_is_deleted = 0,
    'ALTER TABLE contacts ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER contact_phone',
    'SELECT 1'
);
PREPARE add_contact_is_deleted_statement FROM @add_contact_is_deleted;
EXECUTE add_contact_is_deleted_statement;
DEALLOCATE PREPARE add_contact_is_deleted_statement;

SET @has_contact_archived_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND index_name = 'idx_contacts_archived'
);
SET @add_contact_archived_index = IF(
    @has_contact_archived_index = 0,
    'ALTER TABLE contacts ADD INDEX idx_contacts_archived (is_deleted)',
    'SELECT 1'
);
PREPARE add_contact_archived_index_statement FROM @add_contact_archived_index;
EXECUTE add_contact_archived_index_statement;
DEALLOCATE PREPARE add_contact_archived_index_statement;
