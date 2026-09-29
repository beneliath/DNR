-- Retain this display value with the financial record after encrypted delivery
-- recovery metadata expires. Do not invent a historical name for old requests.
ALTER TABLE reimbursement_requests ADD COLUMN owner_name_snapshot VARCHAR(255) NULL;
