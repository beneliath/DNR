-- Older finalized reports and revisions have no recorded book-table amount.
-- Preserve that unknown value; all new closeouts require an explicit amount in the application.
ALTER TABLE engagement_financial_reports
    ADD COLUMN book_table_received DECIMAL(12,2) NULL AFTER travel_received,
    ADD CONSTRAINT chk_financial_report_book_table
        CHECK (book_table_received IS NULL OR book_table_received >= 0);

ALTER TABLE engagement_financial_drafts
    ADD COLUMN book_table_received DECIMAL(12,2) NULL AFTER travel_received,
    ADD CONSTRAINT chk_financial_draft_book_table
        CHECK (book_table_received IS NULL OR book_table_received >= 0);

ALTER TABLE engagement_financial_revisions
    ADD COLUMN book_table_received DECIMAL(12,2) NULL AFTER travel_received,
    ADD CONSTRAINT chk_financial_revision_book_table
        CHECK (book_table_received IS NULL OR book_table_received >= 0);

DROP TRIGGER IF EXISTS financial_revision_after_insert;
DROP TRIGGER IF EXISTS financial_correction_before_update;
DROP TRIGGER IF EXISTS financial_revision_after_update;

DELIMITER $$
CREATE TRIGGER financial_revision_after_insert AFTER INSERT ON engagement_financial_reports FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 THEN
        INSERT INTO engagement_financial_revisions (engagement_id,giving_income_received,lodging_received,travel_received,book_table_received,notes,correction_reason,actor_user_id)
        VALUES (NEW.engagement_id,NEW.giving_income_received,NEW.lodging_received,NEW.travel_received,NEW.book_table_received,NEW.notes,'Initial closeout',NEW.closed_by);
    END IF;
END$$
CREATE TRIGGER financial_correction_before_update BEFORE UPDATE ON engagement_financial_reports FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 AND (NOT (OLD.giving_income_received <=> NEW.giving_income_received)
        OR NOT (OLD.lodging_received <=> NEW.lodging_received) OR NOT (OLD.travel_received <=> NEW.travel_received)
        OR NOT (OLD.book_table_received <=> NEW.book_table_received)
        OR NOT (OLD.notes <=> NEW.notes)) AND (COALESCE(TRIM(@dnr_financial_correction_reason),'')='' OR CHAR_LENGTH(@dnr_financial_correction_reason)>1000) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A financial correction reason is required';
    END IF;
END$$
CREATE TRIGGER financial_revision_after_update AFTER UPDATE ON engagement_financial_reports FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 AND (NOT (OLD.giving_income_received <=> NEW.giving_income_received)
        OR NOT (OLD.lodging_received <=> NEW.lodging_received) OR NOT (OLD.travel_received <=> NEW.travel_received)
        OR NOT (OLD.book_table_received <=> NEW.book_table_received)
        OR NOT (OLD.notes <=> NEW.notes)) THEN
        INSERT INTO engagement_financial_revisions (engagement_id,giving_income_received,lodging_received,travel_received,book_table_received,notes,correction_reason,actor_user_id)
        VALUES (NEW.engagement_id,NEW.giving_income_received,NEW.lodging_received,NEW.travel_received,NEW.book_table_received,NEW.notes,@dnr_financial_correction_reason,NEW.updated_by);
    END IF;
END$$
DELIMITER ;
