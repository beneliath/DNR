-- A person may hold a different role at each organization. The original primary
-- organization and role fields remain compatible with existing integrations.
CREATE TABLE contact_organizations (
    contact_id INT NOT NULL,
    organization_id INT NOT NULL,
    role_title VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (contact_id, organization_id),
    INDEX idx_contact_organizations_organization (organization_id, contact_id),
    CONSTRAINT fk_contact_organization_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_organization_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);

-- Include archived contacts/organizations so restoring a record retains its links.
INSERT INTO contact_organizations (contact_id, organization_id, role_title)
SELECT id, organization_id,
       CASE contact_role
           WHEN 'other' THEN COALESCE(contact_role_other, '')
           WHEN 'pastor' THEN 'Pastor'
           WHEN 'admin' THEN 'Admin'
           ELSE ''
       END
FROM contacts WHERE organization_id IS NOT NULL;

DELIMITER $$
CREATE TRIGGER sync_contact_primary_organization_after_insert
AFTER INSERT ON contacts
FOR EACH ROW
BEGIN
    -- Native database restore disables FK checks and restores relationship rows
    -- independently; do not generate extra rows or change stored timestamps there.
    IF @@foreign_key_checks = 1 AND NEW.organization_id IS NOT NULL THEN
        INSERT INTO contact_organizations (contact_id, organization_id, role_title)
        VALUES (NEW.id, NEW.organization_id,
            CASE NEW.contact_role
                WHEN 'other' THEN COALESCE(NEW.contact_role_other, '')
                WHEN 'pastor' THEN 'Pastor'
                WHEN 'admin' THEN 'Admin'
                ELSE ''
            END);
    END IF;
END$$

CREATE TRIGGER sync_contact_primary_organization_after_update
AFTER UPDATE ON contacts
FOR EACH ROW
BEGIN
    IF @@foreign_key_checks = 1 AND NEW.organization_id IS NOT NULL
       AND (NOT (OLD.organization_id <=> NEW.organization_id)
            OR NOT (OLD.contact_role <=> NEW.contact_role)
            OR NOT (OLD.contact_role_other <=> NEW.contact_role_other)) THEN
        -- Keep the prior organization until a complete affiliation form explicitly
        -- removes it. Changing the primary designation must not discard event work.
        INSERT INTO contact_organizations (contact_id, organization_id, role_title)
        VALUES (NEW.id, NEW.organization_id,
            CASE NEW.contact_role
                WHEN 'other' THEN COALESCE(NEW.contact_role_other, '')
                WHEN 'pastor' THEN 'Pastor'
                WHEN 'admin' THEN 'Admin'
                ELSE ''
            END)
        ON DUPLICATE KEY UPDATE role_title =
            CASE NEW.contact_role
                WHEN 'other' THEN COALESCE(NEW.contact_role_other, '')
                WHEN 'pastor' THEN 'Pastor'
                WHEN 'admin' THEN 'Admin'
                ELSE ''
            END;
    END IF;
END$$

CREATE TRIGGER validate_engagement_contact_organization_before_insert
BEFORE INSERT ON engagement_contacts
FOR EACH ROW
BEGIN
    IF @@foreign_key_checks = 1 AND NOT EXISTS (
        SELECT 1 FROM engagements e
        INNER JOIN contact_organizations co ON co.organization_id = e.organization_id
        WHERE e.id = NEW.engagement_id AND co.contact_id = NEW.contact_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Event contacts must be associated with the event organization';
    END IF;
END$$

CREATE TRIGGER validate_engagement_contact_organization_before_update
BEFORE UPDATE ON engagement_contacts
FOR EACH ROW
BEGIN
    IF @@foreign_key_checks = 1 AND NOT EXISTS (
        SELECT 1 FROM engagements e
        INNER JOIN contact_organizations co ON co.organization_id = e.organization_id
        WHERE e.id = NEW.engagement_id AND co.contact_id = NEW.contact_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Event contacts must be associated with the event organization';
    END IF;
END$$

CREATE TRIGGER prune_contact_organization_events_after_delete
AFTER DELETE ON contact_organizations
FOR EACH ROW
BEGIN
    IF @@foreign_key_checks = 1 THEN
        UPDATE engagements e
        INNER JOIN engagement_contacts ec ON ec.engagement_id = e.id
        SET e.updated_at = CURRENT_TIMESTAMP(6)
        WHERE ec.contact_id = OLD.contact_id AND e.organization_id = OLD.organization_id;

        DELETE ec FROM engagement_contacts ec
        INNER JOIN engagements e ON e.id = ec.engagement_id
        WHERE ec.contact_id = OLD.contact_id AND e.organization_id = OLD.organization_id;
    END IF;
END$$

CREATE TRIGGER prune_engagement_organization_contacts_after_update
AFTER UPDATE ON engagements
FOR EACH ROW
BEGIN
    IF @@foreign_key_checks = 1 AND NOT (OLD.organization_id <=> NEW.organization_id) THEN
        DELETE ec FROM engagement_contacts ec
        LEFT JOIN contact_organizations co ON co.contact_id = ec.contact_id
            AND co.organization_id = NEW.organization_id
        WHERE ec.engagement_id = NEW.id AND co.contact_id IS NULL;
    END IF;
END$$
DELIMITER ;

CREATE TRIGGER audit_contact_organizations_after_insert
AFTER INSERT ON contact_organizations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_insert', 'contact_organizations', NEW.contact_id,
     LEFT(CONCAT('Contact ', NEW.contact_id, ' · Organization ', NEW.organization_id, ' · ', NEW.role_title), 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contact_organizations_after_update
AFTER UPDATE ON contact_organizations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_update', 'contact_organizations', NEW.contact_id,
     LEFT(CONCAT('Contact ', NEW.contact_id, ' · Organization ', NEW.organization_id, ' · ', NEW.role_title), 255),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contact_organizations_after_delete
AFTER DELETE ON contact_organizations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_delete', 'contact_organizations', OLD.contact_id,
     LEFT(CONCAT('Contact ', OLD.contact_id, ' · Organization ', OLD.organization_id, ' · ', OLD.role_title), 255),
     LEFT(@dnr_request_ip, 45));
