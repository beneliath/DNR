ALTER TABLE reimbursement_expenses
    ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN operation_token CHAR(32) NULL,
    ADD UNIQUE KEY uq_reimbursement_expense_operation (user_id, operation_token);
ALTER TABLE reimbursement_requests
    ADD COLUMN correction_note VARCHAR(1000) NOT NULL DEFAULT '',
    ADD COLUMN correction_at DATETIME NULL;
CREATE TABLE reimbursement_submissions (
    id INT PRIMARY KEY,
    attachment_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_ciphertext MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id) REFERENCES reimbursement_requests(id) ON DELETE RESTRICT,
    FOREIGN KEY (attachment_key) REFERENCES stored_files(storage_key)
) ENGINE=InnoDB;
-- Preserve packages from submissions made before this migration. Their original
-- recipient/message snapshots cannot be reconstructed after payload retirement.
INSERT INTO reimbursement_submissions (id, attachment_key, created_at)
SELECT d.request_id, MIN(d.attachment_key), MIN(d.created_at)
FROM reimbursement_email_deliveries d JOIN reimbursement_requests r ON r.id=d.request_id
WHERE r.status='submitted' GROUP BY d.request_id;
CREATE TABLE reimbursement_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(24) NOT NULL,
    entity_id INT NOT NULL,
    actor_id INT NULL,
    action VARCHAR(64) NOT NULL,
    detail VARCHAR(1000) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reimbursement_events_entity (entity_type, entity_id, id)
) ENGINE=InnoDB;
ALTER TABLE reimbursement_receipts
    ADD COLUMN scan_state ENUM('unscanned','queued','scanning','clean','rejected') NOT NULL DEFAULT 'unscanned',
    ADD COLUMN scan_token CHAR(32) NULL,
    ADD COLUMN scan_retry_at DATETIME NULL,
    ADD COLUMN scan_attempts INT NOT NULL DEFAULT 0,
    ADD COLUMN thumbnail_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD FOREIGN KEY (thumbnail_key) REFERENCES stored_files(storage_key);

ALTER TABLE reimbursement_email_deliveries MODIFY status ENUM('pending','processing','retry','sent','failed','delivery_uncertain','cancelled') NOT NULL DEFAULT 'pending';
