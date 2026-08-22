-- User access is retained as durable account state so administrators can
-- suspend access without deleting the user or severing audit references.
ALTER TABLE users
    ADD COLUMN account_status ENUM('invited', 'active', 'inactive')
        NOT NULL DEFAULT 'active' AFTER role,
    ADD COLUMN activated_at DATETIME NULL AFTER account_status,
    ADD COLUMN deactivated_at DATETIME NULL AFTER activated_at,
    ADD COLUMN email_verified_at DATETIME NULL AFTER email,
    ADD COLUMN verified_email VARCHAR(254)
        GENERATED ALWAYS AS (
            CASE
                WHEN email_verified_at IS NOT NULL THEN LOWER(email)
                ELSE NULL
            END
        ) STORED AFTER email_verified_at,
    ADD UNIQUE INDEX uq_users_verified_email (verified_email),
    ADD INDEX idx_users_status_role (account_status, role, username);

UPDATE users
SET activated_at = COALESCE(activated_at, created_at)
WHERE account_status = 'active';

-- Invitation, verification, and recovery links share the same deliberately
-- small token store. Only SHA-256 digests are persisted; raw bearer tokens
-- exist only in the email link delivered to the user.
CREATE TABLE user_email_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    purpose ENUM('invitation', 'verification', 'recovery') NOT NULL,
    email VARCHAR(254) NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    token_hash BINARY(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_email_token_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_email_token_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE INDEX uq_user_email_token_hash (token_hash),
    INDEX idx_user_email_token_lookup (purpose, token_hash, consumed_at, expires_at),
    INDEX idx_user_email_token_user (user_id, purpose, auth_version, consumed_at, created_at)
);
