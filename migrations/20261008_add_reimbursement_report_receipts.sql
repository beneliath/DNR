ALTER TABLE reimbursement_receipts
    ADD COLUMN report_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN report_attempted_at DATETIME NULL,
    ADD COLUMN report_status ENUM('pending','ready','failed') NOT NULL DEFAULT 'pending',
    ADD FOREIGN KEY (report_key) REFERENCES stored_files(storage_key);
