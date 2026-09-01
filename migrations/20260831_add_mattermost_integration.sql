-- Add revocable Mattermost identities, one-time account-link codes, and
-- idempotency records for plugin-originated mutations. The shared service
-- credential remains outside the database in a deployment secret.
CREATE TABLE mattermost_link_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash BINARY(32) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT fk_mattermost_link_code_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mattermost_link_code_hash (code_hash),
    INDEX idx_mattermost_link_code_user_expiry (user_id, expires_at)
);

CREATE TABLE mattermost_user_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instance_id VARCHAR(100) NOT NULL,
    mattermost_user_id VARCHAR(64) NOT NULL,
    mattermost_username VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    linked_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_used_at DATETIME(6) NULL,
    CONSTRAINT fk_mattermost_user_link_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mattermost_external_identity (instance_id, mattermost_user_id),
    UNIQUE KEY uq_mattermost_moed_identity (instance_id, user_id),
    INDEX idx_mattermost_user_link_user (user_id, linked_at)
);

CREATE TABLE mattermost_idempotency_keys (
    instance_id VARCHAR(100) NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    action_name VARCHAR(50) NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    PRIMARY KEY (instance_id, idempotency_key),
    CONSTRAINT fk_mattermost_idempotency_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mattermost_idempotency_expiry (expires_at)
);
