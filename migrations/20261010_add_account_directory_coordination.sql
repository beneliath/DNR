-- Additive coordination: never publish a member login before its private commit.
CREATE TABLE account_directory_outbox (
    operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE platform_login_reservations (
    operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    account_key VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_login_reservation_username (username),
    UNIQUE KEY uq_login_reservation_owner (account_key, user_id)
);
ALTER TABLE platform_accounts ADD COLUMN profile_version INT UNSIGNED NOT NULL DEFAULT 0;

-- Shared recovery requests are asynchronous to avoid directory enumeration.
CREATE TABLE platform_recovery_requests (
    operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    retry_after DATETIME NULL
);
CREATE TABLE account_recovery_receipts (
    operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
