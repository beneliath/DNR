-- Retain selected Mattermost source posts and expose durable, retryable
-- reactions for successful Chron writes and delivered engagement email.
ALTER TABLE engagement_email_messages
    ADD COLUMN mattermost_post_id CHAR(26) NULL
        AFTER mattermost_idempotency_key;

CREATE TABLE mattermost_post_reaction_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    outbound_email_message_id BIGINT UNSIGNED NULL,
    engagement_chron_entry_id BIGINT UNSIGNED NULL,
    instance_id VARCHAR(100) NOT NULL,
    source_key VARCHAR(100) NOT NULL,
    mattermost_post_id CHAR(26) NOT NULL,
    reaction_name ENUM('memo', 'email') NOT NULL,
    delivered_at DATETIME(6) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_mattermost_post_reaction_notification_message
        FOREIGN KEY (outbound_email_message_id)
        REFERENCES engagement_email_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_mattermost_post_reaction_notification_chron
        FOREIGN KEY (engagement_chron_entry_id)
        REFERENCES engagement_chron_entries(id) ON DELETE CASCADE,
    CONSTRAINT chk_mattermost_post_reaction_notification_source CHECK (
        (reaction_name = 'email'
            AND outbound_email_message_id IS NOT NULL
            AND engagement_chron_entry_id IS NULL)
        OR (reaction_name = 'memo'
            AND outbound_email_message_id IS NULL
            AND engagement_chron_entry_id IS NOT NULL)
    ),
    UNIQUE INDEX uq_mattermost_post_reaction_notification (
        instance_id, reaction_name, source_key
    ),
    INDEX idx_mattermost_post_reaction_notification_delivery (
        instance_id, delivered_at, next_attempt_at, id
    )
);
