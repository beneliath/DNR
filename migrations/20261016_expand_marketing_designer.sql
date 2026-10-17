-- Additive, audience-scoped recovery and collaboration; no People/organization changes.
CREATE TABLE marketing_design_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 owner_type ENUM('campaign','template') NOT NULL,
 owner_id INT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL,
 design_json JSON NOT NULL,
 actor_label VARCHAR(100) NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY (audience_id,owner_type,owner_id,version),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_design_comments (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 owner_type ENUM('campaign','template') NOT NULL,
 owner_id INT UNSIGNED NOT NULL,
 parent_id INT UNSIGNED NULL,
 block_key VARCHAR(80) NOT NULL DEFAULT '',
 body VARCHAR(4000) NOT NULL,
 actor_user_id INT NOT NULL,
 actor_label VARCHAR(100) NOT NULL,
 resolved TINYINT(1) NOT NULL DEFAULT 0,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 INDEX(audience_id,owner_type,owner_id,created_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_design_library (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 item_type ENUM('image','section') NOT NULL,
 name VARCHAR(100) NOT NULL,
 data_json JSON NOT NULL,
 actor_label VARCHAR(100) NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 deleted_at TIMESTAMP(6) NULL,
 INDEX(audience_id,item_type,deleted_at,updated_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
