-- Add unique constraint to organization_name
ALTER TABLE organizations ADD CONSTRAINT unique_organization_name UNIQUE (organization_name); 