# Durable inbound mail rollout

This change replaces read/unread-based discovery with a durable IMAP UID checkpoint.
The migration adds mailbox state, reconciliation candidates and content-free import
receipts. It seeds receipts from retained mail and installs receipt-retention triggers.
It does not rewrite existing Chron text or route historical messages during migration.

## Required production recovery point

Before deploying this change to **s1**, create and restore-verify a **fresh production
database backup**, including all Chron/correspondence records. This is an explicit
user requirement for this change. Complete it before switching the application
checkout, applying the migration, or starting the new worker. Use the guarded
[s1 release workflow](release-workflow.md#deploy), which pauses writers,
records the previous application/database/migration identity, and verifies the
encrypted backup through a disposable restore. An older backup or successful dump
alone does not satisfy this requirement. A failed backup or verification blocks deployment.

Record the archive path, timestamp and checksum with the deployment record, and
retain the recovery archive through post-deployment verification. Confirm the
pre-upgrade Chron and correspondence row counts against the restored backup.

## Activation and verification

1. Apply the migration and refreshed service grants through the normal deployment workflow.
2. Confirm Operations shows a recent successful mailbox check and no repeated failures.
3. Review the historical candidates using the one-message reconciliation commands
   in [Inbound email to Chron](../README.md#inbound-email-to-chron). The first scan
   holds unknown existing mail for review, including mail delivered while the worker
   was stopped. Receipts for records purged before this upgrade cannot be reconstructed;
   compare those candidates with existing Chron history before importing anything.
4. Confirm new incoming mail enters the normal routing queue regardless of its read
   flag, and that a routed message has only its intended Chron entries. The worker
   does not change read flags or send email as part of verification.
5. Preserve the backup and deployment receipt until the release and correspondence
   checks are complete. Follow the release workflow's recovery procedure if needed;
   restoring an earlier snapshot discards later writes.

The regression suites cover already-read arrivals through authenticated Chron filing,
fetch/storage failures, atomic checkpoint rollback, purged redelivery without duplicate
Chron entries, UIDVALIDITY changes, explicit reconciliation, quarantine failures,
restricted worker grants, and encrypted backup/restore with receipt triggers enabled.
