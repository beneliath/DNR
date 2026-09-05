ALTER TABLE contacts
    ADD COLUMN normalized_email VARCHAR(255) GENERATED ALWAYS AS (LOWER(TRIM(contact_email))) VIRTUAL,
    ADD INDEX idx_contacts_inbound_email (is_deleted, normalized_email, id);
ALTER TABLE organizations
    ADD COLUMN normalized_email VARCHAR(255) GENERATED ALWAYS AS (LOWER(TRIM(email))) VIRTUAL,
    ADD INDEX idx_organizations_inbound_email (is_deleted, normalized_email, id);
