CREATE TABLE reimbursement_setup (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    organization_name VARCHAR(160) NOT NULL,
    bookkeeper_first_name VARCHAR(80) NOT NULL DEFAULT '',
    bookkeeper_last_name VARCHAR(80) NOT NULL DEFAULT '',
    bookkeeper_email VARCHAR(254) NOT NULL DEFAULT '',
    bookkeeper_phone VARCHAR(40) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_reimbursement_setup_singleton CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO reimbursement_setup (id, organization_name)
VALUES (1, 'Shalom in Messiah');
