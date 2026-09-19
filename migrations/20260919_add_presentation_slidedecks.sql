-- PowerPoint bytes live in private filesystem storage; only metadata is stored here.
CREATE TABLE presentation_slidedecks (
    presentation_id INT NOT NULL,
    speaker_id INT NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    filename VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    size INT UNSIGNED NULL,
    sha256 BINARY(32) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    uploaded_by INT NULL,
    uploaded_by_username_snapshot VARCHAR(50) NULL,
    PRIMARY KEY (presentation_id, speaker_id),
    FOREIGN KEY (storage_key) REFERENCES stored_files(storage_key),
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE,
    FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE RESTRICT,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE short_links
    DROP CHECK chk_short_link_type,
    DROP CHECK chk_short_link_target,
    ADD CONSTRAINT chk_short_link_type CHECK (link_type IN ('website', 'bio', 'donation', 'connection', 'blog', 'books', 'notes', 'slidedeck', 'custom')),
    ADD CONSTRAINT chk_short_link_target CHECK (
        (link_type IN ('notes', 'slidedeck') AND target_url IS NULL)
        OR (link_type NOT IN ('notes', 'slidedeck') AND target_url IS NOT NULL)
    );
