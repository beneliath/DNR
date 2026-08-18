-- Add optional incidental notes to contacts.
SET @has_contact_notes = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contacts'
      AND column_name = 'contact_notes'
);
SET @add_contact_notes = IF(
    @has_contact_notes = 0,
    'ALTER TABLE contacts ADD COLUMN contact_notes TEXT NULL AFTER contact_phone',
    'SELECT 1'
);
PREPARE add_contact_notes_statement FROM @add_contact_notes;
EXECUTE add_contact_notes_statement;
DEALLOCATE PREPARE add_contact_notes_statement;
