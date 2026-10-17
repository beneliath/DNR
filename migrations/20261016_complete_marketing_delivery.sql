CREATE TABLE marketing_delivery_policy (
 audience_id INT UNSIGNED PRIMARY KEY,
 runtime_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
 bulk_approved TINYINT(1) NOT NULL DEFAULT 0,
 hourly_budget INT UNSIGNED NOT NULL DEFAULT 0,
 daily_budget INT UNSIGNED NOT NULL DEFAULT 0,
 hourly_reserve INT UNSIGNED NOT NULL DEFAULT 0,
 daily_reserve INT UNSIGNED NOT NULL DEFAULT 0,
 interval_seconds INT UNSIGNED NOT NULL DEFAULT 1,
 last_permit_at DATETIME(6) NULL,
 blocked_until DATETIME NULL,
 block_reason VARCHAR(200) NOT NULL DEFAULT '',
 event_key_ciphertext TEXT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
INSERT INTO marketing_delivery_policy(audience_id) SELECT id FROM marketing_audiences;
CREATE TABLE marketing_relay_usage (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 message_id VARCHAR(140) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 lane VARCHAR(20) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(audience_id,created_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_service_mail (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NULL,
 kind ENUM('test','confirmation') NOT NULL,
 actor_id INT NULL,
 actor_label VARCHAR(100) NOT NULL DEFAULT '',
 operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_ciphertext MEDIUMTEXT NOT NULL,
 message_id VARCHAR(140) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('queued','processing','accepted','development','failed','uncertain','cancelled') NOT NULL DEFAULT 'queued',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 started_at DATETIME NULL,
 delivery_started_at DATETIME NULL,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,operation_key),
 INDEX(audience_id,state,next_attempt_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_consent_challenges (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NOT NULL,
 email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 service_mail_id BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(token_hash),
 INDEX(audience_id,contact_id,created_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY(service_mail_id) REFERENCES marketing_service_mail(id)
);
CREATE TABLE marketing_message_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 event_type ENUM('open','click','delivered','soft-bounce','hard-bounce','complaint') NOT NULL,
 event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source VARCHAR(30) NOT NULL,
 event_at DATETIME NOT NULL,
 details_json JSON NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,event_key),
 INDEX(audience_id,recipient_id,event_type),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY(recipient_id) REFERENCES marketing_campaign_recipients(id)
);
ALTER TABLE marketing_campaign_recipients
 ADD variant_key VARCHAR(30) NOT NULL DEFAULT '0',
 ADD phase ENUM('main','test','hold','resend') NOT NULL DEFAULT 'main',
 ADD original_recipient_id BIGINT UNSIGNED NULL,
 DROP INDEX delivery_id,
 ADD UNIQUE KEY uq_marketing_recipient_phase(delivery_id,contact_id,phase);
ALTER TABLE marketing_campaign_deliveries
 ADD experiment_due_at DATETIME NULL,
 ADD winner_key VARCHAR(30) NULL,
 ADD resend_due_at DATETIME NULL,
 ADD resend_created_at DATETIME NULL;

ALTER TABLE marketing_delivery_setup_jobs ADD next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
UPDATE marketing_delivery_policy SET runtime_version=1;
