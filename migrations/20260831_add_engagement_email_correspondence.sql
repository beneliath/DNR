-- Send engagement correspondence through the existing isolated SMTP worker,
-- retain a reviewable source record, and link the outgoing message into every
-- relevant Chron log. One logical message has one independently retryable
-- delivery per recipient so addresses are never exposed to other contacts.

CREATE TABLE engagement_email_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    organization_id INT NOT NULL,
    template_key VARCHAR(50) NOT NULL DEFAULT 'custom',
    subject VARCHAR(255) NOT NULL,
    body_text MEDIUMTEXT NOT NULL,
    reply_to VARCHAR(254) NULL,
    included_event_brief TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_by_username_snapshot VARCHAR(50) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_engagement_email_message_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_engagement_email_message_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_engagement_email_message_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_engagement_email_message_brief
        CHECK (included_event_brief IN (0, 1)),
    INDEX idx_engagement_email_message_engagement_created (
        engagement_id, created_at, id
    )
);

CREATE TABLE engagement_email_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    contact_id INT NULL,
    recipient_name VARCHAR(255) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_roles_json JSON NOT NULL,
    payload_ciphertext MEDIUMTEXT NULL,
    status ENUM('pending', 'processing', 'retry', 'sent', 'failed')
        NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_started_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_engagement_email_delivery_message
        FOREIGN KEY (message_id) REFERENCES engagement_email_messages(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_engagement_email_delivery_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    UNIQUE INDEX uq_engagement_email_delivery_contact (message_id, contact_id),
    INDEX idx_engagement_email_delivery_ready (
        status, next_attempt_at, attempts, id
    ),
    INDEX idx_engagement_email_delivery_message_status (message_id, status, id)
);

ALTER TABLE engagement_chron_entries
    ADD COLUMN outbound_email_message_id BIGINT UNSIGNED NULL
        AFTER inbound_email_message_id,
    ADD CONSTRAINT fk_engagement_chron_outbound_email
        FOREIGN KEY (outbound_email_message_id)
        REFERENCES engagement_email_messages(id) ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_engagement_chron_outbound_email (
        outbound_email_message_id, engagement_id
    );

ALTER TABLE contact_chron_entries
    ADD COLUMN outbound_email_message_id BIGINT UNSIGNED NULL
        AFTER inbound_email_message_id,
    ADD CONSTRAINT fk_contact_chron_outbound_email
        FOREIGN KEY (outbound_email_message_id)
        REFERENCES engagement_email_messages(id) ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_contact_chron_outbound_email (
        outbound_email_message_id, contact_id
    );

ALTER TABLE organization_chron_entries
    ADD COLUMN outbound_email_message_id BIGINT UNSIGNED NULL
        AFTER inbound_email_message_id,
    ADD CONSTRAINT fk_organization_chron_outbound_email
        FOREIGN KEY (outbound_email_message_id)
        REFERENCES engagement_email_messages(id) ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_organization_chron_outbound_email (
        outbound_email_message_id, organization_id
    );

DROP TRIGGER IF EXISTS audit_engagement_email_messages_after_insert;

CREATE TRIGGER audit_engagement_email_messages_after_insert
AFTER INSERT ON engagement_email_messages
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50),
     'database_change', 'database_insert', 'engagement_email_messages',
     NEW.id, LEFT(CONCAT('Engagement ', NEW.engagement_id, ' outbound email'), 255),
     LEFT(@dnr_request_ip, 45));
