-- Add assignable follow-up work linked to DNR records.
CREATE TABLE IF NOT EXISTS follow_up_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    details TEXT NULL,
    status ENUM('open', 'in_progress', 'waiting', 'completed', 'canceled')
        NOT NULL DEFAULT 'open',
    priority ENUM('low', 'normal', 'high', 'urgent')
        NOT NULL DEFAULT 'normal',
    due_date DATE NULL,
    waiting_on VARCHAR(255) NULL,
    subject_type ENUM('general', 'engagement', 'organization', 'contact')
        NOT NULL DEFAULT 'general',
    engagement_id INT NULL,
    organization_id INT NULL,
    contact_id INT NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    completed_by INT NULL,
    template_key VARCHAR(100) NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_follow_up_task_engagement
        FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_task_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_task_contact
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_up_task_assignee
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_follow_up_task_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_follow_up_task_completer
        FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_follow_up_task_subject CHECK (
        (subject_type = 'general'
            AND engagement_id IS NULL
            AND organization_id IS NULL
            AND contact_id IS NULL)
        OR (subject_type = 'engagement'
            AND engagement_id IS NOT NULL
            AND organization_id IS NULL
            AND contact_id IS NULL)
        OR (subject_type = 'organization'
            AND engagement_id IS NULL
            AND organization_id IS NOT NULL
            AND contact_id IS NULL)
        OR (subject_type = 'contact'
            AND engagement_id IS NULL
            AND organization_id IS NULL
            AND contact_id IS NOT NULL)
    ),
    CONSTRAINT chk_follow_up_task_completion CHECK (
        (status = 'completed' AND completed_at IS NOT NULL)
        OR (status <> 'completed' AND completed_at IS NULL)
    ),
    UNIQUE KEY uq_follow_up_task_engagement_template (engagement_id, template_key),
    INDEX idx_follow_up_task_queue (status, assigned_to, due_date, priority),
    INDEX idx_follow_up_task_engagement (engagement_id, status, due_date),
    INDEX idx_follow_up_task_organization (organization_id, status, due_date),
    INDEX idx_follow_up_task_contact (contact_id, status, due_date),
    INDEX idx_follow_up_task_updated (updated_at, id)
);

DROP TRIGGER IF EXISTS audit_follow_up_tasks_after_insert;
DROP TRIGGER IF EXISTS audit_follow_up_tasks_after_update;
DROP TRIGGER IF EXISTS audit_follow_up_tasks_after_delete;

CREATE TRIGGER audit_follow_up_tasks_after_insert
AFTER INSERT ON follow_up_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_insert', 'follow_up_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_follow_up_tasks_after_update
AFTER UPDATE ON follow_up_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update', 'follow_up_tasks', NEW.id, LEFT(NEW.title, 255), LEFT(@dnr_request_ip, 45));

CREATE TRIGGER audit_follow_up_tasks_after_delete
AFTER DELETE ON follow_up_tasks
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'follow_up_tasks', OLD.id, LEFT(OLD.title, 255), LEFT(@dnr_request_ip, 45));
