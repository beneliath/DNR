-- Immutable reviewed campaign and recipient snapshots. SMTP credentials stay in the worker.
ALTER TABLE marketing_campaigns ADD UNIQUE KEY uq_marketing_campaign_scope (audience_id,id);
CREATE TABLE marketing_campaign_deliveries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 campaign_id INT UNSIGNED NOT NULL,
 confirmed_version INT UNSIGNED NOT NULL,
 state ENUM('scheduled','sending','paused','completed','cancelled') NOT NULL,
 due_at DATETIME NOT NULL,
 timezone VARCHAR(80) NOT NULL,
 snapshot_ciphertext MEDIUMTEXT NOT NULL,
 asset_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 recipient_count INT UNSIGNED NOT NULL,
 actor_id INT NOT NULL,
 actor_label VARCHAR(100) NOT NULL,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 completed_at TIMESTAMP(6) NULL,
 UNIQUE KEY (asset_key),
 UNIQUE KEY (audience_id,campaign_id,confirmed_version),
 UNIQUE KEY (audience_id,id),
 INDEX (audience_id,state,due_at),
 FOREIGN KEY (audience_id,campaign_id) REFERENCES marketing_campaigns(audience_id,id)
);
CREATE TABLE marketing_campaign_recipients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 delivery_id BIGINT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NOT NULL,
 email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 context_ciphertext MEDIUMTEXT NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_id VARCHAR(140) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('queued','processing','accepted','development','suppressed','failed','uncertain','cancelled') NOT NULL DEFAULT 'queued',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL,
 claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 started_at DATETIME NULL,
 delivery_started_at DATETIME NULL,
 accepted_at DATETIME NULL,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 UNIQUE KEY (delivery_id,contact_id),
 UNIQUE KEY (token_hash),
 UNIQUE KEY (message_id),
 INDEX (audience_id,delivery_id,state,next_attempt_at),
 FOREIGN KEY (audience_id,delivery_id) REFERENCES marketing_campaign_deliveries(audience_id,id)
);
-- Public opt-outs also suppress a subsequently re-created or re-imported address.
CREATE TABLE marketing_email_optouts (
 audience_id INT UNSIGNED NOT NULL,
 email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (audience_id,email_hash),
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
