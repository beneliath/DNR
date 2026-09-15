-- The all-work calendar has no assignee predicate. Keep date-window reads
-- independent of the size of the active task backlog.
ALTER TABLE follow_up_tasks
    ADD INDEX idx_follow_up_task_calendar (is_archived, status, due_date, id);

-- Task owner names appear in calendar feeds. Ordinary login/security updates
-- must not invalidate every subscriber's cached feed.
DELIMITER $$
CREATE TRIGGER calendar_users_after_update AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF NOT (BINARY OLD.username <=> BINARY NEW.username) THEN
        UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
    END IF;
END$$

-- Foreign-key SET NULL actions do not execute follow_up_tasks update triggers.
CREATE TRIGGER calendar_users_after_delete AFTER DELETE ON users
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1$$

CREATE TRIGGER calendar_inquiries_after_update AFTER UPDATE ON booking_inquiries
FOR EACH ROW
BEGIN
    IF NOT (OLD.archived_at <=> NEW.archived_at) THEN
        UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
    END IF;
END$$
CREATE TRIGGER calendar_inquiries_after_delete AFTER DELETE ON booking_inquiries
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1$$
DELIMITER ;

-- Support exact title/name token-prefix searches without scanning LIKE '%...%'.
ALTER TABLE engagements ADD FULLTEXT INDEX ft_engagement_title (event_title);
ALTER TABLE engagements ADD INDEX idx_engagement_active_title (is_deleted, event_title, id);
ALTER TABLE organizations ADD FULLTEXT INDEX ft_organization_name (organization_name);
ALTER TABLE contacts ADD FULLTEXT INDEX ft_contact_identity (contact_first_name, contact_last_name, contact_email);
ALTER TABLE contacts ADD INDEX idx_contact_active_first_name (is_deleted, contact_first_name, id);
