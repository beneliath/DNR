-- Speaker Notes is the single PDF resource for a presentation. Preserve
-- published short links and speaker attribution; never overwrite distinct PDFs.
DELIMITER $$
CREATE PROCEDURE unify_presentation_speaker_notes()
BEGIN
    IF EXISTS (
        SELECT 1 FROM presentations p
        JOIN presentation_notes n ON n.presentation_id = p.id AND n.speaker_id = p.speaker_id
        WHERE p.slide_deck_pdf IS NOT NULL AND n.pdf IS NOT NULL
          AND SHA2(p.slide_deck_pdf, 256) <> SHA2(n.pdf, 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Distinct presentation PDFs require reconciliation before unifying Speaker Notes; no files changed.';
    END IF;

    -- Existing notes metadata wins when the same PDF was stored twice.
    UPDATE presentation_notes n
    JOIN presentations p ON p.id = n.presentation_id AND p.speaker_id = n.speaker_id
    SET n.pdf = p.slide_deck_pdf,
        n.filename = COALESCE(p.slide_deck_filename, 'speaker-notes.pdf'),
        n.size = OCTET_LENGTH(p.slide_deck_pdf),
        n.sha256 = UNHEX(SHA2(p.slide_deck_pdf, 256)),
        n.updated_at = COALESCE(p.slide_deck_updated_at, UTC_TIMESTAMP(6))
    WHERE n.pdf IS NULL AND p.slide_deck_pdf IS NOT NULL;

    INSERT INTO presentation_notes (presentation_id, speaker_id, pdf, filename, size, sha256, updated_at)
    SELECT p.id, p.speaker_id, p.slide_deck_pdf,
        COALESCE(p.slide_deck_filename, 'speaker-notes.pdf'), OCTET_LENGTH(p.slide_deck_pdf),
        UNHEX(SHA2(p.slide_deck_pdf, 256)), COALESCE(p.slide_deck_updated_at, UTC_TIMESTAMP(6))
    FROM presentations p
    WHERE p.slide_deck_pdf IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM presentation_notes n WHERE n.presentation_id = p.id AND n.speaker_id = p.speaker_id
    );

    INSERT INTO short_links (code, engagement_id, presentation_id, speaker_id, link_type, target_url)
    SELECT LOWER(HEX(RANDOM_BYTES(8))), p.engagement_id, n.presentation_id, n.speaker_id, 'notes', NULL
    FROM presentation_notes n JOIN presentations p ON p.id = n.presentation_id
    WHERE n.pdf IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM short_links l WHERE l.presentation_id = n.presentation_id
            AND l.speaker_id = n.speaker_id AND l.link_type = 'notes'
    );
END$$
DELIMITER ;
CALL unify_presentation_speaker_notes();
DROP PROCEDURE unify_presentation_speaker_notes;

ALTER TABLE presentations
    DROP COLUMN slide_deck_pdf,
    DROP COLUMN slide_deck_filename,
    DROP COLUMN slide_deck_size,
    DROP COLUMN slide_deck_sha256,
    DROP COLUMN slide_deck_updated_at;
