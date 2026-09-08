-- Older PDFs retain their upload timestamps; their uploader was not recorded.
ALTER TABLE presentation_notes
    ADD COLUMN uploaded_by INT NULL AFTER updated_at,
    ADD COLUMN uploaded_by_username_snapshot VARCHAR(50) NULL AFTER uploaded_by,
    ADD CONSTRAINT fk_presentation_notes_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL;
