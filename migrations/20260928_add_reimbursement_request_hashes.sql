ALTER TABLE reimbursement_requests
    ADD COLUMN request_hash CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id;

UPDATE reimbursement_requests SET request_hash = LOWER(HEX(RANDOM_BYTES(8)))
WHERE request_hash IS NULL;

ALTER TABLE reimbursement_requests
    MODIFY request_hash CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD UNIQUE KEY uq_reimbursement_request_hash (request_hash);
