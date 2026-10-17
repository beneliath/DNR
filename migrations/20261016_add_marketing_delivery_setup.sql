-- Sender access, provider DNS requirements and setup-only jobs. Campaign dispatch is separate.
CREATE TABLE marketing_sending_domains (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 records_json JSON NOT NULL,
 checks_json JSON NOT NULL,
 authentication_enabled TINYINT(1) NOT NULL DEFAULT 0,
 authenticated_at DATETIME NULL,
 checked_at DATETIME NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 deleted_at TIMESTAMP(6) NULL,
 UNIQUE KEY (audience_id,domain), UNIQUE KEY (audience_id,id),
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_sending_addresses (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 domain_id INT UNSIGNED NOT NULL,
 email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 generation CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 expires_at DATETIME NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 verified_at DATETIME NULL,
 verified_transport VARCHAR(12) NOT NULL DEFAULT '',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 deleted_at TIMESTAMP(6) NULL,
 UNIQUE KEY (audience_id,email), UNIQUE KEY (audience_id,id),
 FOREIGN KEY (audience_id,domain_id) REFERENCES marketing_sending_domains(audience_id,id)
);
CREATE TABLE marketing_delivery_setup_jobs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 sender_id INT UNSIGNED NULL,
 job_type ENUM('verify','probe') NOT NULL,
 generation CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 payload_ciphertext MEDIUMTEXT NOT NULL,
 state ENUM('queued','processing','accepted','development','failed','uncertain','cancelled') NOT NULL DEFAULT 'queued',
 claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 started_at DATETIME NULL,
 delivery_started_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 result_json JSON NOT NULL,
 error_message VARCHAR(500) NOT NULL DEFAULT '',
 actor_id INT NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 INDEX (state,created_at), INDEX (audience_id,created_at),
 FOREIGN KEY (audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY (audience_id,sender_id) REFERENCES marketing_sending_addresses(audience_id,id)
);
