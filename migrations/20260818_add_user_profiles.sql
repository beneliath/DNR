ALTER TABLE users
    ADD COLUMN first_name VARCHAR(100) NULL AFTER username,
    ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name,
    ADD COLUMN phone VARCHAR(50) NULL AFTER last_name,
    ADD COLUMN email VARCHAR(254) NULL AFTER phone,
    ADD COLUMN profile_picture MEDIUMBLOB NULL AFTER email,
    ADD COLUMN profile_picture_mime VARCHAR(32) NULL AFTER profile_picture,
    ADD COLUMN profile_picture_updated_at DATETIME NULL AFTER profile_picture_mime;
