-- Split the legacy combined contact name into independently sortable fields.
-- Safe to run more than once on MySQL 8.

SET @has_contact_first_name = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'contact_first_name'
);
SET @add_contact_first_name = IF(
    @has_contact_first_name = 0,
    'ALTER TABLE contacts ADD COLUMN contact_first_name VARCHAR(255) NULL AFTER organization_id',
    'SELECT 1'
);
PREPARE add_contact_first_name_statement FROM @add_contact_first_name;
EXECUTE add_contact_first_name_statement;
DEALLOCATE PREPARE add_contact_first_name_statement;

SET @has_contact_last_name = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'contact_last_name'
);
SET @add_contact_last_name = IF(
    @has_contact_last_name = 0,
    'ALTER TABLE contacts ADD COLUMN contact_last_name VARCHAR(255) NULL AFTER contact_first_name',
    'SELECT 1'
);
PREPARE add_contact_last_name_statement FROM @add_contact_last_name;
EXECUTE add_contact_last_name_statement;
DEALLOCATE PREPARE add_contact_last_name_statement;

SET @has_legacy_contact_name = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'contact_name'
);
SET @backfill_contact_names = IF(
    @has_legacy_contact_name = 1,
    'UPDATE contacts
     SET contact_first_name = CASE
             WHEN TRIM(contact_name) = '''' THEN ''''
             WHEN LOCATE('' '', TRIM(contact_name)) = 0 THEN ''''
             ELSE SUBSTRING_INDEX(TRIM(contact_name), '' '', 1)
         END,
         contact_last_name = CASE
             WHEN TRIM(contact_name) = '''' THEN ''Unknown''
             WHEN LOCATE('' '', TRIM(contact_name)) = 0 THEN TRIM(contact_name)
             ELSE TRIM(SUBSTRING(TRIM(contact_name), LOCATE('' '', TRIM(contact_name)) + 1))
         END
     WHERE contact_first_name IS NULL OR contact_last_name IS NULL',
    'SELECT 1'
);
PREPARE backfill_contact_names_statement FROM @backfill_contact_names;
EXECUTE backfill_contact_names_statement;
DEALLOCATE PREPARE backfill_contact_names_statement;

UPDATE contacts
SET contact_first_name = COALESCE(contact_first_name, ''),
    contact_last_name = COALESCE(NULLIF(TRIM(contact_last_name), ''), 'Unknown')
WHERE contact_first_name IS NULL
   OR contact_last_name IS NULL
   OR TRIM(contact_last_name) = '';

ALTER TABLE contacts
    MODIFY COLUMN contact_first_name VARCHAR(255) NOT NULL,
    MODIFY COLUMN contact_last_name VARCHAR(255) NOT NULL;

SET @has_last_first_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND index_name = 'idx_contacts_last_first'
);
SET @add_last_first_index = IF(
    @has_last_first_index = 0,
    'ALTER TABLE contacts ADD INDEX idx_contacts_last_first (contact_last_name, contact_first_name)',
    'SELECT 1'
);
PREPARE add_last_first_index_statement FROM @add_last_first_index;
EXECUTE add_last_first_index_statement;
DEALLOCATE PREPARE add_last_first_index_statement;

SET @drop_legacy_contact_name = IF(
    @has_legacy_contact_name = 1,
    'ALTER TABLE contacts DROP COLUMN contact_name',
    'SELECT 1'
);
PREPARE drop_legacy_contact_name_statement FROM @drop_legacy_contact_name;
EXECUTE drop_legacy_contact_name_statement;
DEALLOCATE PREPARE drop_legacy_contact_name_statement;
