-- SES incidents use the operational SMTP relay, not the SES marketing transport.
ALTER TABLE marketing_ses_settings
 ADD alert_incident CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '';

CREATE TABLE marketing_ses_alert_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 incident_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id INT NOT NULL,
 recipient_hash BINARY(32) NOT NULL,
 payload_ciphertext MEDIUMTEXT NULL,
 status ENUM('pending','processing','retry','sent','failed','delivery_uncertain') NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processing_started_at DATETIME NULL,
 delivery_started_at DATETIME NULL,
 claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 smtp_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
 sent_at DATETIME NULL,
 last_error VARCHAR(255) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_marketing_ses_alert_recipient(audience_id,incident_key,user_id,recipient_hash),
 INDEX idx_marketing_ses_alert_ready(status,next_attempt_at,attempts),
 INDEX idx_marketing_ses_alert_retention(status,updated_at,id),
 FOREIGN KEY(audience_id) REFERENCES marketing_ses_settings(audience_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
