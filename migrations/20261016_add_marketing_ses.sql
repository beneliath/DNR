-- Account-isolated SES API setup. Operational mail remains on the existing SMTP path.
CREATE TABLE marketing_ses_settings (
 audience_id INT UNSIGNED PRIMARY KEY,
 runtime_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 region VARCHAR(40) NOT NULL DEFAULT '',
 from_email VARCHAR(254) NOT NULL DEFAULT '',
 configuration_set VARCHAR(64) NOT NULL DEFAULT '',
 topic_arn VARCHAR(256) NOT NULL DEFAULT '',
 access_key_tail VARCHAR(8) NOT NULL DEFAULT '',
 generation CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 check_state ENUM('unconfigured','queued','checking','ready','attention') NOT NULL DEFAULT 'unconfigured',
 checked_at DATETIME NULL,
 check_started_at DATETIME NULL,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 result_json JSON NOT NULL,
 aws_account_id CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 production_access TINYINT(1) NOT NULL DEFAULT 0,
 sending_enabled TINYINT(1) NOT NULL DEFAULT 0,
 max_daily DOUBLE UNSIGNED NOT NULL DEFAULT 0,
 max_rate DOUBLE UNSIGNED NOT NULL DEFAULT 0,
 sent_last_day DOUBLE UNSIGNED NOT NULL DEFAULT 0,
 quota_checked_at DATETIME(6) NULL,
 last_permit_at DATETIME(6) NULL,
 blocked_until DATETIME NULL,
 subscription_arn VARCHAR(512) NOT NULL DEFAULT '',
 subscription_event_at DATETIME NULL,
 last_event_at DATETIME NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
INSERT INTO marketing_ses_settings(audience_id,result_json) SELECT id,JSON_OBJECT() FROM marketing_audiences;
CREATE TABLE marketing_ses_credentials (
 audience_id INT UNSIGNED PRIMARY KEY,
 credential_ciphertext TEXT NOT NULL,
 FOREIGN KEY(audience_id) REFERENCES marketing_ses_settings(audience_id)
);
INSERT INTO marketing_ses_credentials(audience_id,credential_ciphertext) SELECT id,'' FROM marketing_audiences;
CREATE TABLE marketing_ses_usage (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 aws_account_id CHAR(12) NOT NULL,
 region VARCHAR(40) NOT NULL,
 lane VARCHAR(20) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(audience_id,aws_account_id,region,created_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_ses_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 notification_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_type VARCHAR(30) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,notification_id),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
ALTER TABLE marketing_campaign_recipients
 ADD provider VARCHAR(20) NOT NULL DEFAULT '',
 ADD provider_message_id VARCHAR(256) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD provider_account_id CHAR(12) NOT NULL DEFAULT '',
 ADD provider_region VARCHAR(40) NOT NULL DEFAULT '',
 ADD ses_correlation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD UNIQUE KEY uq_marketing_ses_correlation(ses_correlation),
 ADD INDEX(provider_message_id);
ALTER TABLE marketing_service_mail
 ADD provider_message_id VARCHAR(256) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE marketing_message_events MODIFY event_type ENUM('open','click','delivered','soft-bounce','hard-bounce','complaint','send','delay','rejected') NOT NULL;
-- No old marketing setup request may send through the operational relay after the switch.
UPDATE marketing_delivery_setup_jobs SET state='cancelled',payload_ciphertext='',error_message='Marketing delivery now requires this account’s SES API setup.' WHERE state='queued';
UPDATE marketing_ses_settings SET runtime_version=1;
