ALTER TABLE reimbursement_expenses
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER cost_center_id,
    ADD KEY idx_reimbursement_expense_archive (user_id, is_archived, expense_date, id);
