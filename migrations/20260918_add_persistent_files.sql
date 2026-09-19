CREATE TABLE stored_files (
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(127) NOT NULL,
    size BIGINT UNSIGNED NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Legacy BLOBs remain nullable solely for a resumable, lossless conversion.
-- The file-migrator copies and verifies each file before clearing its BLOB.
ALTER TABLE users
    ADD COLUMN profile_picture_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN profile_picture_thumbnail_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD FOREIGN KEY (profile_picture_key) REFERENCES stored_files(storage_key),
    ADD FOREIGN KEY (profile_picture_thumbnail_key) REFERENCES stored_files(storage_key);
ALTER TABLE contacts
    ADD COLUMN contact_photo_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN contact_photo_thumbnail_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD FOREIGN KEY (contact_photo_key) REFERENCES stored_files(storage_key),
    ADD FOREIGN KEY (contact_photo_thumbnail_key) REFERENCES stored_files(storage_key);
ALTER TABLE speakers
    ADD COLUMN photo_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN photo_thumbnail_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD FOREIGN KEY (photo_key) REFERENCES stored_files(storage_key),
    ADD FOREIGN KEY (photo_thumbnail_key) REFERENCES stored_files(storage_key);
ALTER TABLE presentation_notes
    ADD COLUMN storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD FOREIGN KEY (storage_key) REFERENCES stored_files(storage_key);
