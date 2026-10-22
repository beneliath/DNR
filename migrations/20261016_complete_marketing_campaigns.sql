-- Additive campaign planning/publication storage; existing account and organization tables are unchanged.
CREATE TABLE marketing_campaign_plans (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 data_json JSON NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 actor_label VARCHAR(100) NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 deleted_at TIMESTAMP(6) NULL,
 UNIQUE KEY (audience_id,id),
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_campaign_touchpoints (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 plan_id INT UNSIGNED NULL,
 campaign_id INT UNSIGNED NULL,
 touchpoint_type ENUM('task','event','email') NOT NULL,
 name VARCHAR(100) NOT NULL,
 start_at DATETIME NOT NULL,
 end_at DATETIME NOT NULL,
 state ENUM('not-started','in-progress','complete') NOT NULL DEFAULT 'not-started',
 data_json JSON NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 actor_label VARCHAR(100) NOT NULL,
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 deleted_at TIMESTAMP(6) NULL,
 INDEX (audience_id,deleted_at,start_at),
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY (audience_id,plan_id) REFERENCES marketing_campaign_plans(audience_id,id),
 FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id)
);
CREATE TABLE marketing_campaign_publications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 campaign_id INT UNSIGNED NOT NULL,
 public_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 archive_listed TINYINT(1) NOT NULL DEFAULT 0,
 title VARCHAR(200) NOT NULL,
 description VARCHAR(500) NOT NULL DEFAULT '',
 image_data MEDIUMTEXT NOT NULL,
 html MEDIUMTEXT NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY (public_key),
 UNIQUE KEY (audience_id,campaign_id),
 FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id),
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
