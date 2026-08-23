-- Store actual receipts separately from the travel, lodging, and compensation
-- estimates captured while an engagement is being planned. One row is the
-- immutable closeout identity for one engagement; later corrections update the
-- amounts without changing the original closer or close date.
CREATE TABLE engagement_financial_reports (
    engagement_id INT PRIMARY KEY,
    giving_income_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lodging_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    travel_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    closed_by INT NULL,
    closed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_by INT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_financial_report_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_financial_report_closer
        FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_financial_report_updater
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_financial_report_giving
        CHECK (giving_income_received >= 0),
    CONSTRAINT chk_financial_report_lodging
        CHECK (lodging_received >= 0),
    CONSTRAINT chk_financial_report_travel
        CHECK (travel_received >= 0),
    INDEX idx_financial_report_closed_at (closed_at, engagement_id)
);

DROP TRIGGER IF EXISTS audit_engagement_financial_reports_after_insert;
DROP TRIGGER IF EXISTS audit_engagement_financial_reports_after_update;
DROP TRIGGER IF EXISTS audit_engagement_financial_reports_after_delete;

CREATE TRIGGER audit_engagement_financial_reports_after_insert
AFTER INSERT ON engagement_financial_reports
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_insert', 'engagement_financial_reports', NEW.engagement_id,
     CONCAT('Engagement ', NEW.engagement_id, ' financial report'),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_financial_reports_after_update
AFTER UPDATE ON engagement_financial_reports
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_update', 'engagement_financial_reports', NEW.engagement_id,
     CONCAT('Engagement ', NEW.engagement_id, ' financial report'),
     LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_engagement_financial_reports_after_delete
AFTER DELETE ON engagement_financial_reports
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type,
     entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change',
     'database_delete', 'engagement_financial_reports', OLD.engagement_id,
     CONCAT('Engagement ', OLD.engagement_id, ' financial report'),
     LEFT(@dnr_request_ip, 45));
