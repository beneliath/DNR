ALTER TABLE reimbursement_expenses
    DROP CHECK chk_reimbursement_mileage,
    ADD CONSTRAINT chk_reimbursement_mileage CHECK (
        expense_type = 'expense' OR (
            origin_location IS NOT NULL AND destination_location IS NOT NULL
            AND odometer_start IS NOT NULL
            AND mileage_rate IS NOT NULL AND mileage_rate > 0
            AND odometer_start >= 0
            AND (odometer_end IS NULL OR odometer_end > odometer_start)
        )
    );
