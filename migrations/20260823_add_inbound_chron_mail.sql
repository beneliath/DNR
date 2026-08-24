-- Capture email sent or copied to the MOED mailbox and route it into Contact
-- and Organization Chron logs. Parsed source data is retained independently
-- so mailbox retries are idempotent and ambiguous messages can be reviewed.
CREATE TABLE inbound_email_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transport ENUM('imap', 'webhook', 'file') NOT NULL DEFAULT 'imap',
    transport_key VARCHAR(255) NOT NULL,
    deduplication_hash BINARY(32) NOT NULL,
    rfc_message_id VARCHAR(998) NULL,
    gateway_address VARCHAR(254) NOT NULL,
    sender_name VARCHAR(255) NULL,
    sender_address VARCHAR(254) NOT NULL,
    to_addresses JSON NOT NULL,
    cc_addresses JSON NOT NULL,
    subject VARCHAR(998) NOT NULL DEFAULT '',
    sent_at DATETIME NULL,
    received_at DATETIME NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    attachment_names JSON NOT NULL,
    raw_headers MEDIUMTEXT NOT NULL,
    routing_details JSON NULL,
    status ENUM('pending', 'processing', 'processed', 'review', 'rejected', 'failed')
        NOT NULL DEFAULT 'pending',
    review_reason VARCHAR(255) NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    processing_started_at DATETIME NULL,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(255) NULL,
    processed_by INT NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inbound_email_processed_by
        FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE INDEX uq_inbound_email_transport_key (transport, transport_key),
    UNIQUE INDEX uq_inbound_email_deduplication (deduplication_hash),
    INDEX idx_inbound_email_queue (status, next_attempt_at, processing_started_at, id),
    INDEX idx_inbound_email_review (status, received_at, id),
    INDEX idx_inbound_email_sender (sender_address, received_at, id)
);

ALTER TABLE contact_chron_entries
    ADD COLUMN inbound_email_message_id BIGINT UNSIGNED NULL AFTER contact_id,
    ADD CONSTRAINT fk_contact_chron_inbound_email
        FOREIGN KEY (inbound_email_message_id)
        REFERENCES inbound_email_messages(id) ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_contact_chron_inbound_email (
        inbound_email_message_id, contact_id
    );

ALTER TABLE organization_chron_entries
    ADD COLUMN inbound_email_message_id BIGINT UNSIGNED NULL AFTER organization_id,
    ADD CONSTRAINT fk_organization_chron_inbound_email
        FOREIGN KEY (inbound_email_message_id)
        REFERENCES inbound_email_messages(id) ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_organization_chron_inbound_email (
        inbound_email_message_id, organization_id
    );

DROP TRIGGER IF EXISTS audit_inbound_email_messages_after_insert;
DROP TRIGGER IF EXISTS audit_inbound_email_messages_after_update;
DROP TRIGGER IF EXISTS audit_inbound_email_messages_after_delete;

CREATE TRIGGER audit_inbound_email_messages_after_insert
AFTER INSERT ON inbound_email_messages
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_insert', 'inbound_email_messages', NEW.id,
     LEFT(NULLIF(NEW.subject, ''), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_inbound_email_messages_after_update
AFTER UPDATE ON inbound_email_messages
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_update', 'inbound_email_messages', NEW.id,
     LEFT(NULLIF(NEW.subject, ''), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_inbound_email_messages_after_delete
AFTER DELETE ON inbound_email_messages
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_delete', 'inbound_email_messages', OLD.id,
     LEFT(NULLIF(OLD.subject, ''), 255), LEFT(@dnr_request_ip, 45));
