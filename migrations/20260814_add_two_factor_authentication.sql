ALTER TABLE users
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER auth_version,
    ADD COLUMN totp_secret_encrypted TEXT NULL AFTER two_factor_enabled,
    ADD COLUMN totp_confirmed_at DATETIME NULL AFTER totp_secret_encrypted,
    ADD COLUMN totp_last_used_step BIGINT UNSIGNED NULL AFTER totp_confirmed_at,
    ADD COLUMN login_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_last_used_step,
    ADD COLUMN login_locked_until DATETIME NULL AFTER login_failed_attempts,
    ADD COLUMN two_factor_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER login_locked_until,
    ADD COLUMN two_factor_locked_until DATETIME NULL AFTER two_factor_failed_attempts;

CREATE TABLE user_recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recovery_code_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_recovery_codes_user_unused (user_id, used_at)
);

CREATE TABLE security_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    target_user_id INT NULL,
    event_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_security_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_security_audit_target
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_security_audit_target_created (target_user_id, created_at)
);
