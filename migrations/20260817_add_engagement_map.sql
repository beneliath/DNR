-- Cache geocoding results separately so map lookups do not modify engagement
-- timestamps or create audit noise. Identical addresses share one lookup.
CREATE TABLE IF NOT EXISTS engagement_map_geocodes (
    address_hash CHAR(64) PRIMARY KEY,
    address_query VARCHAR(1000) NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    lookup_status ENUM('found', 'not_found') NOT NULL,
    geocoded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_engagement_map_coordinates CHECK (
        (lookup_status = 'found' AND latitude IS NOT NULL AND longitude IS NOT NULL)
        OR (lookup_status = 'not_found' AND latitude IS NULL AND longitude IS NULL)
    )
);
