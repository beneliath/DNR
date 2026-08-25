-- Contacts may be recorded before their organization is known.
ALTER TABLE contacts
    MODIFY organization_id INT NULL;
