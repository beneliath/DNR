-- Assign only the relevant organization contacts to each engagement. A contact
-- may serve in several operational roles for the same event.
CREATE TABLE engagement_contacts (
    engagement_id INT NOT NULL,
    contact_id INT NOT NULL,
    contact_role ENUM(
        'primary_host',
        'on_site_contact',
        'billing',
        'travel',
        'materials'
    ) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (engagement_id, contact_id, contact_role),
    INDEX idx_engagement_contacts_contact (contact_id, engagement_id),
    INDEX idx_engagement_contacts_role (engagement_id, contact_role, contact_id),
    CONSTRAINT fk_engagement_contact_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_engagement_contact_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_engagement_contact_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

DROP TRIGGER IF EXISTS audit_engagement_contacts_after_insert;
DROP TRIGGER IF EXISTS audit_engagement_contacts_after_delete;

CREATE TRIGGER audit_engagement_contacts_after_insert
AFTER INSERT ON engagement_contacts
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_insert', 'engagement_contacts', NEW.engagement_id,
     LEFT(CONCAT('Contact ', NEW.contact_id, ' · ', NEW.contact_role), 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_contacts_after_delete
AFTER DELETE ON engagement_contacts
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_delete', 'engagement_contacts', OLD.engagement_id,
     LEFT(CONCAT('Contact ', OLD.contact_id, ' · ', OLD.contact_role), 255),
     LEFT(@dnr_request_ip, 45));
