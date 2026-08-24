-- Make the standard engagement checklist configurable.
CREATE TABLE IF NOT EXISTS standard_event_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent')
        NOT NULL DEFAULT 'normal',
    due_anchor ENUM('event_start', 'event_end')
        NOT NULL DEFAULT 'event_start',
    due_offset_days SMALLINT NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_by INT NULL,
    archived_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_standard_event_task_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_standard_event_task_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_standard_event_task_archive CHECK (
        (is_archived = 0 AND archived_at IS NULL)
        OR (is_archived = 1 AND archived_at IS NOT NULL)
    ),
    UNIQUE KEY uq_standard_event_task_key (template_key),
    INDEX idx_standard_event_task_list (is_archived, sort_order, id)
);

INSERT IGNORE INTO standard_event_tasks
    (template_key, title, priority, due_anchor, due_offset_days, sort_order)
VALUES
    ('standard.confirm_location',
     'Confirm the venue address and on-site contact',
     'high', 'event_start', -30, 10),
    ('standard.confirm_travel_lodging',
     'Confirm travel and lodging arrangements',
     'high', 'event_start', -30, 20),
    ('standard.confirm_presentations',
     'Confirm presentation topics, dates, and times',
     'high', 'event_start', -21, 30),
    ('standard.confirm_materials',
     'Confirm book table, brochures, and event materials',
     'normal', 'event_start', -14, 40),
    ('standard.host_reconfirmation',
     'Reconfirm final details with the host',
     'high', 'event_start', -7, 50),
    ('standard.prepare_materials',
     'Prepare presentations, handouts, books, and supplies',
     'high', 'event_start', -3, 60),
    ('standard.send_thanks',
     'Send a post-event thank-you',
     'normal', 'event_end', 1, 70),
    ('standard.record_outcomes',
     'Record attendance, feedback, and notable outcomes',
     'normal', 'event_end', 2, 80),
    ('standard.financial_closeout',
     'Complete financial and reimbursement follow-up',
     'high', 'event_end', 7, 90);

DROP TRIGGER IF EXISTS audit_standard_event_tasks_after_insert;
DROP TRIGGER IF EXISTS audit_standard_event_tasks_after_update;
DROP TRIGGER IF EXISTS audit_standard_event_tasks_after_delete;

CREATE TRIGGER audit_standard_event_tasks_after_insert
AFTER INSERT ON standard_event_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'standard_event_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_standard_event_tasks_after_update
AFTER UPDATE ON standard_event_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'standard_event_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_standard_event_tasks_after_delete
AFTER DELETE ON standard_event_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'standard_event_tasks', OLD.id, LEFT(OLD.title, 255), LEFT(@dnr_request_ip, 45));
