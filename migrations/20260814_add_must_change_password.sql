SET @has_must_change_password = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'must_change_password'
);

SET @add_must_change_password = IF(
    @has_must_change_password = 0,
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password',
    'SELECT 1'
);

PREPARE add_must_change_password_statement FROM @add_must_change_password;
EXECUTE add_must_change_password_statement;
DEALLOCATE PREPARE add_must_change_password_statement;
