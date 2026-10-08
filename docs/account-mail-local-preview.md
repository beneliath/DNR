# Shared mail routing — local preview

The user explicitly authorized local activation on October 7, 2026. The three
localhost Accounts now use `DNR_ACCOUNT_MAIL_ENABLED=1`, after fresh encrypted,
restore-verified backups. No production deployment is authorized.

`migrations/20261009_add_platform_mail_queue.sql` is applied locally and must
remain immutable. It creates `platform_inbound_mail` without changing existing
tables or records. All databases retain the same schema; only the primary
populates this table.

The grant script includes these permissions (using its existing variable names):

```sql
GRANT SELECT, INSERT, UPDATE ON `${MYSQL_DATABASE}`.platform_inbound_mail TO '${MYSQL_USER}'@'%';
GRANT SELECT ON `${MYSQL_DATABASE}`.platform_accounts TO '${mail_ingest_user}'@'%';
GRANT SELECT, INSERT, UPDATE ON `${MYSQL_DATABASE}`.platform_inbound_mail TO '${mail_ingest_user}'@'%';
```

The primary mail-ingest worker needs its application encryption key to read the
encrypted member routing credentials, Account mode/key settings, and access to
the gateway for authenticated delivery. The composed mail worker configuration
includes those fields. Enable the flag for each Account's web/dispatch services
and the primary mail worker only after the schema and grants are installed.
Set the flag in member `account.env` files and preserve it in provisioning.
The local provisioner drains staged delivery through `deliver_account_mail.php`;
live IMAP polling remains disabled in the local preview.

`python3 tests/account_mail_http_test.py --local-preview` passed with the local
provisioner temporarily stopped. It uses the restricted mail-ingest principal
for import/delivery and nonce-tagged records, then removes its fixtures. Verified:

- Generate identical record-ID Engagement tokens in three Accounts; separate
  pure routing tests also cover Inquiry tokens and record types.
- Import valid primary and member replies, including primary legacy tokens, and
  confirm each appears only in its destination's Inbox. Repeat import/delivery
  to prove idempotency. Delivered payloads are removed from the central queue.
- Import missing, forged, incomplete, conflicting, and unavailable-Account
  tokens; confirm no Account receives them and only SuperAdmin can read the
  separate review queue, including direct-ID, preview-role and POST checks.
- Check ordinary Inbox lists, counts, searches, dashboards and audit entries
  do not contain quarantined message content. Preserve pre-existing Shalom
  records as requested; do not silently reclassify historical records.
- Check retry/reject/manual-route authorization, CSRF and fresh verification. Verify local
  web/worker health and remove synthetic integration fixtures. A mailbox first
  scan sends uncertain mail to the platform queue rather than disclosing sender
  and subject in the ordinary reconciliation screen.

Six user-requested `[LOCAL TEST]` Mail Review messages were created (initial IDs
19–24). Automated checks leave these available for the user to route, reject,
or delete. One has a valid Test Account token and a simulated
delivery failure, allowing Check Routing Again to demonstrate delivery into
that Account's Inbox. Its synthetic record ID has no matching engagement.
The other five cover missing, conflicting, invalid, incomplete and unavailable
Account tokens. No sample email was sent or fetched externally.

Live IMAP/SMTP, real-record automatic filing, concurrent review/delivery races,
and oversized message handling still require production qualification. The
local provisioner is resumed to process retries every five seconds.

The new format signs Account label, record type and record ID. Primary legacy
markers remain supported. Sender/contact matches never choose the Account.
Uncertain mail stays separate until a SuperAdmin explicitly routes it. The review
UI now allows manual Account selection after CSRF and fresh Admin Unlock checks.
The decision is signed and bound to the message fingerprint, Account, reviewer,
and decision time. Each destination verifies the decision before accepting it.
Manual deliveries are inserted directly into Inbox Needs Review, including mail
with valid tokens for existing records; no automatic record filing occurs.
The original message and routing tokens are preserved. Only ready Accounts are
selectable; unavailable or forged destinations are rejected server-side.

The worker persists the destination before attempting delivery. Once delivery
has started, reassignment is blocked because a lost response could otherwise
leave copies in two Accounts. Retry preserves the decision, and destination
deduplication prevents a repeated delivery from overwriting the user's review.
Successfully delivered bodies are removed from the platform queue, retaining
content-free deduplication metadata. Rejection retains the message in Rejected.
SuperAdmins can permanently delete rejected message contents after confirmation
and fresh Admin Unlock. Lists, counts, and direct-ID views exclude these deleted
messages. Fingerprint/source receipts remain to prevent reimport, and deletion
is rejected server-side for messages that are still awaiting review or delivery.

The review screen uses status tabs, a message queue, and an adjacent reader with
dedicated routing controls. Full subjects and their tokens remain available in
Message details. Pagination has its own variable to avoid the shared header's
navigation variable overwriting the numeric page count.

Fresh local backup receipts for this activation are under
`var/backups/account-mail-activation-*`. Earlier `account-mail-schema-*` copies
predate this activation. Production requires its own fresh verified backup.
