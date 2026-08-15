-- Add update timestamps used to keep the shared calendar feed current.
-- Safe to run more than once on MySQL 8.

SET @has_engagement_updated_at = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'engagements'
      AND column_name = 'updated_at'
);
SET @add_engagement_updated_at = IF(
    @has_engagement_updated_at = 0,
    'ALTER TABLE engagements ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE add_engagement_updated_at_statement FROM @add_engagement_updated_at;
EXECUTE add_engagement_updated_at_statement;
DEALLOCATE PREPARE add_engagement_updated_at_statement;

SET @has_organization_updated_at = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'organizations'
      AND column_name = 'updated_at'
);
SET @add_organization_updated_at = IF(
    @has_organization_updated_at = 0,
    'ALTER TABLE organizations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE add_organization_updated_at_statement FROM @add_organization_updated_at;
EXECUTE add_organization_updated_at_statement;
DEALLOCATE PREPARE add_organization_updated_at_statement;
