-- Retain the original private-delivery behavior for existing messages.
-- New composer messages record To/Cc/Bcc so retries preserve recipient visibility.
ALTER TABLE engagement_email_deliveries
    ADD COLUMN recipient_type ENUM('private', 'to', 'cc', 'bcc') NOT NULL DEFAULT 'private'
        AFTER recipient_email;
