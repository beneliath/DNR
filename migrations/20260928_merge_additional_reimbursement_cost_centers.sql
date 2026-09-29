-- Consolidate duplicate COA choices requested for reimbursements. Submitted
-- report snapshots retain the labels that were recorded at submission.
INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '5282', 'Dues & Subscriptions'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers
    WHERE coa_number = '5282' AND description = 'Dues & Subscriptions'
);

INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '5442', 'Security Mileage & Travel'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers
    WHERE coa_number = '5442' AND description = 'Security Mileage & Travel'
);

INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '5451', 'Reference Materials & Supplies'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers
    WHERE coa_number = '5451' AND description = 'Reference Materials & Supplies'
);

INSERT INTO reimbursement_cost_centers (coa_number, description)
SELECT '5676', 'Parking & Tolls'
WHERE NOT EXISTS (
    SELECT 1 FROM reimbursement_cost_centers
    WHERE coa_number = '5676' AND description = 'Parking & Tolls'
);

UPDATE reimbursement_expenses e
JOIN reimbursement_cost_centers old_center ON old_center.id = e.cost_center_id
JOIN reimbursement_cost_centers merged_center
    ON merged_center.coa_number = old_center.coa_number
   AND merged_center.description = CASE old_center.coa_number
       WHEN '5282' THEN 'Dues & Subscriptions'
       WHEN '5288' THEN 'Software'
       WHEN '5442' THEN 'Security Mileage & Travel'
       WHEN '5451' THEN 'Reference Materials & Supplies'
       WHEN '5676' THEN 'Parking & Tolls'
   END
SET e.cost_center_id = merged_center.id
WHERE (old_center.coa_number = '5282' AND old_center.description IN ('Dues', 'Subscriptions'))
   OR (old_center.coa_number = '5288' AND old_center.description = 'Software License/ Maintenance')
   OR (old_center.coa_number = '5442' AND old_center.description IN ('Security Mileage', 'Security Travel'))
   OR (old_center.coa_number = '5451' AND old_center.description IN ('Reference Materials', 'Supplies Ministry'))
   OR (old_center.coa_number = '5676' AND old_center.description IN ('Parking', 'Tolls'));

DELETE FROM reimbursement_cost_centers
WHERE (coa_number = '5282' AND description IN ('Dues', 'Subscriptions'))
   OR (coa_number = '5288' AND description = 'Software License/ Maintenance')
   OR (coa_number = '5442' AND description IN ('Security Mileage', 'Security Travel'))
   OR (coa_number = '5451' AND description IN ('Reference Materials', 'Supplies Ministry'))
   OR (coa_number = '5676' AND description IN ('Parking', 'Tolls'));
