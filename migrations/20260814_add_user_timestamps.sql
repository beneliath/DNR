-- Apply to databases created before user timestamps were included in init.sql.
-- Safe to run more than once on MySQL 8.

SET @has_created_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'created_at'
);
SET @add_created_at = IF(
    @has_created_at = 0,
    'ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE add_created_at_statement FROM @add_created_at;
EXECUTE add_created_at_statement;
DEALLOCATE PREPARE add_created_at_statement;

SET @has_last_updated_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'last_updated_at'
);
SET @add_last_updated_at = IF(
    @has_last_updated_at = 0,
    'ALTER TABLE users ADD COLUMN last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE add_last_updated_at_statement FROM @add_last_updated_at;
EXECUTE add_last_updated_at_statement;
DEALLOCATE PREPARE add_last_updated_at_statement;
