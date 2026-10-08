-- Retain a deleted Account's address label so old links can never target a new owner.
ALTER TABLE platform_accounts
    MODIFY COLUMN state ENUM('pending', 'provisioning', 'ready', 'disabled', 'archiving', 'restoring', 'deleting', 'deleted') NOT NULL DEFAULT 'pending',
    ADD COLUMN lifecycle_error VARCHAR(255) NULL,
    ADD COLUMN lifecycle_requested_by INT NULL,
    ADD CONSTRAINT fk_account_lifecycle_actor FOREIGN KEY (lifecycle_requested_by) REFERENCES users(id) ON DELETE SET NULL;
