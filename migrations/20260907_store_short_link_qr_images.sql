-- Rendered once on link creation; existing links are populated by
-- scripts/backfill_short_link_qr.php after this migration.
CREATE TABLE short_link_qr_images (
    link_id BIGINT UNSIGNED PRIMARY KEY,
    encoded_url VARCHAR(2048) NOT NULL,
    png MEDIUMBLOB NOT NULL,
    svg MEDIUMBLOB NOT NULL,
    png_sha256 BINARY(32) NOT NULL,
    svg_sha256 BINARY(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (link_id) REFERENCES short_links(id) ON DELETE CASCADE
);
