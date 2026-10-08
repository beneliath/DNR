-- The shared mailbox lands here before any Account receives its contents.
-- Only the primary deployment uses this queue; member tables remain empty.
CREATE TABLE platform_inbound_mail (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transport VARCHAR(16) NOT NULL,
    transport_key VARCHAR(255) NOT NULL,
    deduplication_hash BINARY(32) NOT NULL,
    payload JSON NULL,
    account_key VARCHAR(64) NULL,
    status ENUM('review','pending','delivered','rejected') NOT NULL DEFAULT 'review',
    review_reason VARCHAR(255) NOT NULL DEFAULT '',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    retry_after DATETIME NULL,
    received_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    reviewed_by INT NULL,
    UNIQUE KEY uq_platform_mail_source (transport, transport_key),
    UNIQUE KEY uq_platform_mail_message (deduplication_hash),
    INDEX idx_platform_mail_queue (status, retry_after, id)
);
