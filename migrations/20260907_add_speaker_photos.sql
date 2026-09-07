ALTER TABLE speakers
    ADD COLUMN photo MEDIUMBLOB NULL,
    ADD COLUMN photo_thumbnail BLOB NULL,
    ADD COLUMN photo_mime VARCHAR(32) NULL,
    ADD COLUMN photo_thumbnail_mime VARCHAR(32) NULL,
    ADD COLUMN photo_sha256 BINARY(32) NULL,
    ADD COLUMN photo_updated_at DATETIME(6) NULL;
