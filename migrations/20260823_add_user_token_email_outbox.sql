-- Durable, encrypted account-email delivery. Bearer links are never stored in
-- plaintext and superseded tokens remain usable until replacement delivery.
CREATE TABLE email_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    purpose ENUM('invitation', 'verification', 'recovery') NOT NULL,
    payload_ciphertext MEDIUMTEXT NULL,
    status ENUM('pending', 'processing', 'retry', 'sent', 'failed')
        NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_started_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_outbox_token
        FOREIGN KEY (token_id) REFERENCES user_email_tokens(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_outbox_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_email_outbox_token (token_id),
    INDEX idx_email_outbox_ready (status, next_attempt_at, attempts),
    INDEX idx_email_outbox_user_created (user_id, created_at)
);
