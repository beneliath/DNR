-- Authentication throttles are deliberately scoped to a source or account.
-- Existing global/IP counters are transient and can be discarded safely.
DELETE FROM authentication_rate_limits;

ALTER TABLE authentication_rate_limits
    MODIFY scope ENUM(
        'login_ip',
        'login_account',
        'recovery_ip',
        'recovery_account'
    ) NOT NULL;

-- Recovery codes previously used slow password hashes that required a serial
-- scan. Invalidate them and switch to an indexed, keyed digest. Users retain
-- their enrolled authenticator and can generate a fresh recovery-code set.
DELETE FROM user_recovery_codes;

ALTER TABLE user_recovery_codes
    DROP INDEX idx_recovery_codes_user_unused,
    DROP COLUMN code_hash,
    ADD COLUMN code_lookup_hash BINARY(32) NOT NULL AFTER user_id,
    ADD UNIQUE INDEX uq_recovery_code_user_lookup (user_id, code_lookup_hash),
    ADD INDEX idx_recovery_codes_user_unused (user_id, used_at);

CREATE TABLE calendar_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NOT NULL DEFAULT 'Calendar subscription',
    token_hash BINARY(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    CONSTRAINT fk_calendar_subscription_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_calendar_subscription_token (token_hash),
    INDEX idx_calendar_subscription_user_active (user_id, revoked_at, created_at)
);

ALTER TABLE users
    ADD COLUMN profile_picture_sha256 BINARY(32) NULL AFTER profile_picture_mime;

UPDATE users
SET profile_picture_sha256 = UNHEX(SHA2(profile_picture, 256))
WHERE profile_picture IS NOT NULL;

ALTER TABLE contacts
    ADD COLUMN contact_photo_sha256 BINARY(32) NULL AFTER contact_photo_mime;

UPDATE contacts
SET contact_photo_sha256 = UNHEX(SHA2(contact_photo, 256))
WHERE contact_photo IS NOT NULL;

ALTER TABLE presentations
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

CREATE INDEX idx_organizations_active_name
    ON organizations (is_deleted, organization_name, id);
CREATE INDEX idx_engagements_active_date
    ON engagements (is_deleted, event_start_date, id);
CREATE INDEX idx_engagements_active_status_date
    ON engagements (is_deleted, confirmation_status, event_start_date, id);
CREATE INDEX idx_contacts_active_name
    ON contacts (is_deleted, contact_last_name, contact_first_name, id);
CREATE INDEX idx_contacts_organization_active_name
    ON contacts (organization_id, is_deleted, contact_last_name, contact_first_name, id);

CREATE FULLTEXT INDEX ft_organizations_search
    ON organizations (
        organization_name, notes, affiliation, distinctives, email, phone,
        physical_city, physical_state, mailing_city, mailing_state
    );
CREATE FULLTEXT INDEX ft_engagements_search
    ON engagements (event_title, event_description, engagement_notes, caller_name);
CREATE FULLTEXT INDEX ft_contacts_search
    ON contacts (
        contact_first_name, contact_last_name, contact_email, contact_phone,
        contact_role_other, contact_notes
    );
CREATE FULLTEXT INDEX ft_chron_entries_search
    ON engagement_chron_entries (entry_text, created_by_username_snapshot);
CREATE FULLTEXT INDEX ft_follow_up_tasks_search
    ON follow_up_tasks (title, details, waiting_on);
CREATE FULLTEXT INDEX ft_security_audit_search
    ON security_audit_log (
        actor_username, target_username, event_category, event_type,
        entity_type, entity_label, details, ip_address
    );

CREATE TABLE engagement_map_geocode_queue (
    address_hash CHAR(64) PRIMARY KEY,
    address_query VARCHAR(1000) NOT NULL,
    status ENUM('pending', 'processing', 'retry') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geocode_queue_ready (status, next_attempt_at, attempts)
);
