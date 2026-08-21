CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(255) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Users table (for authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(254) NULL,
    profile_picture MEDIUMBLOB NULL,
    profile_picture_mime VARCHAR(32) NULL,
    profile_picture_sha256 BINARY(32) NULL,
    profile_picture_updated_at DATETIME NULL,
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
    code_lookup_hash BINARY(32) NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recovery_code_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_recovery_code_user_lookup (user_id, code_lookup_hash),
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
    INDEX idx_security_audit_entity_created (entity_type, entity_id, created_at, id),
    INDEX idx_security_audit_login_ip_created (event_category, event_type, ip_address, created_at),
    FULLTEXT INDEX ft_security_audit_search (
        actor_username, target_username, event_category, event_type,
        entity_type, entity_label, details, ip_address
    )
);

CREATE TABLE IF NOT EXISTS authentication_rate_limits (
    key_hash BINARY(32) PRIMARY KEY,
    scope ENUM('login_ip', 'login_account', 'recovery_ip', 'recovery_account') NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_auth_rate_limits_updated (updated_at)
);

CREATE TABLE IF NOT EXISTS calendar_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Calendar subscription',
    token_hash BINARY(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    CONSTRAINT fk_calendar_subscription_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_calendar_subscription_token (token_hash),
    INDEX idx_calendar_subscription_user_active (user_id, revoked_at, created_at)
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_organizations_active_name (is_deleted, organization_name, id),
    FULLTEXT INDEX ft_organizations_search (
        organization_name, notes, affiliation, distinctives, email, phone,
        physical_city, physical_state, mailing_city, mailing_state
    )
);

-- Engagements table (sample fields; extend as needed)
CREATE TABLE IF NOT EXISTS engagements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    event_title VARCHAR(255),
    event_description TEXT,
    engagement_notes TEXT,
    event_start_date DATE NOT NULL,
    event_end_date DATE NOT NULL,
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
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    CONSTRAINT chk_engagement_date_range CHECK (event_end_date >= event_start_date),
    CONSTRAINT chk_engagement_travel_amount CHECK (travel_amount IS NULL OR travel_amount >= 0),
    CONSTRAINT chk_engagement_housing_amount CHECK (housing_amount IS NULL OR housing_amount >= 0),
    CONSTRAINT chk_engagement_other_type CHECK (
        event_type <> 'other' OR TRIM(COALESCE(event_type_other, '')) <> ''
    ),
    INDEX idx_engagements_active_date (is_deleted, event_start_date, id),
    INDEX idx_engagements_active_status_date (
        is_deleted, confirmation_status, event_start_date, id
    ),
    FULLTEXT INDEX ft_engagements_search (
        event_title, event_description, engagement_notes, caller_name
    )
);

-- Cached address lookups for the interactive engagement map. Keeping this
-- separate avoids changing an engagement's concurrency timestamp when a map
-- location is resolved, and lets events at the same address share a result.
CREATE TABLE IF NOT EXISTS engagement_map_geocodes (
    address_hash CHAR(64) PRIMARY KEY,
    address_query VARCHAR(1000) NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    lookup_status ENUM('found', 'not_found') NOT NULL,
    geocoded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_engagement_map_coordinates CHECK (
        (lookup_status = 'found' AND latitude IS NOT NULL AND longitude IS NOT NULL)
        OR (lookup_status = 'not_found' AND latitude IS NULL AND longitude IS NULL)
    )
);

CREATE TABLE IF NOT EXISTS engagement_map_geocode_queue (
    address_hash CHAR(64) PRIMARY KEY,
    address_query VARCHAR(1000) NOT NULL,
    status ENUM('pending', 'processing', 'retry') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geocode_queue_ready (status, next_attempt_at, attempts)
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
    ),
    FULLTEXT INDEX ft_chron_entries_search (entry_text, created_by_username_snapshot)
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
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_presentation_attendance CHECK (
        expected_attendance IS NULL OR expected_attendance >= 1
    ),
    CONSTRAINT chk_presentation_time_format CHECK (
        presentation_time IS NULL
        OR presentation_time REGEXP '^(0[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$'
    ),
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
    contact_notes TEXT NULL,
    contact_photo MEDIUMBLOB NULL,
    contact_photo_mime VARCHAR(32) NULL,
    contact_photo_sha256 BINARY(32) NULL,
    contact_photo_updated_at DATETIME NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_contacts_last_first (contact_last_name, contact_first_name),
    INDEX idx_contacts_archived (is_deleted),
    INDEX idx_contacts_active_name (
        is_deleted, contact_last_name, contact_first_name, id
    ),
    INDEX idx_contacts_organization_active_name (
        organization_id, is_deleted, contact_last_name, contact_first_name, id
    ),
    FULLTEXT INDEX ft_contacts_search (
        contact_first_name, contact_last_name, contact_email, contact_phone,
        contact_role_other, contact_notes
    )
);

-- Configurable standard work generated for engagements.
CREATE TABLE IF NOT EXISTS standard_event_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent')
        NOT NULL DEFAULT 'normal',
    due_anchor ENUM('event_start', 'event_end')
        NOT NULL DEFAULT 'event_start',
    due_offset_days SMALLINT NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_by INT NULL,
    archived_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_standard_event_task_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_standard_event_task_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_standard_event_task_archive CHECK (
        (is_archived = 0 AND archived_at IS NULL)
        OR (is_archived = 1 AND archived_at IS NOT NULL)
    ),
    UNIQUE KEY uq_standard_event_task_key (template_key),
    INDEX idx_standard_event_task_list (is_archived, sort_order, id)
);

INSERT IGNORE INTO standard_event_tasks
    (template_key, title, priority, due_anchor, due_offset_days, sort_order)
VALUES
    ('standard.confirm_location', 'Confirm the venue address and on-site contact', 'high', 'event_start', -30, 10),
    ('standard.confirm_travel_lodging', 'Confirm travel and lodging arrangements', 'high', 'event_start', -30, 20),
    ('standard.confirm_presentations', 'Confirm presentation topics, dates, and times', 'high', 'event_start', -21, 30),
    ('standard.confirm_materials', 'Confirm book table, brochures, and event materials', 'normal', 'event_start', -14, 40),
    ('standard.host_reconfirmation', 'Reconfirm final details with the host', 'high', 'event_start', -7, 50),
    ('standard.prepare_materials', 'Prepare presentations, handouts, books, and supplies', 'high', 'event_start', -3, 60),
    ('standard.send_thanks', 'Send a post-event thank-you', 'normal', 'event_end', 1, 70),
    ('standard.record_outcomes', 'Record attendance, feedback, and notable outcomes', 'normal', 'event_end', 2, 80),
    ('standard.financial_closeout', 'Complete financial and reimbursement follow-up', 'high', 'event_end', 7, 90);

-- Assignable follow-up work linked to an engagement, organization, contact,
-- or the application generally.
CREATE TABLE IF NOT EXISTS follow_up_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    status ENUM('open', 'in_progress', 'waiting', 'completed', 'canceled')
        NOT NULL DEFAULT 'open',
    priority ENUM('low', 'normal', 'high', 'urgent')
        NOT NULL DEFAULT 'normal',
    due_date DATE NULL,
    waiting_on VARCHAR(255) NULL,
    subject_type ENUM('general', 'engagement', 'organization', 'contact')
        NOT NULL DEFAULT 'general',
    engagement_id INT NULL,
    organization_id INT NULL,
    contact_id INT NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    completed_by INT NULL,
    template_key VARCHAR(100) NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_follow_up_task_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_task_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_task_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_task_assignee
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_follow_up_task_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_follow_up_task_completer
        FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_follow_up_task_subject CHECK (
        (subject_type = 'general'
            AND engagement_id IS NULL
            AND organization_id IS NULL
            AND contact_id IS NULL)
        OR (subject_type = 'engagement'
            AND engagement_id IS NOT NULL
            AND organization_id IS NULL
            AND contact_id IS NULL)
        OR (subject_type = 'organization'
            AND engagement_id IS NULL
            AND organization_id IS NOT NULL
            AND contact_id IS NULL)
        OR (subject_type = 'contact'
            AND engagement_id IS NULL
            AND organization_id IS NULL
            AND contact_id IS NOT NULL)
    ),
    CONSTRAINT chk_follow_up_task_completion CHECK (
        (status = 'completed' AND completed_at IS NOT NULL)
        OR (status <> 'completed' AND completed_at IS NULL)
    ),
    UNIQUE KEY uq_follow_up_task_engagement_template (engagement_id, template_key),
    INDEX idx_follow_up_task_queue (status, assigned_to, due_date, priority),
    INDEX idx_follow_up_task_engagement (engagement_id, status, due_date),
    INDEX idx_follow_up_task_organization (organization_id, status, due_date),
    INDEX idx_follow_up_task_contact (contact_id, status, due_date),
    INDEX idx_follow_up_task_updated (updated_at, id),
    FULLTEXT INDEX ft_follow_up_tasks_search (title, details, waiting_on)
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

CREATE TRIGGER audit_follow_up_tasks_after_insert AFTER INSERT ON follow_up_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'follow_up_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_follow_up_tasks_after_update AFTER UPDATE ON follow_up_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'follow_up_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_follow_up_tasks_after_delete AFTER DELETE ON follow_up_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'follow_up_tasks', OLD.id, LEFT(OLD.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_standard_event_tasks_after_insert AFTER INSERT ON standard_event_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'standard_event_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_standard_event_tasks_after_update AFTER UPDATE ON standard_event_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'standard_event_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_standard_event_tasks_after_delete AFTER DELETE ON standard_event_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'standard_event_tasks', OLD.id, LEFT(OLD.title, 255), LEFT(@dnr_request_ip, 45));

INSERT IGNORE INTO schema_migrations (migration_name, checksum) VALUES
('20260814_add_last_login_at.sql', REPEAT('0', 64)),
('20260814_add_must_change_password.sql', REPEAT('0', 64)),
('20260814_add_shared_calendar.sql', REPEAT('0', 64)),
('20260814_add_two_factor_authentication.sql', REPEAT('0', 64)),
('20260814_add_user_timestamps.sql', REPEAT('0', 64)),
('20260815_add_audit_log.sql', REPEAT('0', 64)),
('20260815_add_contact_archiving.sql', REPEAT('0', 64)),
('20260815_add_event_title.sql', REPEAT('0', 64)),
('20260815_split_contact_names.sql', REPEAT('0', 64)),
('20260817_add_engagement_chron_entries.sql', REPEAT('0', 64)),
('20260817_add_event_description.sql', REPEAT('0', 64)),
('20260817_add_presentation_archiving.sql', REPEAT('0', 64)),
('20260817_add_schema_migrations.sql', REPEAT('0', 64)),
('20260817_beta_readiness_hardening.sql', REPEAT('0', 64)),
('20260818_add_follow_up_tasks.sql', REPEAT('0', 64)),
('20260818_add_contact_notes.sql', REPEAT('0', 64)),
('20260818_add_contact_photos.sql', REPEAT('0', 64)),
('20260818_add_user_profiles.sql', REPEAT('0', 64)),
('20260818_standardize_phone_numbers.sql', REPEAT('0', 64)),
('20260821_add_standard_event_tasks.sql', REPEAT('0', 64)),
('20260821_security_performance_hardening.sql', REPEAT('0', 64));
