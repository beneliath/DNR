-- Consolidate the two COA 5325 choices while retaining submitted report snapshots.
INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '5325', 'Internet & Phone'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers
    WHERE coa_number = '5325' AND description = 'Internet & Phone'
);

UPDATE reimbursement_expenses e
JOIN reimbursement_cost_centers old_center ON old_center.id = e.cost_center_id
JOIN reimbursement_cost_centers merged_center
    ON merged_center.coa_number = '5325'
   AND merged_center.description = 'Internet & Phone'
SET e.cost_center_id = merged_center.id
WHERE old_center.coa_number = '5325'
  AND old_center.description IN ('Cell Phone', 'Internet');

DELETE FROM reimbursement_cost_centers
WHERE coa_number = '5325' AND description IN ('Cell Phone', 'Internet');
