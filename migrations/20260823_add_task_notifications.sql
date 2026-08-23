-- Add opt-in daily work digests without mixing operational notifications into
-- the account-token outbox. Payloads remain encrypted and are erased after a
-- terminal delivery state.
ALTER TABLE users
    ADD COLUMN task_digest_enabled TINYINT(1) NOT NULL DEFAULT 0
        AFTER email_verified_at,
    ADD CONSTRAINT chk_users_task_digest_enabled
        CHECK (task_digest_enabled IN (0, 1));

CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    notification_type ENUM('daily_task_digest') NOT NULL,
    digest_date DATE NOT NULL,
    recipient_hash BINARY(32) NOT NULL,
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
    CONSTRAINT fk_notification_outbox_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_notification_outbox_daily_digest
        (user_id, notification_type, digest_date),
    INDEX idx_notification_outbox_ready
        (status, next_attempt_at, attempts),
    INDEX idx_notification_outbox_digest_date
        (digest_date, status)
);
