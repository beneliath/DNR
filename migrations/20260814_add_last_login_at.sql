SET @has_last_login_at = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'last_login_at'
);

SET @add_last_login_at = IF(
    @has_last_login_at = 0,
    'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER last_updated_at',
    'SELECT 1'
);

PREPARE add_last_login_at_statement FROM @add_last_login_at;
EXECUTE add_last_login_at_statement;
DEALLOCATE PREPARE add_last_login_at_statement;

UPDATE users AS user_account
JOIN (
    SELECT target_user_id, MAX(created_at) AS most_recent_login
    FROM security_audit_log
    WHERE event_type IN ('two_factor_login', 'recovery_code_login')
    GROUP BY target_user_id
) AS login_history
    ON login_history.target_user_id = user_account.id
SET user_account.last_login_at = login_history.most_recent_login,
    user_account.last_updated_at = user_account.last_updated_at
WHERE user_account.last_login_at IS NULL;
