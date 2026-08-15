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
    target_user_id INT NULL,
    event_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_security_audit_target
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_security_audit_target_created (target_user_id, created_at)
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

-- Presentations table
CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    topic_title VARCHAR(255) NOT NULL,
    presentation_date DATE NULL,
    presentation_time VARCHAR(8) NULL,
    speaker_name VARCHAR(255) NOT NULL,
    expected_attendance INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE
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
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_contacts_last_first (contact_last_name, contact_first_name)
);
