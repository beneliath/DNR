-- Add edit-version timestamps to contacts and normalize the engagement caller
-- relationship without discarding the historical username snapshot.
SET @add_contact_created_at = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'contacts' AND column_name = 'created_at'
    ),
    'DO 0',
    'ALTER TABLE contacts ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
);
PREPARE statement FROM @add_contact_created_at;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @add_contact_updated_at = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'contacts' AND column_name = 'updated_at'
    ),
    'DO 0',
    'ALTER TABLE contacts ADD COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)'
);
PREPARE statement FROM @add_contact_updated_at;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @add_caller_user_id = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'engagements' AND column_name = 'caller_user_id'
    ),
    'DO 0',
    'ALTER TABLE engagements ADD COLUMN caller_user_id INT NULL AFTER caller_name'
);
PREPARE statement FROM @add_caller_user_id;
EXECUTE statement;
DEALLOCATE PREPARE statement;

UPDATE engagements engagement
INNER JOIN users caller ON caller.username = engagement.caller_name
SET engagement.caller_user_id = caller.id
WHERE engagement.caller_user_id IS NULL
  AND TRIM(COALESCE(engagement.caller_name, '')) <> '';

SET @add_caller_index = IF(
    EXISTS(
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'engagements'
          AND index_name = 'idx_engagements_caller_user'
    ),
    'DO 0',
    'ALTER TABLE engagements ADD INDEX idx_engagements_caller_user (caller_user_id, is_deleted, event_start_date)'
);
PREPARE statement FROM @add_caller_index;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @add_caller_foreign_key = IF(
    EXISTS(
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'engagements'
          AND constraint_name = 'fk_engagement_caller_user'
    ),
    'DO 0',
    'ALTER TABLE engagements ADD CONSTRAINT fk_engagement_caller_user FOREIGN KEY (caller_user_id) REFERENCES users(id) ON DELETE SET NULL'
);
PREPARE statement FROM @add_caller_foreign_key;
EXECUTE statement;
DEALLOCATE PREPARE statement;

UPDATE engagements
SET confirmation_status = 'work_in_progress'
WHERE confirmation_status IS NULL
   OR confirmation_status NOT IN ('work_in_progress', 'under_review', 'confirmed');

UPDATE engagements
SET event_type_other = CONCAT('Legacy value: ', LEFT(COALESCE(event_type, ''), 35)),
    event_type = 'other'
WHERE event_type IS NULL
   OR event_type NOT IN ('conference', 'service', 'study or teaching', 'Passover Seder', 'other');

ALTER TABLE organizations
    MODIFY updated_at TIMESTAMP(6) NOT NULL
        DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);
ALTER TABLE contacts
    MODIFY updated_at TIMESTAMP(6) NOT NULL
        DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);
ALTER TABLE engagements
    MODIFY event_type VARCHAR(50) NOT NULL DEFAULT 'conference',
    MODIFY confirmation_status VARCHAR(50) NOT NULL DEFAULT 'work_in_progress',
    MODIFY updated_at TIMESTAMP(6) NOT NULL
        DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);

SET @add_engagement_status_check = IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'engagements'
          AND constraint_name = 'chk_engagement_confirmation_status'
    ),
    'DO 0',
    "ALTER TABLE engagements ADD CONSTRAINT chk_engagement_confirmation_status CHECK (confirmation_status IN ('work_in_progress', 'under_review', 'confirmed'))"
);
PREPARE statement FROM @add_engagement_status_check;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @add_engagement_type_check = IF(
    EXISTS(
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'engagements'
          AND constraint_name = 'chk_engagement_type'
    ),
    'DO 0',
    "ALTER TABLE engagements ADD CONSTRAINT chk_engagement_type CHECK (event_type IN ('conference', 'service', 'study or teaching', 'Passover Seder', 'other'))"
);
PREPARE statement FROM @add_engagement_type_check;
EXECUTE statement;
DEALLOCATE PREPARE statement;
