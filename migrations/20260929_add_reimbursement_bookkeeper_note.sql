ALTER TABLE reimbursement_requests
    ADD COLUMN bookkeeper_note VARCHAR(1000) NOT NULL DEFAULT '' AFTER end_date;
