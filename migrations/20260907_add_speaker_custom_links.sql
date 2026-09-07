-- Profile link keys remain stable when rows are renamed or reordered. Published
-- short links retain their own label/destination even after profile removal.
ALTER TABLE speakers ADD COLUMN custom_links JSON NULL;

ALTER TABLE short_links
    DROP CHECK chk_short_link_type,
    DROP INDEX uq_presentation_speaker_link,
    ADD COLUMN custom_link_key CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    ADD COLUMN custom_label VARCHAR(255) NULL,
    ADD UNIQUE KEY uq_presentation_speaker_link (presentation_id, speaker_id, link_type, custom_link_key),
    ADD CONSTRAINT chk_short_link_type CHECK (link_type IN ('website', 'bio', 'donation', 'connection', 'blog', 'books', 'notes', 'custom')),
    ADD CONSTRAINT chk_short_link_custom CHECK (
        (link_type = 'custom' AND CHAR_LENGTH(custom_link_key) = 16 AND custom_label IS NOT NULL AND CHAR_LENGTH(TRIM(custom_label)) > 0)
        OR (link_type <> 'custom' AND custom_link_key = '' AND custom_label IS NULL)
    );
