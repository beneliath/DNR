ALTER TABLE security_audit_log
    ADD COLUMN actor_username VARCHAR(50) NULL AFTER actor_user_id,
    ADD COLUMN target_username VARCHAR(50) NULL AFTER target_user_id,
    ADD COLUMN event_category VARCHAR(30) NOT NULL DEFAULT 'security' AFTER target_username,
    ADD COLUMN entity_type VARCHAR(50) NULL AFTER event_type,
    ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER entity_type,
    ADD COLUMN entity_label VARCHAR(255) NULL AFTER entity_id,
    ADD COLUMN details VARCHAR(255) NULL AFTER entity_label,
    ADD INDEX idx_security_audit_created (created_at, id),
    ADD INDEX idx_security_audit_category_created (event_category, created_at, id),
    ADD INDEX idx_security_audit_actor_created (actor_user_id, created_at, id),
    ADD INDEX idx_security_audit_entity_created (entity_type, entity_id, created_at, id);

UPDATE security_audit_log AS audit_entry
LEFT JOIN users AS actor ON actor.id = audit_entry.actor_user_id
LEFT JOIN users AS target ON target.id = audit_entry.target_user_id
SET audit_entry.actor_username = actor.username,
    audit_entry.target_username = target.username
WHERE audit_entry.actor_username IS NULL
   OR audit_entry.target_username IS NULL;

DROP TRIGGER IF EXISTS audit_users_after_insert;
DROP TRIGGER IF EXISTS audit_users_after_update;
DROP TRIGGER IF EXISTS audit_users_after_delete;
DROP TRIGGER IF EXISTS audit_organizations_after_insert;
DROP TRIGGER IF EXISTS audit_organizations_after_update;
DROP TRIGGER IF EXISTS audit_organizations_after_delete;
DROP TRIGGER IF EXISTS audit_contacts_after_insert;
DROP TRIGGER IF EXISTS audit_contacts_after_update;
DROP TRIGGER IF EXISTS audit_contacts_after_delete;
DROP TRIGGER IF EXISTS audit_engagements_after_insert;
DROP TRIGGER IF EXISTS audit_engagements_after_update;
DROP TRIGGER IF EXISTS audit_engagements_after_delete;
DROP TRIGGER IF EXISTS audit_presentations_after_insert;
DROP TRIGGER IF EXISTS audit_presentations_after_update;
DROP TRIGGER IF EXISTS audit_presentations_after_delete;

CREATE TRIGGER audit_users_after_insert AFTER INSERT ON users
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'users', NEW.id, LEFT(NEW.username, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_users_after_update AFTER UPDATE ON users
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'users', NEW.id, LEFT(NEW.username, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_users_after_delete AFTER DELETE ON users
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'users', OLD.id, LEFT(OLD.username, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_organizations_after_insert AFTER INSERT ON organizations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'organizations', NEW.id, LEFT(NEW.organization_name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_organizations_after_update AFTER UPDATE ON organizations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'organizations', NEW.id, LEFT(NEW.organization_name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_organizations_after_delete AFTER DELETE ON organizations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'organizations', OLD.id, LEFT(OLD.organization_name, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contacts_after_insert AFTER INSERT ON contacts
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'contacts', NEW.id, LEFT(TRIM(CONCAT_WS(' ', NEW.contact_first_name, NEW.contact_last_name)), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contacts_after_update AFTER UPDATE ON contacts
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'contacts', NEW.id, LEFT(TRIM(CONCAT_WS(' ', NEW.contact_first_name, NEW.contact_last_name)), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contacts_after_delete AFTER DELETE ON contacts
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'contacts', OLD.id, LEFT(TRIM(CONCAT_WS(' ', OLD.contact_first_name, OLD.contact_last_name)), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagements_after_insert AFTER INSERT ON engagements
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'engagements', NEW.id, LEFT(NULLIF(NEW.event_title, ''), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagements_after_update AFTER UPDATE ON engagements
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'engagements', NEW.id, LEFT(NULLIF(NEW.event_title, ''), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagements_after_delete AFTER DELETE ON engagements
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'engagements', OLD.id, LEFT(NULLIF(OLD.event_title, ''), 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_presentations_after_insert AFTER INSERT ON presentations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'presentations', NEW.id, LEFT(NEW.topic_title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_presentations_after_update AFTER UPDATE ON presentations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'presentations', NEW.id, LEFT(NEW.topic_title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_presentations_after_delete AFTER DELETE ON presentations
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'presentations', OLD.id, LEFT(OLD.topic_title, 255), LEFT(@dnr_request_ip, 45));
