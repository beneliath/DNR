-- The network-isolated web tier only accepts bounded envelopes. The mail worker
-- verifies AWS signatures and confirms subscriptions with account credentials.
CREATE TABLE marketing_ses_inbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 generation CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_ciphertext MEDIUMTEXT NOT NULL,
 state ENUM('queued','processing','done','rejected','failed') NOT NULL DEFAULT 'queued',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 started_at DATETIME NULL,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 error_message VARCHAR(200) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY(audience_id,generation,payload_hash),
 INDEX(audience_id,state),
 INDEX(state,next_attempt_at,id),
 INDEX(created_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_ses_settings(audience_id)
);
UPDATE marketing_ses_settings SET runtime_version=2;
