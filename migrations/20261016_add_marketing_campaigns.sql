-- Additive campaign storage; existing People and organization tables are unchanged.
CREATE TABLE marketing_campaign_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY (audience_id,name),
    UNIQUE KEY (audience_id,id),
    FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    campaign_type ENUM('regular','plain-text') NOT NULL DEFAULT 'regular',
    status ENUM('draft','scheduled','sending','sent','paused') NOT NULL DEFAULT 'draft',
    draft_json JSON NOT NULL,
    actor_label VARCHAR(100) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    sent_at TIMESTAMP(6) NULL,
    deleted_at TIMESTAMP(6) NULL,
    INDEX (audience_id,deleted_at,updated_at),
    INDEX (audience_id,folder_id),
    FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id),
    FOREIGN KEY (audience_id,folder_id) REFERENCES marketing_campaign_folders(audience_id,id)
);
