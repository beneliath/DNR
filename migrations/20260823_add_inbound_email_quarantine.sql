-- Poison messages must not remain unseen forever and starve every later UID.
-- Retain bounded metadata for operator diagnosis without persisting malformed
-- attacker-controlled message bodies.
CREATE TABLE inbound_email_quarantine (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transport ENUM('imap') NOT NULL DEFAULT 'imap',
    transport_key VARCHAR(255) NOT NULL,
    failure_reason VARCHAR(255) NOT NULL,
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    first_failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    UNIQUE INDEX uq_inbound_quarantine_transport_key (transport, transport_key),
    INDEX idx_inbound_quarantine_review (reviewed_at, last_failed_at)
);
