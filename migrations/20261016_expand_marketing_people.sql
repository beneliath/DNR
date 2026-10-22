CREATE TABLE marketing_audiences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    settings_json JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
);
INSERT INTO marketing_audiences (id, name, settings_json) VALUES (1, 'My audience', JSON_OBJECT());

ALTER TABLE marketing_contacts
    ADD audience_id INT UNSIGNED NOT NULL DEFAULT 1,
    ADD custom_json JSON NOT NULL DEFAULT (JSON_OBJECT()),
    ADD is_vip TINYINT(1) NOT NULL DEFAULT 0,
    ADD language VARCHAR(12) NOT NULL DEFAULT '',
    ADD timezone VARCHAR(80) NOT NULL DEFAULT '',
    ADD deleted_at TIMESTAMP(6) NULL,
    DROP INDEX uq_marketing_contact_email,
    ADD UNIQUE KEY uq_marketing_person_email (audience_id, email),
    ADD INDEX idx_marketing_audience_people (audience_id, deleted_at, is_archived, id),
    ADD FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id);
ALTER TABLE marketing_tags
    ADD audience_id INT UNSIGNED NOT NULL DEFAULT 1,
    ADD version INT UNSIGNED NOT NULL DEFAULT 1,
    DROP INDEX uq_marketing_tag_name,
    ADD UNIQUE KEY uq_marketing_audience_tag (audience_id, name),
    ADD FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id);
ALTER TABLE marketing_segments
    ADD audience_id INT UNSIGNED NOT NULL DEFAULT 1,
    ADD version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    DROP INDEX uq_marketing_segment_name,
    ADD UNIQUE KEY uq_marketing_audience_segment (audience_id, name),
    ADD FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id);
ALTER TABLE marketing_contact_events
    MODIFY actor_user_id INT NULL,
    ADD actor_label VARCHAR(100) NOT NULL DEFAULT '',
    ADD actor_type VARCHAR(20) NOT NULL DEFAULT 'user';

CREATE TABLE marketing_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    merge_tag VARCHAR(30) NOT NULL,
    field_type VARCHAR(20) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    default_value TEXT NOT NULL,
    options_json JSON NOT NULL,
    position INT NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY (audience_id, merge_tag),
    FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_group_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    display_type ENUM('checkboxes','radio','dropdown','hidden') NOT NULL DEFAULT 'checkboxes',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY (audience_id, name),
    FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_group_options (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    UNIQUE KEY (category_id, name),
    FOREIGN KEY (category_id) REFERENCES marketing_group_categories(id) ON DELETE CASCADE
);
CREATE TABLE marketing_contact_groups (
    contact_id INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, option_id),
    FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES marketing_group_options(id) ON DELETE CASCADE
);
CREATE TABLE marketing_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id INT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    actor_label VARCHAR(100) NOT NULL,
    body TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    FOREIGN KEY (contact_id) REFERENCES marketing_contacts(id) ON DELETE CASCADE
);
INSERT INTO marketing_notes (contact_id, actor_label, body, created_at, updated_at)
    SELECT id, 'Existing note', notes, created_at, updated_at FROM marketing_contacts WHERE notes <> '';
-- Preserve the person's last-change timestamp while moving legacy notes.
UPDATE marketing_contacts SET notes = '', updated_at = updated_at WHERE notes <> '';

CREATE TABLE marketing_deleted_emails (
    audience_id INT UNSIGNED NOT NULL,
    email_hash CHAR(64) NOT NULL,
    deleted_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (audience_id, email_hash),
    FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    job_type ENUM('import','export','bulk') NOT NULL,
    name VARCHAR(180) NOT NULL,
    state ENUM('draft','queued','processing','complete','cancelled','failed','undone') NOT NULL DEFAULT 'draft',
    actor_user_id INT NOT NULL,
    actor_label VARCHAR(100) NOT NULL,
    params_json JSON NOT NULL,
    total INT UNSIGNED NOT NULL DEFAULT 0,
    processed INT UNSIGNED NOT NULL DEFAULT 0,
    added INT UNSIGNED NOT NULL DEFAULT 0,
    updated INT UNSIGNED NOT NULL DEFAULT 0,
    skipped INT UNSIGNED NOT NULL DEFAULT 0,
    errors INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_marketing_jobs_queue (state, id),
    FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_job_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    source_row INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NULL,
    payload_json JSON NOT NULL,
    result_json JSON NULL,
    state ENUM('pending','added','updated','exported','skipped','error','undone') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NOT NULL DEFAULT '',
    applied_version INT UNSIGNED NULL,
    UNIQUE KEY (job_id, source_row),
    INDEX idx_marketing_job_pending (job_id, state, id),
    FOREIGN KEY (job_id) REFERENCES marketing_jobs(id) ON DELETE CASCADE
);
