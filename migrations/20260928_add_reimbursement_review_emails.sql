ALTER TABLE reimbursement_setup
    ADD COLUMN reviewer_email VARCHAR(254) NOT NULL DEFAULT '' AFTER bookkeeper_phone,
    ADD COLUMN cc_email VARCHAR(254) NOT NULL DEFAULT '' AFTER reviewer_email;
