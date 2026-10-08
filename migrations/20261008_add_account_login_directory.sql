-- The shared sign-in resolves a globally unique username without exposing the
-- Account directory. Passwords and MFA remain in the user's private Account.
CREATE TABLE platform_login_routes (
    username VARCHAR(50) NOT NULL PRIMARY KEY,
    account_key VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_login_owner (account_key, user_id)
);
INSERT INTO platform_login_routes (username, account_key, user_id)
SELECT username, (SELECT account_key FROM account_profile WHERE id = 1), id
FROM users WHERE platform_identity_id IS NULL;

-- Password verification permits only the MFA step, never an authenticated session.
CREATE TABLE account_login_handoffs (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_account_login_expiry (expires_at)
);
CREATE TABLE account_service_nonces (
    nonce CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_account_service_expiry (expires_at)
);
