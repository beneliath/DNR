-- Task-backed next actions retain legacy summary columns for pipeline/digest consumers.
ALTER TABLE booking_inquiries ADD COLUMN next_action_task_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_inquiry_next_action_task FOREIGN KEY (next_action_task_id)
        REFERENCES follow_up_tasks(id) ON DELETE SET NULL;
ALTER TABLE engagement_email_messages ADD COLUMN follow_up_task_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_email_follow_up_task FOREIGN KEY (follow_up_task_id)
        REFERENCES follow_up_tasks(id) ON DELETE SET NULL;

INSERT INTO follow_up_tasks (title, status, priority, due_date, subject_type, inquiry_id, assigned_to, created_by, template_key)
SELECT next_action, 'open', priority, next_action_due_date, 'inquiry', id, owner_user_id, created_by,
    CONCAT('inquiry.next-action.', id)
FROM booking_inquiries WHERE next_action IS NOT NULL AND TRIM(next_action) <> ''
    AND stage IN ('new','contacted','qualified','awaiting_details','proposal_sent') AND archived_at IS NULL;
UPDATE booking_inquiries i JOIN follow_up_tasks t ON t.template_key=CONCAT('inquiry.next-action.',i.id)
SET i.next_action_task_id=t.id;

DELIMITER $$
CREATE TRIGGER workflow_task_next_action_update AFTER UPDATE ON follow_up_tasks FOR EACH ROW
BEGIN
    UPDATE booking_inquiries SET
        next_action=IF(NEW.subject_type='inquiry' AND NEW.inquiry_id=id AND NEW.is_archived=0 AND NEW.status IN ('open','in_progress','waiting'), NEW.title, NULL),
        next_action_due_date=IF(NEW.subject_type='inquiry' AND NEW.inquiry_id=id AND NEW.is_archived=0 AND NEW.status IN ('open','in_progress','waiting'), NEW.due_date, NULL),
        owner_user_id=IF(NEW.subject_type='inquiry' AND NEW.inquiry_id=id AND NEW.is_archived=0 AND NEW.status IN ('open','in_progress','waiting'), NEW.assigned_to, owner_user_id),
        updated_at=GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(updated_at, INTERVAL 1 MICROSECOND))
    WHERE next_action_task_id=NEW.id AND stage <> 'booked';
END$$
CREATE TRIGGER workflow_task_next_action_delete BEFORE DELETE ON follow_up_tasks FOR EACH ROW
BEGIN
    UPDATE booking_inquiries SET next_action_task_id=NULL, next_action=NULL, next_action_due_date=NULL
    WHERE next_action_task_id=OLD.id AND stage <> 'booked';
END$$
DELIMITER ;
