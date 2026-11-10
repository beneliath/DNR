-- These parts belong to the retained source. MessageMonkey stores filed attachments separately.
ALTER TABLE marketing_inbox_inbound_parts
    DROP FOREIGN KEY marketing_inbox_inbound_parts_ibfk_1,
    ADD CONSTRAINT fk_inbox_part_source FOREIGN KEY (inbound_id)
        REFERENCES inbound_email_messages (id) ON DELETE CASCADE;
