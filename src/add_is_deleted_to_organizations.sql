-- Add is_deleted column to organizations table
ALTER TABLE organizations ADD COLUMN is_deleted TINYINT(1) DEFAULT 0; 