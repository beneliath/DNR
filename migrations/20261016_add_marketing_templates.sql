-- Reusable designs are snapshots, separate from campaign content and organization data.
CREATE TABLE marketing_email_templates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 folder VARCHAR(100) NOT NULL DEFAULT '',
 design_json JSON NOT NULL,
 actor_label VARCHAR(100) NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 deleted_at TIMESTAMP(6) NULL,
 INDEX(audience_id,deleted_at,updated_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
