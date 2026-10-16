INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '6110', 'Mileage Reimbursement'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers WHERE coa_number = '6110'
);

-- Reclassify unsubmitted mileage; submitted reports retain their approved accounts.
UPDATE reimbursement_expenses e
JOIN reimbursement_cost_centers c ON c.coa_number = '6110'
LEFT JOIN reimbursement_request_items ri ON ri.expense_id = e.id
LEFT JOIN reimbursement_requests r ON r.id = ri.request_id
SET e.cost_center_id = c.id, e.version = e.version + 1
WHERE e.expense_type = 'mileage' AND e.cost_center_id <> c.id
    AND (r.id IS NULL OR r.status = 'draft');
