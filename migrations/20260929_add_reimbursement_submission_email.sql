ALTER TABLE users ADD COLUMN reimbursement_reviewer_email VARCHAR(254) NOT NULL DEFAULT '';
CREATE TABLE reimbursement_email_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT NULL,
    request_hash CHAR(16) NOT NULL,
    recipient_hash BINARY(32) NOT NULL,
    recipient_type ENUM('to','cc','bcc') NOT NULL,
    attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_ciphertext MEDIUMTEXT NULL,
    status ENUM('pending','processing','retry','sent','failed','delivery_uncertain') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_started_at DATETIME NULL,
    delivery_started_at DATETIME NULL,
    claim_token CHAR(32) NULL,
    smtp_message_id VARCHAR(255) NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reimbursement_delivery (request_hash, recipient_hash),
    KEY idx_reimbursement_delivery_ready (status, next_attempt_at, id),
    FOREIGN KEY (request_id) REFERENCES reimbursement_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (attachment_key) REFERENCES stored_files(storage_key)
) ENGINE=InnoDB;
