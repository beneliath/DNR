CREATE TABLE speakers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(16) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_speakers_name (name, id),
    CONSTRAINT chk_speaker_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_speaker_email CHECK (CHAR_LENGTH(TRIM(email)) > 0),
    CONSTRAINT chk_speaker_phone CHECK (CHAR_LENGTH(TRIM(phone)) > 0)
);

INSERT INTO speakers (name, email, phone)
VALUES ('Olivier Melnick', 'olivier@shalominmessiah.com', '+19494002892');
SET @initial_speaker_id = LAST_INSERT_ID();

ALTER TABLE presentations ADD COLUMN speaker_id INT NULL AFTER speaker_name;
-- Deliberately includes archived presentations and presentations on deleted events.
UPDATE presentations SET speaker_id = @initial_speaker_id;
ALTER TABLE presentations
    MODIFY COLUMN speaker_id INT NOT NULL,
    ADD CONSTRAINT fk_presentation_speaker FOREIGN KEY (speaker_id)
        REFERENCES speakers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    DROP COLUMN speaker_name;

-- The application receives no DELETE privilege on speakers. The restricted FK
-- also protects references; the existing offline restore can still replace data.

CREATE TRIGGER audit_speakers_after_insert AFTER INSERT ON speakers
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'speakers', NEW.id, LEFT(NEW.name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_speakers_after_update AFTER UPDATE ON speakers
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'speakers', NEW.id, LEFT(NEW.name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER calendar_speakers_after_update AFTER UPDATE ON speakers
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
