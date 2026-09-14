-- Content-free receipts survive retained-source deletion and mailbox rebuilds.
CREATE TABLE inbound_email_import_receipts (
    deduplication_hash BINARY(32) PRIMARY KEY,
    imported_at DATETIME NULL,
    purged_at DATETIME NULL,
    ignored_at DATETIME NULL
);

INSERT INTO inbound_email_import_receipts (deduplication_hash, imported_at)
SELECT deduplication_hash, created_at FROM inbound_email_messages;

CREATE TRIGGER retain_inbound_import_receipt AFTER INSERT ON inbound_email_messages
FOR EACH ROW INSERT INTO inbound_email_import_receipts (deduplication_hash, imported_at)
VALUES (NEW.deduplication_hash, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE imported_at = COALESCE(imported_at, UTC_TIMESTAMP());

CREATE TRIGGER retain_inbound_purge_receipt BEFORE DELETE ON inbound_email_messages
FOR EACH ROW INSERT INTO inbound_email_import_receipts (deduplication_hash, imported_at, purged_at)
VALUES (OLD.deduplication_hash, OLD.created_at, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE purged_at = UTC_TIMESTAMP();

CREATE TABLE inbound_mailbox_state (
    mailbox_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    mailbox_label VARCHAR(255) NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL,
    scanned_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reconcile_through_uid BIGINT UNSIGNED NOT NULL,
    observed_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_checked_at DATETIME NULL,
    last_imported_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    failed_at DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0
);

-- Unknown historical messages require an explicit import/ignore decision.
-- Never retain their bodies here, and never automatically resurrect old mail.
CREATE TABLE inbound_mail_reconciliation (
    mailbox_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL,
    uid BIGINT UNSIGNED NOT NULL,
    deduplication_hash BINARY(32) NOT NULL,
    sender_address VARCHAR(254) NOT NULL,
    subject VARCHAR(998) NOT NULL,
    sent_at DATETIME NULL,
    decision ENUM('pending', 'imported', 'ignored', 'duplicate', 'superseded') NOT NULL DEFAULT 'pending',
    discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (mailbox_key, uid_validity, uid),
    INDEX idx_mail_reconciliation_pending (decision, discovered_at),
    INDEX idx_mail_reconciliation_fingerprint (deduplication_hash)
);
