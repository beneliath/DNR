CREATE TABLE reimbursement_cost_centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coa_number VARCHAR(20) NOT NULL,
    description VARCHAR(120) NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reimbursement_cost_center (coa_number, description),
    KEY idx_reimbursement_cost_center_status (is_archived, coa_number, description),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE reimbursement_expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    expense_date DATE NOT NULL,
    merchant VARCHAR(160) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    amount_cents INT UNSIGNED NOT NULL,
    cost_center_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (cost_center_id) REFERENCES reimbursement_cost_centers(id),
    KEY idx_reimbursement_expense_owner_date (user_id, expense_date, id)
) ENGINE=InnoDB;

CREATE TABLE reimbursement_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    filename VARCHAR(255) NOT NULL,
    content_type VARCHAR(127) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expense_id) REFERENCES reimbursement_expenses(id) ON DELETE CASCADE,
    FOREIGN KEY (storage_key) REFERENCES stored_files(storage_key),
    KEY idx_reimbursement_receipt_expense (expense_id, id)
) ENGINE=InnoDB;

CREATE TABLE reimbursement_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    KEY idx_reimbursement_request_owner (user_id, created_at, id),
    CONSTRAINT chk_reimbursement_request_dates CHECK (start_date <= end_date)
) ENGINE=InnoDB;

CREATE TABLE reimbursement_request_items (
    request_id INT NOT NULL,
    expense_id INT NOT NULL,
    expense_date DATE NULL,
    merchant VARCHAR(160) NULL,
    description VARCHAR(500) NULL,
    amount_cents INT UNSIGNED NULL,
    coa_number VARCHAR(20) NULL,
    coa_description VARCHAR(120) NULL,
    PRIMARY KEY (request_id, expense_id),
    UNIQUE KEY uq_reimbursement_expense_once (expense_id),
    FOREIGN KEY (request_id) REFERENCES reimbursement_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (expense_id) REFERENCES reimbursement_expenses(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO reimbursement_cost_centers (coa_number, description) VALUES
('5478','Lodging'),('5225','Food'),('5451','Supplies Ministry'),
('5425','Supplies Maintenance'),('5284','Supplies Office'),
('5282','Subscriptions'),('5282','Dues'),('5451','Reference Materials'),
('5025','Advertising'),('5285','Printing/Duplication'),
('5286','Postage/Shipping'),('5315','Honoraria'),
('5125','Professional Services'),('5325','Cell Phone'),
('5325','Internet'),('5289','Office Phone / Fax'),
('5425','Maint/Repair - Equip'),('5252','Facility Rentals / Lease'),
('5288','Software'),('5288','Software License/ Maintenance'),
('5025','Marketing'),('5375','Registration/Participation'),
('5277','Banking Fees'),('5279','Equipment Lease'),
('5287','Equipment Purchase'),('5291','Server Computer Equipment'),
('5292','Personal Computer Equipment'),('5293','Peripherals & Electronics'),
('5294','General Office Equipment'),('5441','Security EQ'),
('5442','Security Travel'),('5442','Security Mileage'),
('5443','Security Lodging'),('5444','Security Meals'),
('5445','Security Misc.'),('5446','Security Insurance'),
('5480','Car Rental'),('5678','Gas'),('5479','Taxi/Train/Uber'),
('5676','Tolls'),('5676','Parking'),('5476','Airfare'),
('5410','Spousal Travel'),('5481','Travel Insurance');
