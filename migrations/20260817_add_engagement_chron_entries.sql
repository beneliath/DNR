-- Replace the single engagement Chron text field with timestamped entries.
CREATE TABLE IF NOT EXISTS engagement_chron_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    entry_text MEDIUMTEXT NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    legacy_engagement_note TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_by_username_snapshot VARCHAR(50) NULL,
    updated_by INT NULL,
    archived_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_chron_entry_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_chron_entry_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_chron_entry_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_chron_entry_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_chron_entry_engagement_active_created (
        engagement_id, is_archived, created_at, id
    )
);

SET @has_chron_creator_username = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'engagement_chron_entries'
      AND column_name = 'created_by_username_snapshot'
);
SET @add_chron_creator_username = IF(
    @has_chron_creator_username = 0,
    'ALTER TABLE engagement_chron_entries ADD COLUMN created_by_username_snapshot VARCHAR(50) NULL AFTER created_by',
    'SELECT 1'
);
PREPARE add_chron_creator_username_statement FROM @add_chron_creator_username;
EXECUTE add_chron_creator_username_statement;
DEALLOCATE PREPARE add_chron_creator_username_statement;

UPDATE engagement_chron_entries ce
INNER JOIN users creator ON creator.id = ce.created_by
SET ce.created_by_username_snapshot = creator.username
WHERE ce.created_by_username_snapshot IS NULL;

-- Preserve each existing free-text Chron log as one legacy entry. The engagement
-- update timestamp is the closest available historical timestamp.
INSERT INTO engagement_chron_entries (
    engagement_id,
    entry_text,
    legacy_engagement_note,
    created_at,
    updated_at
)
SELECT
    e.id,
    e.engagement_notes,
    1,
    COALESCE(e.updated_at, UTC_TIMESTAMP()),
    COALESCE(e.updated_at, UTC_TIMESTAMP())
FROM engagements e
WHERE TRIM(COALESCE(e.engagement_notes, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM engagement_chron_entries ce
      WHERE ce.engagement_id = e.id
        AND ce.legacy_engagement_note = 1
  );

DROP TRIGGER IF EXISTS audit_engagement_chron_entries_after_insert;
DROP TRIGGER IF EXISTS audit_engagement_chron_entries_after_update;
DROP TRIGGER IF EXISTS audit_engagement_chron_entries_after_delete;

CREATE TRIGGER audit_engagement_chron_entries_after_insert
AFTER INSERT ON engagement_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'engagement_chron_entries', NEW.id, CONCAT('Engagement ', NEW.engagement_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_chron_entries_after_update
AFTER UPDATE ON engagement_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'engagement_chron_entries', NEW.id, CONCAT('Engagement ', NEW.engagement_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_chron_entries_after_delete
AFTER DELETE ON engagement_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'engagement_chron_entries', OLD.id, CONCAT('Engagement ', OLD.engagement_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));
