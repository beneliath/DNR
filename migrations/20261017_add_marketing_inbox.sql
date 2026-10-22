-- Native, account-local one-to-one conversations. No source is connected by migration.
CREATE TABLE marketing_inbox_settings (
 audience_id INT UNSIGNED PRIMARY KEY,
 runtime_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
 campaign_replies TINYINT(1) NOT NULL DEFAULT 0,
 survey_replies TINYINT(1) NOT NULL DEFAULT 0,
 gateway_confirmed TINYINT(1) NOT NULL DEFAULT 0,
 contact_enabled TINYINT(1) NOT NULL DEFAULT 0,
 contact_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 contact_document JSON NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
INSERT INTO marketing_inbox_settings(audience_id,contact_document)
 SELECT id,JSON_OBJECT('title','Contact us','intro','Send us a message. We would love to hear from you.','button','Send message','success','Thank you. Your message has been received.') FROM marketing_audiences;

CREATE TABLE marketing_inbox_threads (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NULL,
 sender_email VARCHAR(254) NOT NULL,
 sender_name VARCHAR(100) NOT NULL DEFAULT '',
 subject VARCHAR(250) NOT NULL,
 source ENUM('email','contact','survey','direct') NOT NULL,
 source_ref BIGINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('todo','done','trash') NOT NULL DEFAULT 'todo',
 previous_status ENUM('todo','done') NOT NULL DEFAULT 'todo',
 sender_verified TINYINT(1) NOT NULL DEFAULT 0,
 last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_inbound_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_activity_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,id),
 INDEX(audience_id,status,last_activity_at,id),
 INDEX(audience_id,sender_email),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_routes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 source ENUM('email','direct','thread') NOT NULL,
 reference_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY(audience_id,source,reference_id),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 thread_id BIGINT UNSIGNED NOT NULL,
 direction ENUM('inbound','outbound','comment') NOT NULL,
 source_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 rfc_message_id VARCHAR(998) NOT NULL DEFAULT '',
 references_json JSON NOT NULL,
 sender_email VARCHAR(254) NOT NULL DEFAULT '',
 sender_name VARCHAR(100) NOT NULL DEFAULT '',
 actor_id INT NULL,
 body_text MEDIUMTEXT NOT NULL,
 body_html MEDIUMTEXT NOT NULL,
 service_mail_id BIGINT UNSIGNED NULL,
 parent_id BIGINT UNSIGNED NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,id),
 UNIQUE KEY(audience_id,source_key),
 INDEX(audience_id,thread_id,id),
 INDEX(audience_id,service_mail_id),
 FOREIGN KEY(audience_id,thread_id) REFERENCES marketing_inbox_threads(audience_id,id),
 FOREIGN KEY(service_mail_id) REFERENCES marketing_service_mail(id)
);
CREATE TABLE marketing_inbox_drafts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 actor_id INT NOT NULL,
 contact_id INT UNSIGNED NULL,
 thread_id BIGINT UNSIGNED NULL,
 subject VARCHAR(250) NOT NULL DEFAULT '',
 from_name VARCHAR(100) NOT NULL DEFAULT '',
 body_html MEDIUMTEXT NOT NULL,
 include_optout TINYINT(1) NOT NULL DEFAULT 1,
 state ENUM('draft','queued','discarded') NOT NULL DEFAULT 'draft',
 service_mail_id BIGINT UNSIGNED NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,id),
 INDEX(audience_id,actor_id,state),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY(service_mail_id) REFERENCES marketing_service_mail(id)
);
CREATE TABLE marketing_inbox_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 message_id BIGINT UNSIGNED NULL,
 draft_id BIGINT UNSIGNED NULL,
 filename VARCHAR(180) NOT NULL,
 mime_type VARCHAR(50) NOT NULL,
 byte_size INT UNSIGNED NOT NULL,
 sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 content MEDIUMBLOB NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(audience_id,message_id),
 INDEX(audience_id,draft_id),
 FOREIGN KEY(audience_id,message_id) REFERENCES marketing_inbox_messages(audience_id,id),
 FOREIGN KEY(audience_id,draft_id) REFERENCES marketing_inbox_drafts(audience_id,id)
);
CREATE TABLE marketing_inbox_labels (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 deleted_at DATETIME NULL,
 UNIQUE KEY(audience_id,id),
 UNIQUE KEY(audience_id,name),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_thread_labels (
 audience_id INT UNSIGNED NOT NULL,
 thread_id BIGINT UNSIGNED NOT NULL,
 label_id INT UNSIGNED NOT NULL,
 PRIMARY KEY(audience_id,thread_id,label_id),
 FOREIGN KEY(audience_id,thread_id) REFERENCES marketing_inbox_threads(audience_id,id),
 FOREIGN KEY(audience_id,label_id) REFERENCES marketing_inbox_labels(audience_id,id)
);
CREATE TABLE marketing_inbox_templates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 subject VARCHAR(250) NOT NULL,
 body_html MEDIUMTEXT NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 deleted_at DATETIME NULL,
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,name),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_read_state (
 audience_id INT UNSIGNED NOT NULL,
 thread_id BIGINT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(audience_id,thread_id,user_id),
 FOREIGN KEY(audience_id,thread_id) REFERENCES marketing_inbox_threads(audience_id,id)
);
CREATE TABLE marketing_inbox_user_settings (
 audience_id INT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 browser_alerts TINYINT(1) NOT NULL DEFAULT 0,
 email_alerts TINYINT(1) NOT NULL DEFAULT 0,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(audience_id,user_id),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 thread_id BIGINT UNSIGNED NOT NULL,
 message_id BIGINT UNSIGNED NOT NULL,
 kind ENUM('inbound','comment') NOT NULL,
 read_at DATETIME NULL,
 email_mail_id BIGINT UNSIGNED NULL,
 email_checked_at DATETIME NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,user_id,message_id),
 INDEX(audience_id,user_id,read_at,id),
 FOREIGN KEY(audience_id,thread_id) REFERENCES marketing_inbox_threads(audience_id,id),
 FOREIGN KEY(audience_id,message_id) REFERENCES marketing_inbox_messages(audience_id,id)
);
CREATE TABLE marketing_inbox_preferences (
 audience_id INT UNSIGNED NOT NULL,
 email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 optout_at DATETIME(6) NULL,
 last_inbound_at DATETIME(6) NULL,
 PRIMARY KEY(audience_id,email_hash),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_optout_tokens (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_inbox_presence (
 audience_id INT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 thread_id BIGINT UNSIGNED NOT NULL,
 is_typing TINYINT(1) NOT NULL DEFAULT 0,
 seen_at DATETIME NOT NULL,
 PRIMARY KEY(audience_id,user_id),
 INDEX(audience_id,thread_id,seen_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
ALTER TABLE marketing_service_mail
 MODIFY kind ENUM('test','confirmation','inbox','inbox-alert') NOT NULL,
 ADD ses_correlation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD provider_account_id CHAR(12) NULL,
 ADD provider_region VARCHAR(40) NULL,
 ADD recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD INDEX(audience_id,ses_correlation);
CREATE TABLE marketing_inbox_delivery_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 service_mail_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(30) NOT NULL,
 event_at DATETIME(6) NOT NULL,
 INDEX(audience_id,service_mail_id,id),
 FOREIGN KEY(service_mail_id) REFERENCES marketing_service_mail(id)
);
CREATE TABLE marketing_inbox_inbound_parts (
 inbound_id BIGINT UNSIGNED NOT NULL,
 part_number TINYINT UNSIGNED NOT NULL,
 filename VARCHAR(180) NOT NULL,
 content MEDIUMBLOB NOT NULL,
 PRIMARY KEY(inbound_id,part_number),
 FOREIGN KEY(inbound_id) REFERENCES inbound_email_messages(id)
);
UPDATE marketing_inbox_settings SET runtime_version=1;
