-- Track operational event state separately from confirmation workflow and
-- archival. Existing financially closed engagements are backfilled as
-- completed; every other active record begins in the active lifecycle.
ALTER TABLE engagements
    ADD COLUMN lifecycle_status
        ENUM('active', 'postponed', 'canceled', 'completed')
        NOT NULL DEFAULT 'active' AFTER confirmation_status,
    ADD COLUMN cancellation_reason VARCHAR(1000) NULL AFTER lifecycle_status,
    ADD COLUMN rescheduled_to_engagement_id INT NULL AFTER cancellation_reason,
    ADD COLUMN lifecycle_changed_by INT NULL AFTER rescheduled_to_engagement_id,
    ADD COLUMN lifecycle_changed_at DATETIME(6) NOT NULL
        DEFAULT CURRENT_TIMESTAMP(6) AFTER lifecycle_changed_by,
    ADD INDEX idx_engagements_lifecycle_date (
        is_deleted, lifecycle_status, event_start_date, id
    ),
    ADD INDEX idx_engagements_rescheduled_to (rescheduled_to_engagement_id),
    ADD INDEX idx_engagements_lifecycle_changed_by (lifecycle_changed_by);

UPDATE engagements engagement
INNER JOIN engagement_financial_reports report
        ON report.engagement_id = engagement.id
SET engagement.lifecycle_status = 'completed',
    engagement.lifecycle_changed_at = report.closed_at;

ALTER TABLE engagements
    ADD CONSTRAINT chk_engagement_cancellation_reason CHECK (
        (lifecycle_status = 'canceled'
            AND cancellation_reason IS NOT NULL
            AND CHAR_LENGTH(TRIM(cancellation_reason)) > 0)
        OR (lifecycle_status <> 'canceled' AND cancellation_reason IS NULL)
    );

ALTER TABLE engagements
    ADD CONSTRAINT fk_engagement_rescheduled_to
        FOREIGN KEY (rescheduled_to_engagement_id)
        REFERENCES engagements(id) ON DELETE SET NULL;

ALTER TABLE engagements
    ADD CONSTRAINT fk_engagement_lifecycle_changer
        FOREIGN KEY (lifecycle_changed_by)
        REFERENCES users(id) ON DELETE SET NULL;

DROP TRIGGER IF EXISTS validate_engagement_lifecycle_before_insert;
DROP TRIGGER IF EXISTS validate_engagement_lifecycle_before_update;

DELIMITER $$
CREATE TRIGGER validate_engagement_lifecycle_before_insert
BEFORE INSERT ON engagements
FOR EACH ROW
BEGIN
    IF NEW.rescheduled_to_engagement_id IS NOT NULL
       AND NEW.lifecycle_status NOT IN ('postponed', 'canceled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only postponed or canceled engagements can link to a replacement';
    END IF;
    IF NEW.rescheduled_to_engagement_id IS NOT NULL
       AND NEW.id IS NOT NULL
       AND NEW.id <> 0
       AND NEW.rescheduled_to_engagement_id = NEW.id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'An engagement cannot be rescheduled to itself';
    END IF;
END$$

CREATE TRIGGER validate_engagement_lifecycle_before_update
BEFORE UPDATE ON engagements
FOR EACH ROW
BEGIN
    IF NEW.rescheduled_to_engagement_id IS NOT NULL
       AND NEW.lifecycle_status NOT IN ('postponed', 'canceled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only postponed or canceled engagements can link to a replacement';
    END IF;
    IF NEW.rescheduled_to_engagement_id IS NOT NULL
       AND NEW.rescheduled_to_engagement_id = NEW.id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'An engagement cannot be rescheduled to itself';
    END IF;
END$$
DELIMITER ;

ALTER TABLE engagements
    DROP INDEX ft_engagements_search,
    ADD FULLTEXT INDEX ft_engagements_search (
        event_title, event_description, engagement_notes, caller_name,
        cancellation_reason
    );
