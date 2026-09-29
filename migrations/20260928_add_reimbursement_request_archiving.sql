ALTER TABLE reimbursement_requests
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD KEY idx_reimbursement_request_archive (is_archived, created_at, id);
