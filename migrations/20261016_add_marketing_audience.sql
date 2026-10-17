-- Marketing contacts are independent of relationship contacts: a CRM record is
-- not evidence of marketing consent. Each account uses its own database.
CREATE TABLE marketing_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    email_status ENUM('non-subscribed','subscribed','unsubscribed','pending','cleaned') NOT NULL DEFAULT 'non-subscribed',
    source VARCHAR(100) NOT NULL DEFAULT 'Manual entry',
    notes TEXT NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_marketing_contact_email (email),
    INDEX idx_marketing_contact_status (is_archived, email_status, id)
);

CREATE TABLE marketing_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_marketing_tag_name (name)
);

CREATE TABLE marketing_contact_tags (
    contact_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, tag_id),
    FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES marketing_tags(id) ON DELETE CASCADE
);

CREATE TABLE marketing_segments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    filters_json JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_marketing_segment_name (name)
);

-- Append-only history preserves the evidence for each subscription transition.
CREATE TABLE marketing_contact_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id INT UNSIGNED NOT NULL,
    actor_user_id INT NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    details_json JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE RESTRICT,
    INDEX idx_marketing_contact_events (contact_id, id)
);
