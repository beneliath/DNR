CREATE TABLE email_message_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    subject_template VARCHAR(255) NOT NULL,
    body_template MEDIUMTEXT NOT NULL,
    suggested_roles_json JSON NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT NULL,
    archived_by INT NULL,
    archived_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_email_template_key (template_key),
    INDEX idx_email_template_list (is_archived, sort_order, name, id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_email_template_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_email_template_archive CHECK (
        (is_archived = 0 AND archived_at IS NULL)
        OR (is_archived = 1 AND archived_at IS NOT NULL)
    )
);

INSERT INTO email_message_templates
    (template_key, name, subject_template, body_template, suggested_roles_json, sort_order)
VALUES
    ('booking_confirmation', 'Booking confirmation', 'Confirmation: {{event_name}}',
     'Hello,\n\nThis message confirms {{event_name}} with {{organization_name}} on {{event_dates}}. Please reply with any corrections or outstanding details.\n\nThank you,',
     '["primary_host"]', 10),
    ('travel_lodging', 'Travel and lodging request', 'Travel and lodging details: {{event_name}}',
     'Hello,\n\nWe are preparing travel and lodging for {{event_name}} on {{event_dates}}. Please send the confirmed transportation, lodging, arrival, and local-contact details when available.\n\nThank you,',
     '["primary_host", "travel"]', 20),
    ('final_reconfirmation', 'Final-detail reconfirmation', 'Final details: {{event_name}}',
     'Hello,\n\nWe are reconfirming the final details for {{event_name}} on {{event_dates}}. Please review the schedule, venue, on-site contact, travel, lodging, and materials arrangements and reply with any changes.\n\nThank you,',
     '["primary_host", "on_site_contact"]', 30),
    ('presentation_schedule', 'Presentation schedule', 'Presentation schedule: {{event_name}}',
     'Hello,\n\nHere is the current presentation schedule for {{event_name}}:\n\n{{presentation_schedule}}\n\nPlease reply with any corrections.\n\nThank you,',
     '["primary_host", "on_site_contact", "materials"]', 40),
    ('post_event_thanks', 'Post-event thank-you', 'Thank you: {{event_name}}',
     'Hello,\n\nThank you for hosting and supporting {{event_name}}. We appreciate the time, preparation, and hospitality that made the engagement possible.\n\nWith gratitude,',
     '["primary_host", "on_site_contact"]', 50);

-- Keep the original template name with sent correspondence, even after deletion.
ALTER TABLE engagement_email_messages ADD COLUMN template_label VARCHAR(100) NULL AFTER template_key;
UPDATE engagement_email_messages message
LEFT JOIN email_message_templates template ON template.template_key = message.template_key
SET message.template_label = COALESCE(template.name, 'Custom message')
WHERE message.engagement_id IS NOT NULL;

CREATE TRIGGER audit_email_templates_after_insert AFTER INSERT ON email_message_templates
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert',
     'email_message_templates', NEW.id, LEFT(NEW.name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_email_templates_after_update AFTER UPDATE ON email_message_templates
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update',
     'email_message_templates', NEW.id, LEFT(NEW.name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_email_templates_after_delete AFTER DELETE ON email_message_templates
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete',
     'email_message_templates', OLD.id, LEFT(OLD.name, 255), LEFT(@dnr_request_ip, 45));
