-- Add a first-class pre-engagement inquiry pipeline, including durable stage
-- history, Chron, task ownership, inbound provenance, outbound correspondence,
-- and an audited link to the engagement created at booking.

CREATE TABLE booking_inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    organization_id INT NULL,
    primary_contact_id INT NULL,
    request_summary MEDIUMTEXT NULL,
    event_type VARCHAR(50) NOT NULL DEFAULT 'conference',
    event_type_other VARCHAR(50) NULL,
    preferred_start_date DATE NULL,
    preferred_end_date DATE NULL,
    alternate_start_date DATE NULL,
    alternate_end_date DATE NULL,
    event_address_line_1 VARCHAR(255) NULL,
    event_address_line_2 VARCHAR(255) NULL,
    event_city VARCHAR(100) NULL,
    event_state VARCHAR(100) NULL,
    event_zipcode VARCHAR(20) NULL,
    event_country VARCHAR(100) NULL,
    source ENUM('email', 'phone', 'website', 'referral', 'mattermost', 'other')
        NOT NULL DEFAULT 'other',
    source_detail VARCHAR(255) NULL,
    inbound_email_message_id BIGINT UNSIGNED NULL,
    owner_user_id INT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent')
        NOT NULL DEFAULT 'normal',
    stage ENUM(
        'new', 'contacted', 'qualified', 'awaiting_details',
        'proposal_sent', 'booked', 'declined'
    ) NOT NULL DEFAULT 'new',
    next_action VARCHAR(255) NULL,
    next_action_due_date DATE NULL,
    decline_reason VARCHAR(1000) NULL,
    converted_engagement_id INT NULL,
    stage_changed_by INT NULL,
    stage_changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    converted_by INT NULL,
    converted_at DATETIME(6) NULL,
    created_by INT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_booking_inquiry_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_contact
        FOREIGN KEY (primary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_inbound_email
        FOREIGN KEY (inbound_email_message_id)
        REFERENCES inbound_email_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_owner
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_stage_actor
        FOREIGN KEY (stage_changed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_converter
        FOREIGN KEY (converted_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_engagement
        FOREIGN KEY (converted_engagement_id) REFERENCES engagements(id) ON DELETE SET NULL,
    CONSTRAINT chk_booking_inquiry_preferred_dates CHECK (
        preferred_start_date IS NULL OR preferred_end_date IS NULL
        OR preferred_end_date >= preferred_start_date
    ),
    CONSTRAINT chk_booking_inquiry_alternate_dates CHECK (
        alternate_start_date IS NULL OR alternate_end_date IS NULL
        OR alternate_end_date >= alternate_start_date
    ),
    CONSTRAINT chk_booking_inquiry_decline CHECK (
        (stage = 'declined' AND TRIM(COALESCE(decline_reason, '')) <> '')
        OR (stage <> 'declined' AND decline_reason IS NULL)
    ),
    CONSTRAINT chk_booking_inquiry_conversion CHECK (
        (stage = 'booked' AND converted_at IS NOT NULL)
        OR (stage <> 'booked' AND converted_at IS NULL)
    ),
    CONSTRAINT chk_booking_inquiry_type CHECK (
        event_type IN ('conference', 'service', 'study or teaching', 'Passover Seder', 'other')
        AND (event_type <> 'other' OR TRIM(COALESCE(event_type_other, '')) <> '')
    ),
    UNIQUE INDEX uq_booking_inquiry_inbound_email (inbound_email_message_id),
    UNIQUE INDEX uq_booking_inquiry_engagement (converted_engagement_id),
    INDEX idx_booking_inquiry_pipeline (stage, priority, next_action_due_date, id),
    INDEX idx_booking_inquiry_owner (owner_user_id, stage, next_action_due_date),
    INDEX idx_booking_inquiry_organization (organization_id, stage, id),
    INDEX idx_booking_inquiry_contact (primary_contact_id, stage, id),
    INDEX idx_booking_inquiry_preferred_dates (preferred_start_date, preferred_end_date),
    FULLTEXT INDEX ft_booking_inquiry_search (
        title, request_summary, source_detail, event_city, event_state
    )
);

CREATE TABLE booking_inquiry_stage_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_inquiry_id INT NOT NULL,
    from_stage ENUM(
        'new', 'contacted', 'qualified', 'awaiting_details',
        'proposal_sent', 'booked', 'declined'
    ) NULL,
    to_stage ENUM(
        'new', 'contacted', 'qualified', 'awaiting_details',
        'proposal_sent', 'booked', 'declined'
    ) NOT NULL,
    reason VARCHAR(1000) NULL,
    changed_by INT NULL,
    changed_by_username_snapshot VARCHAR(50) NULL,
    changed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_booking_inquiry_stage_history_inquiry
        FOREIGN KEY (booking_inquiry_id) REFERENCES booking_inquiries(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_inquiry_stage_history_user
        FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_booking_inquiry_stage_history (
        booking_inquiry_id, changed_at DESC, id DESC
    )
);

-- Reuse the isolated, retryable engagement delivery queue for inquiry email.
-- Exactly one parent type owns every retained outbound message.
ALTER TABLE engagement_email_messages
    MODIFY engagement_id INT NULL,
    MODIFY organization_id INT NULL,
    ADD COLUMN booking_inquiry_id INT NULL AFTER engagement_id,
    ADD CONSTRAINT fk_engagement_email_message_inquiry
        FOREIGN KEY (booking_inquiry_id) REFERENCES booking_inquiries(id) ON DELETE CASCADE,
    ADD CONSTRAINT chk_engagement_email_message_parent CHECK (
        (engagement_id IS NOT NULL AND booking_inquiry_id IS NULL)
        OR (engagement_id IS NULL AND booking_inquiry_id IS NOT NULL)
    ),
    ADD INDEX idx_engagement_email_message_inquiry_created (
        booking_inquiry_id, created_at, id
    );

CREATE TABLE booking_inquiry_chron_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_inquiry_id INT NOT NULL,
    inbound_email_message_id BIGINT UNSIGNED NULL,
    outbound_email_message_id BIGINT UNSIGNED NULL,
    entry_text MEDIUMTEXT NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_by_username_snapshot VARCHAR(50) NULL,
    updated_by INT NULL,
    archived_by INT NULL,
    archived_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_booking_inquiry_chron_inquiry
        FOREIGN KEY (booking_inquiry_id) REFERENCES booking_inquiries(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_inquiry_chron_inbound_email
        FOREIGN KEY (inbound_email_message_id)
        REFERENCES inbound_email_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_chron_outbound_email
        FOREIGN KEY (outbound_email_message_id)
        REFERENCES engagement_email_messages(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_chron_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_chron_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_inquiry_chron_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_booking_inquiry_chron_archive CHECK (
        (is_archived = 0 AND archived_at IS NULL)
        OR (is_archived = 1 AND archived_at IS NOT NULL)
    ),
    UNIQUE INDEX uq_booking_inquiry_chron_inbound (
        inbound_email_message_id, booking_inquiry_id
    ),
    UNIQUE INDEX uq_booking_inquiry_chron_outbound (
        outbound_email_message_id, booking_inquiry_id
    ),
    INDEX idx_booking_inquiry_chron_active (
        booking_inquiry_id, is_archived, created_at DESC, id DESC
    ),
    FULLTEXT INDEX ft_booking_inquiry_chron_search (entry_text)
);

ALTER TABLE follow_up_tasks
    DROP CHECK chk_follow_up_task_subject;

ALTER TABLE follow_up_tasks
    MODIFY subject_type ENUM(
        'general', 'engagement', 'organization', 'contact', 'inquiry'
    ) NOT NULL DEFAULT 'general',
    ADD COLUMN inquiry_id INT NULL AFTER contact_id,
    ADD CONSTRAINT fk_follow_up_task_inquiry
        FOREIGN KEY (inquiry_id) REFERENCES booking_inquiries(id) ON DELETE CASCADE,
    ADD INDEX idx_follow_up_task_inquiry (inquiry_id, status, due_date);

ALTER TABLE follow_up_tasks
    ADD CONSTRAINT chk_follow_up_task_subject CHECK (
        (subject_type = 'general'
            AND engagement_id IS NULL AND organization_id IS NULL
            AND contact_id IS NULL AND inquiry_id IS NULL)
        OR (subject_type = 'engagement'
            AND engagement_id IS NOT NULL AND organization_id IS NULL
            AND contact_id IS NULL AND inquiry_id IS NULL)
        OR (subject_type = 'organization'
            AND engagement_id IS NULL AND organization_id IS NOT NULL
            AND contact_id IS NULL AND inquiry_id IS NULL)
        OR (subject_type = 'contact'
            AND engagement_id IS NULL AND organization_id IS NULL
            AND contact_id IS NOT NULL AND inquiry_id IS NULL)
        OR (subject_type = 'inquiry'
            AND engagement_id IS NULL AND organization_id IS NULL
            AND contact_id IS NULL AND inquiry_id IS NOT NULL)
    );

DROP TRIGGER IF EXISTS audit_engagement_email_messages_after_insert;
CREATE TRIGGER audit_engagement_email_messages_after_insert
AFTER INSERT ON engagement_email_messages
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50),
     'database_change', 'database_insert', 'engagement_email_messages', NEW.id,
     LEFT(CASE
        WHEN NEW.booking_inquiry_id IS NOT NULL
            THEN CONCAT('Inquiry ', NEW.booking_inquiry_id, ' outbound email')
        ELSE CONCAT('Engagement ', NEW.engagement_id, ' outbound email')
     END, 255), LEFT(@dnr_request_ip, 45));

DROP TRIGGER IF EXISTS audit_booking_inquiries_after_insert;
DROP TRIGGER IF EXISTS audit_booking_inquiries_after_update;
DROP TRIGGER IF EXISTS audit_booking_inquiries_after_delete;
DROP TRIGGER IF EXISTS audit_booking_inquiry_chron_after_insert;
DROP TRIGGER IF EXISTS audit_booking_inquiry_chron_after_update;
DROP TRIGGER IF EXISTS audit_booking_inquiry_chron_after_delete;

CREATE TRIGGER audit_booking_inquiries_after_insert
AFTER INSERT ON booking_inquiries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_insert', 'booking_inquiries', NEW.id, LEFT(NEW.title, 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_booking_inquiries_after_update
AFTER UPDATE ON booking_inquiries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_update', 'booking_inquiries', NEW.id, LEFT(NEW.title, 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_booking_inquiries_after_delete
AFTER DELETE ON booking_inquiries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_delete', 'booking_inquiries', OLD.id, LEFT(OLD.title, 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_booking_inquiry_chron_after_insert
AFTER INSERT ON booking_inquiry_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_insert', 'booking_inquiry_chron_entries', NEW.id,
     LEFT(CONCAT('Inquiry ', NEW.booking_inquiry_id, ' Chron entry'), 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_booking_inquiry_chron_after_update
AFTER UPDATE ON booking_inquiry_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_update', 'booking_inquiry_chron_entries', NEW.id,
     LEFT(CONCAT('Inquiry ', NEW.booking_inquiry_id, ' Chron entry'), 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_booking_inquiry_chron_after_delete
AFTER DELETE ON booking_inquiry_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type,
     entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_delete', 'booking_inquiry_chron_entries', OLD.id,
     LEFT(CONCAT('Inquiry ', OLD.booking_inquiry_id, ' Chron entry'), 255),
     LEFT(@dnr_request_ip, 45));
