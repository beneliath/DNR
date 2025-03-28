-- Users table (for authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'editor', 'reviewer') NOT NULL
);

-- Organizations table
CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_name VARCHAR(255) NOT NULL,
    notes TEXT,
    affiliation VARCHAR(255),
    distinctives VARCHAR(255),
    website_url VARCHAR(255),
    phone VARCHAR(50),
    fax VARCHAR(50),
    email VARCHAR(100),
    mailing_address VARCHAR(255),
    physical_address VARCHAR(255)
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
    FOREIGN KEY (organization_id) REFERENCES organizations(id)
);

-- Presentations table
CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    topic VARCHAR(255),
    presentation_date DATE,
    presentation_time TIME,
    speaker_name VARCHAR(255),
    expected_attendance INT,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id)
);

