-- Unknown draft receipts stay NULL. Finalized reports and their aggregates remain unchanged.
CREATE TABLE engagement_financial_drafts (
    engagement_id INT PRIMARY KEY,
    giving_income_received DECIMAL(12,2) NULL,
    lodging_received DECIMAL(12,2) NULL,
    travel_received DECIMAL(12,2) NULL,
    notes TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_financial_draft_engagement FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_financial_draft_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_financial_draft_giving CHECK (giving_income_received IS NULL OR giving_income_received >= 0),
    CONSTRAINT chk_financial_draft_lodging CHECK (lodging_received IS NULL OR lodging_received >= 0),
    CONSTRAINT chk_financial_draft_travel CHECK (travel_received IS NULL OR travel_received >= 0)
);

CREATE TRIGGER audit_financial_drafts_after_insert AFTER INSERT ON engagement_financial_drafts
FOR EACH ROW INSERT INTO security_audit_log (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'engagement_financial_drafts', NEW.engagement_id, CONCAT('Engagement ', NEW.engagement_id, ' receipt draft'), LEFT(@dnr_request_ip, 45));
CREATE TRIGGER audit_financial_drafts_after_update AFTER UPDATE ON engagement_financial_drafts
FOR EACH ROW INSERT INTO security_audit_log (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'engagement_financial_drafts', NEW.engagement_id, CONCAT('Engagement ', NEW.engagement_id, ' receipt draft'), LEFT(@dnr_request_ip, 45));
CREATE TRIGGER audit_financial_drafts_after_delete AFTER DELETE ON engagement_financial_drafts
FOR EACH ROW INSERT INTO security_audit_log (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'engagement_financial_drafts', OLD.engagement_id, CONCAT('Engagement ', OLD.engagement_id, ' receipt draft'), LEFT(@dnr_request_ip, 45));
