-- Record lookup provenance and provide a separate, user-confirmed pin override.
ALTER TABLE engagement_map_geocodes
    ADD COLUMN provider VARCHAR(32) NOT NULL DEFAULT 'nominatim',
    ADD COLUMN confidence DECIMAL(4,3) NULL,
    ADD COLUMN match_type VARCHAR(64) NULL,
    ADD COLUMN matched_address VARCHAR(1000) NULL;

CREATE TABLE engagement_map_pins (
    engagement_id INT PRIMARY KEY,
    address_hash CHAR(64) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    confirmed_by INT NOT NULL,
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_map_pin_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_map_pin_user FOREIGN KEY (confirmed_by) REFERENCES users(id),
    CONSTRAINT chk_map_pin_coordinates CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)
);
