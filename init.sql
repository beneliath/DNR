-- Users table (for authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'editor', 'reviewer') NOT NULL
);

-- for production (default admin account)
-- Insert default admin account with hashed password (p@55word)
INSERT INTO users (username, password, role) 
VALUES ('admin', '$2y$12$wTYbXn3kB2NAKPhZdVBniuzRdPySg8k3v67l4dxLCh7t3kGpifYI.', 'admin')
ON DUPLICATE KEY UPDATE username=username;

-- ! REMOVE BLOCK BELOW THIS LINE ON FINAL BUILD ! =======

-- for development (editor and reviewer accounts)
-- we will want to see what things look like from the perspective
-- of and editor and a reviewer ...

-- Insert default editor account with hashed password (p@55word)
INSERT INTO users (username, password, role) 
VALUES ('editor', '$2y$12$wTYbXn3kB2NAKPhZdVBniuzRdPySg8k3v67l4dxLCh7t3kGpifYI.', 'editor')
ON DUPLICATE KEY UPDATE username=username;

-- Insert default reviewer account with hashed password (p@55word)
INSERT INTO users (username, password, role) 
VALUES ('reviewer', '$2y$12$wTYbXn3kB2NAKPhZdVBniuzRdPySg8k3v67l4dxLCh7t3kGpifYI.', 'reviewer')
ON DUPLICATE KEY UPDATE username=username;

-- ! REMOVE BLOCK ABOVE THIS LINE ON FINAL BUILD ! =======

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
    physical_country VARCHAR(100)
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
    contact_name VARCHAR(255) NOT NULL,
    contact_role ENUM('pastor', 'admin', 'other') NOT NULL,
    contact_role_other VARCHAR(255),
    contact_email VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(50),
    FOREIGN KEY (organization_id) REFERENCES organizations(id)
);

