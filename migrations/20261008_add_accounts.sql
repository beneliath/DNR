-- Each Account has a separate database, file store, session store and workers.
-- This migration labels the existing database; no business records are moved.
CREATE TABLE account_profile (
    id TINYINT UNSIGNED PRIMARY KEY,
    account_key VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    settings JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_single_account_profile CHECK (id = 1)
);
INSERT INTO account_profile (id, account_key, name, settings)
VALUES (1, 'shalom-in-messiah', 'Shalom in Messiah', JSON_OBJECT());

ALTER TABLE users
    ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN platform_identity_id INT NULL,
    ADD UNIQUE KEY uq_platform_identity (platform_identity_id);

-- Preserve passwords, MFA, record ownership, and every existing record ID.
UPDATE users SET role = 'admin', is_superadmin = 1, auth_version = auth_version + 1
WHERE username = 'dgilmore';
UPDATE users SET role = 'editor', auth_version = auth_version + 1 WHERE username = 'omelnick';
UPDATE users SET role = 'reviewer', account_status = 'inactive',
    deactivated_at = COALESCE(deactivated_at, UTC_TIMESTAMP()), auth_version = auth_version + 1
WHERE username = 'dnewberry';

-- Only the primary deployment uses the platform directory. Member deployments
-- cannot query it: they communicate through an authenticated, bounded API.
CREATE TABLE platform_accounts (
    account_key VARCHAR(64) PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    public_url VARCHAR(255) NULL UNIQUE,
    state ENUM('pending', 'provisioning', 'ready', 'disabled') NOT NULL DEFAULT 'pending',
    api_key_encrypted TEXT NOT NULL,
    requested_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id)
);
CREATE TABLE platform_access_tickets (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    account_key VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    FOREIGN KEY (account_key) REFERENCES platform_accounts(account_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_platform_ticket_expiry (expires_at)
);
CREATE TABLE platform_api_nonces (
    account_key VARCHAR(64) NOT NULL,
    nonce CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (account_key, nonce),
    FOREIGN KEY (account_key) REFERENCES platform_accounts(account_key)
);
