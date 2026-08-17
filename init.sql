-- Users table (for authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('admin', 'editor', 'reviewer') NOT NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_secret_encrypted TEXT NULL,
    totp_confirmed_at DATETIME NULL,
    totp_last_used_step BIGINT UNSIGNED NULL,
    login_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    login_locked_until DATETIME NULL,
    two_factor_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    two_factor_locked_until DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recovery_code_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_recovery_codes_user_unused (user_id, used_at)
);

CREATE TABLE IF NOT EXISTS security_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_username VARCHAR(50) NULL,
    target_user_id INT NULL,
    target_username VARCHAR(50) NULL,
    event_category VARCHAR(30) NOT NULL DEFAULT 'security',
    event_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id BIGINT UNSIGNED NULL,
    entity_label VARCHAR(255) NULL,
    details VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_security_audit_target
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_security_audit_target_created (target_user_id, created_at),
    INDEX idx_security_audit_created (created_at, id),
    INDEX idx_security_audit_category_created (event_category, created_at, id),
    INDEX idx_security_audit_actor_created (actor_user_id, created_at, id),
    INDEX idx_security_audit_entity_created (entity_type, entity_id, created_at, id)
);

-- Create the first administrator after startup with:
-- docker compose exec web php /opt/dnr/bin/create_admin.php admin

-- Organizations table
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_name VARCHAR(255) NOT NULL UNIQUE,
    notes TEXT,
    affiliation VARCHAR(255),
    distinctives VARCHAR(255),
    website_url VARCHAR(255),
    phone VARCHAR(50),
    fax VARCHAR(50),
    email VARCHAR(100),
    mailing_address_line_1 VARCHAR(255),
    mailing_address_line_2 VARCHAR(255),
    mailing_city VARCHAR(100),
    mailing_state VARCHAR(100),
    mailing_zipcode VARCHAR(20),
    mailing_country VARCHAR(100),
    physical_address_line_1 VARCHAR(255),
    physical_address_line_2 VARCHAR(255),
    physical_city VARCHAR(100),
    physical_state VARCHAR(100),
    physical_zipcode VARCHAR(20),
    physical_country VARCHAR(100),
    is_deleted TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Engagements table (sample fields; extend as needed)
CREATE TABLE IF NOT EXISTS engagements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    event_title VARCHAR(255),
    engagement_notes TEXT,
    event_start_date DATE NOT NULL,
    event_end_date DATE,
    event_type VARCHAR(50),
    event_type_other VARCHAR(50),
    book_table TINYINT(1) DEFAULT 0,
    brochures TINYINT(1) DEFAULT 0,
    caller_name VARCHAR(255),
    confirmation_status VARCHAR(50),
    event_address_line_1 VARCHAR(255),
    event_address_line_2 VARCHAR(255),
    event_city VARCHAR(100),
    event_state VARCHAR(100),
    event_zipcode VARCHAR(20),
    event_country VARCHAR(100),
    travel_covered ENUM('unknown', 'yes', 'no') DEFAULT 'unknown',
    travel_amount DECIMAL(10,2) DEFAULT NULL,
    compensation_type ENUM('Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other') DEFAULT 'Unknown',
    other_compensation TEXT,
    housing_type ENUM('Unknown', 'Provided', 'Not Provided', 'Other') DEFAULT 'Unknown',
    other_housing TEXT,
    housing_amount DECIMAL(10,2) DEFAULT NULL,
    is_deleted TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id)
);

-- Timestamped Chron log entries for engagements. engagement_notes remains on
-- engagements only for upgrade compatibility with pre-entry installations.
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

-- Presentations table
CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    topic_title VARCHAR(255) NOT NULL,
    presentation_date DATE NULL,
    presentation_time VARCHAR(8) NULL,
    speaker_name VARCHAR(255) NOT NULL,
    expected_attendance INT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_presentation_engagement_active_schedule (
        engagement_id, is_archived, presentation_date, presentation_time, id
    )
);

-- Contacts table
CREATE TABLE IF NOT EXISTS contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    contact_first_name VARCHAR(255) NOT NULL,
    contact_last_name VARCHAR(255) NOT NULL,
    contact_role ENUM('pastor', 'admin', 'other') NOT NULL,
    contact_role_other VARCHAR(255),
    contact_email VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(50),
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_contacts_last_first (contact_last_name, contact_first_name),
    INDEX idx_contacts_archived (is_deleted)
);

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

CREATE TRIGGER audit_engagement_chron_entries_after_insert AFTER INSERT ON engagement_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'engagement_chron_entries', NEW.id, CONCAT('Engagement ', NEW.engagement_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_chron_entries_after_update AFTER UPDATE ON engagement_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'engagement_chron_entries', NEW.id, CONCAT('Engagement ', NEW.engagement_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_chron_entries_after_delete AFTER DELETE ON engagement_chron_entries
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'engagement_chron_entries', OLD.id, CONCAT('Engagement ', OLD.engagement_id, ' Chron entry'), LEFT(@dnr_request_ip, 45));

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
