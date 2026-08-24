-- Independent communication histories for contacts and organizations.
CREATE TABLE contact_chron_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id INT NOT NULL,
    entry_text MEDIUMTEXT NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_by_username_snapshot VARCHAR(50) NULL,
    updated_by INT NULL,
    archived_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_contact_chron_entry_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_chron_entry_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_chron_entry_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_chron_entry_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_contact_chron_entry_active_created (
        contact_id, is_archived, created_at, id
    ),
    FULLTEXT INDEX ft_contact_chron_entries_search (
        entry_text, created_by_username_snapshot
    )
);

CREATE TABLE organization_chron_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    entry_text MEDIUMTEXT NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_by_username_snapshot VARCHAR(50) NULL,
    updated_by INT NULL,
    archived_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_organization_chron_entry_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_organization_chron_entry_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_organization_chron_entry_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_organization_chron_entry_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_organization_chron_entry_active_created (
        organization_id, is_archived, created_at, id
    ),
    FULLTEXT INDEX ft_organization_chron_entries_search (
        entry_text, created_by_username_snapshot
    )
);

CREATE TRIGGER audit_contact_chron_entries_after_insert
AFTER INSERT ON contact_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'contact_chron_entries', NEW.id, CONCAT('Contact ', NEW.contact_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contact_chron_entries_after_update
AFTER UPDATE ON contact_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'contact_chron_entries', NEW.id, CONCAT('Contact ', NEW.contact_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_contact_chron_entries_after_delete
AFTER DELETE ON contact_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'contact_chron_entries', OLD.id, CONCAT('Contact ', OLD.contact_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_organization_chron_entries_after_insert
AFTER INSERT ON organization_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'organization_chron_entries', NEW.id, CONCAT('Organization ', NEW.organization_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_organization_chron_entries_after_update
AFTER UPDATE ON organization_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'organization_chron_entries', NEW.id, CONCAT('Organization ', NEW.organization_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_organization_chron_entries_after_delete
AFTER DELETE ON organization_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'organization_chron_entries', OLD.id, CONCAT('Organization ', OLD.organization_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));
