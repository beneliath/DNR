-- Archive completed inquiries without changing their outcome or related records.
ALTER TABLE booking_inquiries
    ADD COLUMN archived_at DATETIME(6) NULL,
    ADD COLUMN archived_by INT NULL,
    ADD CONSTRAINT fk_booking_inquiry_archive_actor
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT chk_booking_inquiry_archive CHECK (
        archived_at IS NULL OR stage IN ('declined', 'booked')
    ),
    ADD INDEX idx_booking_inquiry_archive (archived_at, stage, id);
