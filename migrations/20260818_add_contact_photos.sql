ALTER TABLE contacts
    ADD COLUMN contact_photo MEDIUMBLOB NULL AFTER contact_phone,
    ADD COLUMN contact_photo_mime VARCHAR(32) NULL AFTER contact_photo,
    ADD COLUMN contact_photo_updated_at DATETIME NULL AFTER contact_photo_mime;
