-- Extend the Mattermost integration with idempotent engagement-email requests
-- and durable, private reply notifications for the linked MOED sender.

ALTER TABLE engagement_email_messages
    ADD COLUMN mattermost_instance_id VARCHAR(100) NULL AFTER created_by_username_snapshot,
    ADD COLUMN mattermost_idempotency_key VARCHAR(100) NULL AFTER mattermost_instance_id,
    ADD UNIQUE INDEX uq_engagement_email_mattermost_request (
        mattermost_instance_id, mattermost_idempotency_key
    );

CREATE TABLE mattermost_reply_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instance_id VARCHAR(100) NOT NULL,
    mattermost_user_id VARCHAR(64) NOT NULL,
    engagement_id INT NOT NULL,
    inbound_email_message_id BIGINT UNSIGNED NOT NULL,
    delivered_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_mattermost_reply_notification_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_mattermost_reply_notification_message
        FOREIGN KEY (inbound_email_message_id)
        REFERENCES inbound_email_messages(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_mattermost_reply_notification (
        inbound_email_message_id, instance_id, mattermost_user_id, engagement_id
    ),
    INDEX idx_mattermost_reply_notification_delivery (
        instance_id, delivered_at, id
    )
);
