ALTER TABLE email_outbox
    MODIFY status ENUM('pending','processing','retry','sent','failed','delivery_uncertain') NOT NULL DEFAULT 'pending',
    ADD COLUMN claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN smtp_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN delivery_started_at DATETIME NULL;

ALTER TABLE notification_outbox
    MODIFY status ENUM('pending','processing','retry','sent','failed','delivery_uncertain') NOT NULL DEFAULT 'pending',
    ADD COLUMN claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN smtp_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN delivery_started_at DATETIME NULL;

ALTER TABLE engagement_email_deliveries
    MODIFY status ENUM('pending','processing','retry','sent','failed','delivery_uncertain') NOT NULL DEFAULT 'pending',
    ADD COLUMN claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN smtp_message_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN delivery_started_at DATETIME NULL;
