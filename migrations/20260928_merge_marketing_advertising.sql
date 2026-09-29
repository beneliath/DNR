-- Replace the two COA 5025 choices with one shared category. Existing
-- submitted report snapshots keep their original labels.
INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '5025', 'Marketing & Advertising'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers
    WHERE coa_number = '5025' AND description = 'Marketing & Advertising'
);

UPDATE reimbursement_expenses e
JOIN reimbursement_cost_centers old_center ON old_center.id = e.cost_center_id
JOIN reimbursement_cost_centers merged_center
    ON merged_center.coa_number = '5025'
   AND merged_center.description = 'Marketing & Advertising'
SET e.cost_center_id = merged_center.id
WHERE old_center.coa_number = '5025'
  AND old_center.description IN ('Marketing', 'Advertising');

DELETE FROM reimbursement_cost_centers
WHERE coa_number = '5025' AND description IN ('Marketing', 'Advertising');
