# Data-management changes

Implemented on `codex/macbook/network-measurement-wording` with migration
`20261001_data_management.sql`. The application migration is applied on localhost;
production application deployment remains separate. Local migration preparation
included an encrypted database/file backup with authenticated restore verification.

* Event-contact assignments survive organization and affiliation changes.
  Assignment snapshots and a removal history preserve names, contact details,
  roles and dates. Historical addresses are excluded from live email recipients.
  New roles still require an active affiliation; existing historical roles may
  remain unchanged when an event is edited.
* Final financial reports retain immutable amount/note revisions. Corrections
  require a reason; database triggers capture actor and time in the same
  transaction. Final reports and revisions have indefinite retention pending an
  explicit organizational policy. Parent engagement/organization permanent
  deletion is blocked, including through bulk deletion; archive remains available.
* Reimbursement setup and cost-center saves use numeric versions and business
  audit events inside the same transaction. Stale forms preserve their stale
  token until reloaded. Failed audit inserts roll back the mutation.
* Organization identity is its numeric ID. Distinct records may share a name;
  name/email/phone/address matches require review and explicit distinct-record
  acknowledgment on creation. Inline name-only intake directs ambiguous matches
  to the full form.
* Contact, organization, engagement, inquiry, task and standard-task creation use
  per-user operation receipts. Retrying a completed save returns its original
  ID and preserves the return destination. Receipts commit with the business
  transaction; a failed save leaves no successful receipt. Receipts expire only
  when their user account is deleted, preserving retry safety across old tabs.
* Administrators can review contact/organization merges and choose field values.
  Merge and undo require recent administrative elevation. An encrypted journal
  retains the before/after records and relationships for ninety days. Undo rejects
  later record or relationship changes, restores relationships transactionally,
  and records an audit event. Expired journal payloads are cleared by maintenance;
  journal metadata remains. Key rotation covers retained journal ciphertext.
* Scheduled maintenance records hourly consistency counts, including affiliation,
  merge redirect, financial revision and inquiry/task mismatches, plus retention
  backlogs. Operations shows the timestamp and labels overdue reports. It also
  displays sanitized NAS recovery reports from the independent host monitor.
* Financial and reimbursement persistence share fixed-precision `Money` parsing
  and integer-cent totals. Blank values remain distinct from confirmed zero;
  invalid precision and out-of-range amounts are rejected.

The shared bulk-delete toolbar is excluded from the general form card styling,
removing its outer pane on Organizations, Contacts, Speakers and other uses.

The real NAS replacement restore passed, including all 100 stored files and
password/MFA sign-in. Recovery verification, the live administrator alert
configuration, and remaining NAS snapshot/off-site and independent key/image
recovery requirements are documented in
[data-recovery-plan.md](data-recovery-plan.md).
