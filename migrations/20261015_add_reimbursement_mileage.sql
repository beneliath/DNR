ALTER TABLE reimbursement_setup
    ADD COLUMN mileage_rate DECIMAL(5,3) NOT NULL DEFAULT 0.760;

ALTER TABLE reimbursement_expenses
    ADD COLUMN expense_type ENUM('expense', 'mileage') NOT NULL DEFAULT 'expense',
    ADD COLUMN origin_location VARCHAR(160) NULL,
    ADD COLUMN destination_location VARCHAR(160) NULL,
    ADD COLUMN odometer_start DECIMAL(9,2) NULL,
    ADD COLUMN odometer_end DECIMAL(9,2) NULL,
    ADD COLUMN mileage_rate DECIMAL(5,3) NULL,
    ADD CONSTRAINT chk_reimbursement_mileage CHECK (
        expense_type = 'expense' OR (
            origin_location IS NOT NULL AND destination_location IS NOT NULL
            AND odometer_start IS NOT NULL AND odometer_end IS NOT NULL
            AND mileage_rate IS NOT NULL AND mileage_rate > 0
            AND odometer_start >= 0 AND odometer_end > odometer_start
        )
    );
