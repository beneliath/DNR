-- Preserve identities and history; existing rows are backfilled before new writers run.
ALTER TABLE organizations DROP INDEX organization_name,
    ADD INDEX idx_organization_name (organization_name);
ALTER TABLE reimbursement_setup ADD version BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE reimbursement_cost_centers ADD version BIGINT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE record_creation_operations (
    user_id INT NOT NULL,
    operation_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id, operation_token),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_creation_operation_age (created_at)
);

ALTER TABLE engagement_contacts
    ADD organization_id_snapshot INT NULL,
    ADD contact_first_name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
    ADD contact_last_name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
    ADD contact_email_snapshot VARCHAR(254) NOT NULL DEFAULT '',
    ADD contact_phone_snapshot VARCHAR(64) NOT NULL DEFAULT '',
    ADD role_title_snapshot VARCHAR(255) NOT NULL DEFAULT '';
UPDATE engagement_contacts ec JOIN engagements e ON e.id=ec.engagement_id
    JOIN contacts c ON c.id=ec.contact_id
    LEFT JOIN contact_organizations co ON co.contact_id=c.id AND co.organization_id=e.organization_id
SET ec.organization_id_snapshot=e.organization_id,
    ec.contact_first_name_snapshot=COALESCE(c.contact_first_name,''), ec.contact_last_name_snapshot=COALESCE(c.contact_last_name,''),
    ec.contact_email_snapshot=COALESCE(c.contact_email,''), ec.contact_phone_snapshot=COALESCE(c.contact_phone,''),
    ec.role_title_snapshot=COALESCE(co.role_title,'');
CREATE TABLE engagement_contact_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    contact_id INT NOT NULL,
    contact_role VARCHAR(32) NOT NULL,
    organization_id_snapshot INT NULL,
    contact_first_name_snapshot VARCHAR(255) NOT NULL,
    contact_last_name_snapshot VARCHAR(255) NOT NULL,
    contact_email_snapshot VARCHAR(254) NOT NULL,
    contact_phone_snapshot VARCHAR(64) NOT NULL,
    role_title_snapshot VARCHAR(255) NOT NULL,
    assigned_at TIMESTAMP(6) NOT NULL,
    ended_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ended_by INT NULL,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    KEY idx_event_contact_history (engagement_id, ended_at, id)
);
DROP TRIGGER prune_contact_organization_events_after_delete;
DROP TRIGGER prune_engagement_organization_contacts_after_update;
DROP TRIGGER validate_engagement_contact_organization_before_insert;
DROP TRIGGER validate_engagement_contact_organization_before_update;
DELIMITER $$
CREATE TRIGGER validate_engagement_contact_organization_before_insert
BEFORE INSERT ON engagement_contacts FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 THEN
        IF EXISTS (SELECT 1 FROM engagements e JOIN contact_organizations co ON co.organization_id=e.organization_id
            JOIN contacts c ON c.id=co.contact_id WHERE e.id=NEW.engagement_id AND co.contact_id=NEW.contact_id AND c.is_deleted=0) THEN
            SET NEW.organization_id_snapshot=(SELECT organization_id FROM engagements WHERE id=NEW.engagement_id);
            SET NEW.contact_first_name_snapshot=COALESCE((SELECT contact_first_name FROM contacts WHERE id=NEW.contact_id),'');
            SET NEW.contact_last_name_snapshot=COALESCE((SELECT contact_last_name FROM contacts WHERE id=NEW.contact_id),'');
            SET NEW.contact_email_snapshot=COALESCE((SELECT contact_email FROM contacts WHERE id=NEW.contact_id),'');
            SET NEW.contact_phone_snapshot=COALESCE((SELECT contact_phone FROM contacts WHERE id=NEW.contact_id),'');
            SET NEW.role_title_snapshot=COALESCE((SELECT role_title FROM contact_organizations WHERE contact_id=NEW.contact_id AND organization_id=NEW.organization_id_snapshot),'');
        ELSEIF NOT EXISTS (SELECT 1 FROM engagement_contact_history h WHERE h.engagement_id=NEW.engagement_id
                AND h.contact_id=NEW.contact_id AND h.contact_role=NEW.contact_role)
            AND NOT EXISTS (SELECT 1 FROM engagement_contacts ec WHERE ec.engagement_id=NEW.engagement_id
                AND ec.contact_role=NEW.contact_role AND ec.contact_id=@dnr_merge_source_contact_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='New event contacts must have an active organization affiliation';
        END IF;
    END IF;
END$$
CREATE TRIGGER validate_engagement_contact_organization_before_update
BEFORE UPDATE ON engagement_contacts FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 AND (OLD.engagement_id<>NEW.engagement_id OR OLD.contact_id<>NEW.contact_id OR OLD.contact_role<>NEW.contact_role)
        AND NOT EXISTS (SELECT 1 FROM engagements e JOIN contact_organizations co ON co.organization_id=e.organization_id
            JOIN contacts c ON c.id=co.contact_id WHERE e.id=NEW.engagement_id AND co.contact_id=NEW.contact_id AND c.is_deleted=0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Changed event contact identities must have an active affiliation';
    END IF;
END$$
CREATE TRIGGER preserve_engagement_contact_after_delete AFTER DELETE ON engagement_contacts FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 THEN
        INSERT INTO engagement_contact_history (engagement_id,contact_id,contact_role,organization_id_snapshot,
            contact_first_name_snapshot,contact_last_name_snapshot,contact_email_snapshot,contact_phone_snapshot,
            role_title_snapshot,assigned_at,ended_by)
        VALUES (OLD.engagement_id,OLD.contact_id,OLD.contact_role,OLD.organization_id_snapshot,
            OLD.contact_first_name_snapshot,OLD.contact_last_name_snapshot,OLD.contact_email_snapshot,
            OLD.contact_phone_snapshot,OLD.role_title_snapshot,OLD.created_at,@dnr_actor_user_id);
    END IF;
END$$
-- Snapshot contact assignments before a parent deletion (FK cascades bypass child triggers).
CREATE TRIGGER preserve_contact_event_history_before_delete BEFORE DELETE ON contacts FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 THEN
        INSERT INTO engagement_contact_history (engagement_id,contact_id,contact_role,organization_id_snapshot,
            contact_first_name_snapshot,contact_last_name_snapshot,contact_email_snapshot,contact_phone_snapshot,
            role_title_snapshot,assigned_at,ended_by)
        SELECT engagement_id,contact_id,contact_role,organization_id_snapshot,contact_first_name_snapshot,
            contact_last_name_snapshot,contact_email_snapshot,contact_phone_snapshot,role_title_snapshot,created_at,@dnr_actor_user_id
        FROM engagement_contacts WHERE contact_id=OLD.id;
    END IF;
END$$
DELIMITER ;

-- Closed financial data is retained indefinitely; corrections create immutable revisions.
ALTER TABLE engagement_financial_reports DROP FOREIGN KEY fk_financial_report_engagement,
    ADD CONSTRAINT fk_financial_report_retention FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE RESTRICT;
CREATE TABLE engagement_financial_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    giving_income_received DECIMAL(12,2) NOT NULL,
    lodging_received DECIMAL(12,2) NOT NULL,
    travel_received DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    correction_reason VARCHAR(1000) NOT NULL,
    actor_user_id INT NULL,
    recorded_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (engagement_id) REFERENCES engagement_financial_reports(engagement_id) ON DELETE RESTRICT,
    KEY idx_financial_revision_event (engagement_id,id)
);
INSERT INTO engagement_financial_revisions (engagement_id,giving_income_received,lodging_received,travel_received,notes,
    correction_reason,actor_user_id,recorded_at)
SELECT engagement_id,giving_income_received,lodging_received,travel_received,notes,'Existing report at migration',
    COALESCE(updated_by,closed_by),updated_at FROM engagement_financial_reports;
DELIMITER $$
CREATE TRIGGER financial_revision_after_insert AFTER INSERT ON engagement_financial_reports FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 THEN
        INSERT INTO engagement_financial_revisions (engagement_id,giving_income_received,lodging_received,travel_received,notes,correction_reason,actor_user_id)
        VALUES (NEW.engagement_id,NEW.giving_income_received,NEW.lodging_received,NEW.travel_received,NEW.notes,'Initial closeout',NEW.closed_by);
    END IF;
END$$
CREATE TRIGGER financial_correction_before_update BEFORE UPDATE ON engagement_financial_reports FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 AND (NOT (OLD.giving_income_received <=> NEW.giving_income_received)
        OR NOT (OLD.lodging_received <=> NEW.lodging_received) OR NOT (OLD.travel_received <=> NEW.travel_received)
        OR NOT (OLD.notes <=> NEW.notes)) AND (COALESCE(TRIM(@dnr_financial_correction_reason),'')='' OR CHAR_LENGTH(@dnr_financial_correction_reason)>1000) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A financial correction reason is required';
    END IF;
END$$
CREATE TRIGGER financial_revision_after_update AFTER UPDATE ON engagement_financial_reports FOR EACH ROW
BEGIN
    IF @@foreign_key_checks=1 AND (NOT (OLD.giving_income_received <=> NEW.giving_income_received)
        OR NOT (OLD.lodging_received <=> NEW.lodging_received) OR NOT (OLD.travel_received <=> NEW.travel_received)
        OR NOT (OLD.notes <=> NEW.notes)) THEN
        INSERT INTO engagement_financial_revisions (engagement_id,giving_income_received,lodging_received,travel_received,notes,correction_reason,actor_user_id)
        VALUES (NEW.engagement_id,NEW.giving_income_received,NEW.lodging_received,NEW.travel_received,NEW.notes,@dnr_financial_correction_reason,NEW.updated_by);
    END IF;
END$$
DELIMITER ;

CREATE TABLE record_merge_journal (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(20) NOT NULL,
    source_id INT NOT NULL,
    target_id INT NOT NULL,
    snapshot_ciphertext MEDIUMTEXT NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at TIMESTAMP(6) NOT NULL,
    undone_at TIMESTAMP(6) NULL,
    undone_by INT NULL,
    KEY idx_merge_expiry (expires_at),
    KEY idx_merge_target (entity_type,target_id,id)
);
CREATE TABLE data_management_health (
    name VARCHAR(50) NOT NULL PRIMARY KEY,
    state_json JSON NOT NULL,
    checked_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
);
