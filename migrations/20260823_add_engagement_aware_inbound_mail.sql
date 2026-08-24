-- Route explicitly marked inbound messages to Engagement Chron logs while
-- preserving the source record and remaining idempotent across worker retries.
ALTER TABLE engagement_chron_entries
    ADD COLUMN inbound_email_message_id BIGINT UNSIGNED NULL AFTER engagement_id,
    ADD CONSTRAINT fk_engagement_chron_inbound_email
        FOREIGN KEY (inbound_email_message_id)
        REFERENCES inbound_email_messages(id) ON DELETE SET NULL,
    ADD UNIQUE INDEX uq_engagement_chron_inbound_email (
        inbound_email_message_id, engagement_id
    );
