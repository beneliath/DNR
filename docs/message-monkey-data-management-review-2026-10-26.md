# MessageMonkey data management review — October 26, 2026

Reviewed the implementation on `codex/messagemonkey-function-performance`, preserving the preceding function, performance, and security work. Three confirmed data defects were corrected and covered by regression tests. The review also identified retention and recovery decisions that should be documented before treating contact deletion as complete historical erasure or resuming outbound mail after restoring an older backup.

## Confirmed defects and remediation

### P2 — Contact deletion erased unrelated import/export rows

Permanent deletion searched serialized job payloads and results for the contact's email as a substring. Deleting `ann@example.test` also cleared the completed export and pending import rows of `joann@example.test`. A mapped import could lose another person's row merely because its name cell contained the deleted address. The other contact record itself was not deleted, but pending input and saved export history were lost.

Deletion now clears linked job rows by contact ID. For imports without a contact ID, it checks the complete normalized email in the configured email column. Unmapped drafts use exact complete-cell matching because their email column has not yet been selected. A coarse JSON search reduces the candidate set; only the subsequent exact comparison authorizes erasure. Candidates are processed in batches of 100 inside the original deletion transaction.

Regression checks cover suffix collisions, mapped identity versus other columns, mixed case and whitespace, literal wildcard/slash characters, foreign audiences, 205 matching rows across batches, permanent suppression hashes, and rollback of both job redaction and the contact tombstone.

Implementation: [audience_jobs.php](../src/audience_jobs.php), [audience_store.php](../src/audience_store.php).

### P2 — Profiles and exports omitted native subscription evidence

The evidence queries recognized contact creation and manual subscription changes, but omitted recipient unsubscribe, provider bounce/complaint, delivered confirmation, and signup conversion events. An export could therefore show an old imported opt-out date or no native consent date. When a new native opt-in date was used, old imported IP/confirmation evidence could still appear beside it.

Profiles and new export snapshots now use the same event-type catalog, including actual signup conversions. Repeat signup submissions do not replace the effective consent event. Native single opt-in and manual permission replace the opt-in date and clear unrelated old confirmation/IP values. Delivered double opt-in records the effective opt-in and confirmation time from the confirmation/conversion event. Native suppression replaces stale imported suppression dates and campaign attributes. No IP address is invented.

These changes correct evidence presentation; they do not change eligibility, grant consent, clear opt-outs, or alter delivery suppression. Dates from native history are recorded UTC dates; imported dates retain their original unspecified time-zone interpretation. Already completed exports remain frozen. Generate a new export to capture corrected evidence.

Implementation: [audience.php](../src/audience.php), [audience_helpers.php](../src/audience_helpers.php), [audience_export.php](../src/audience_export.php), and the snapshot query in [audience_jobs.php](../src/audience_jobs.php).

### P3 — Invalid calendar dates were emitted as export evidence

The export formatter checked only the timestamp's shape. It accepted values such as `2026-02-30 12:00:00` and `2026-10-26 25:61:00`. It now checks calendar and clock validity and leaves invalid values blank. Valid leap days and native fractional-second timestamps remain supported. Raw stored source metadata is preserved.

Implementation and regression cases: [audience_export.php](../src/audience_export.php), [audience_export_test.php](../tests/audience_export_test.php).

## Review coverage

| Area | Behavior reviewed and verification |
| --- | --- |
| Contact identity and mutation | Normalized emails, duplicate rejection, audience isolation, field validation, optimistic versions, transactional updates, notes, tags, groups, and stale bulk selections. Existing storage/HTTP suites and new deletion regression checks passed. |
| Imports | CSV/TSV/XLSX bounds, mappings, blank versus unmapped fields, duplicates, preservation of opt-outs, custom fields and memberships, resumable batches, per-row failures, cancellation, and undo. The wizard carries the update-existing choice between steps. Undo archives newly created unchanged contacts; it is not a rollback of updates to existing contacts. |
| Exports | Snapshot membership and values, field/group ordering, source evidence, status partitions including pending, quoting/Unicode, formula protection, private temporary files, cleanup after interrupted downloads, and explicit export-history deletion. Queued snapshots remain stable across later edits. |
| Consent and suppression | Manual permission, single/double opt-in, repeat submissions, public unsubscribe, hard bounce/complaint, deleted-address hashes, immutable recipient identity, and suppression rechecks before sending. |
| Campaigns, flows, forms, and files | Versioned draft/published state, archive/restore/delete distinctions, immutable sent snapshots, cancellation of queued work, form response cleanup, and references retained for delivery history. Lifecycle suites passed. |
| Inbox | Thread/contact association, duplicate inbound events, draft ownership, attachment metadata, queued reply cancellation on Trash, restore behavior, and retention of conversation history. HTTP and restricted-worker suites passed. |
| Reports and analytics | Audience scoping, status and membership filters, delivery event deduplication, collection conversions, pagination, and existing report/export behavior. The main HTTP suite includes analytics coverage. |
| Recovery and encryption | Account snapshot coverage, application-key recovery, encrypted response decryption, relationships, saved exports, continued writes during capture, restored database fingerprints, uploaded file integrity, and replacement application access. A fresh synthetic rehearsal passed. |

## Retention and recovery observations

These are operational follow-ups, not additional automatic purges introduced by this change.

| Record type / action | Current behavior |
| --- | --- |
| Contact archive / restore | Preserves profile data and subscription status; archive excludes the contact from ordinary active selection. Restore is not resubscription. |
| Contact permanent deletion | Tombstones the contact, removes profile details/memberships/notes, redacts contact event details and linked collection responses, clears related job data, and retains the deleted-email suppression hash. Delivery snapshots and Inbox conversation copies are not a comprehensive part of this erasure. |
| Import/export jobs | Stored input and export snapshots have no general automatic age-based expiry in the reviewed paths. Export-history deletion clears payloads/results and hides the selected history; active exports must first finish or be cancelled. |
| Campaign/flow deletion | Editable definitions are removed or irreversibly tombstoned; pending work is cancelled. Historical delivery evidence and recipient unsubscribe tokens are deliberately retained. |
| Form deletion | Removes the resource, revisions, responses, and visits. Delivered confirmation links can still complete without the deleted form's conversion attribution. |
| Inbox Trash | Cancels eligible queued replies and hides the conversation from normal lists. Messages and attachments remain recoverable; Trash is not permanent erasure. |
| Short-lived processing data | Collection rate-limit buckets are pruned after 48 hours in bounded passes. Terminal SNS receipts are pruned after seven days; old pending verification expires after three days. Confirmation mail payloads are cleared when processing finishes or expires. Cleanup depends on those paths/workers running. |
| Recovery copies | The repository documents separate account snapshots and bounded local/NAS retention. A snapshot can retain data deleted from the current database until that backup expires. Live cadence and freshness were not inspected in this review. |

Two decisions remain:

1. **Define retention and erasure scope.** Choose retention periods for import input, saved exports, delivery snapshots, Inbox messages/attachments, analytics, and recovery copies. Document which historical and suppression records survive a contact deletion. Broader erasure must preserve unsubscribe protections and the required delivery evidence; this review does not silently delete those records.
2. **Require reconciliation before mail resumes after a rollback.** An older snapshot necessarily contains older queue and suppression state. Keep outbound workers stopped while restoring, reconcile post-snapshot delivery/opt-out activity, and explicitly review pending campaigns, flows, and service mail before enabling delivery. The rehearsal verifies recoverability with outbound networking blocked; it does not certify that an old mail queue is safe to resume against a live provider.

## Verification

- Before remediation, the new database fixture reported **17 failures in 24 checks**, reproducing collateral erasure and stale/missing native evidence. Separate probes reproduced invalid exported calendar/clock values.
- Final focused data-management fixture: **34 checks passed**, including batch completeness, rollback, audience isolation, all reviewed native event shapes, and frozen evidence.
- **36 local PHP suites passed**, including **47 export checks**. Four additional guarded worker suites were executed in Docker under their restricted database roles.
- **17 Audience integration suite entries passed**, plus the four restricted-role suites. The main HTTP suite completed **1,339 checks**. Existing performance checks passed: **386 storage/query checks** and **156 HTTP checks across 25 workspaces**.
- All **four PHPStan configurations passed**. PHP/Python syntax validation and `git diff --check` passed.
- A fresh **synthetic backup/restore rehearsal passed**, with MessageMonkey contact/tag/note/event/export fixtures and an encrypted survey response. All **67 MessageMonkey tables** participated in the restored database verification. Encryption, uploaded files, administrator password/MFA, and the MessageMonkey page passed replacement-host checks. The online snapshot/file phase took 31.1 seconds; replacement application restoration took 26.1 seconds. These fixture timings are not production recovery estimates.
- The existing template-transfer test emits a nonfatal PHP 8.5 `imagedestroy()` deprecation. Globally installed Composer tooling also emits deprecation notices. Neither prevented verification.

Evidence: [qualification.json](review-assets/message-monkey-data-management-2026-10-26/qualification.json). New regression fixture: [audience_data_management_integration_test.php](../tests/audience_data_management_integration_test.php). Recovery fixture: [online_recovery_integration_test.py](../tests/online_recovery_integration_test.py).

Repeat with `python3 scripts/integration_environment.py audience-data`, `python3 scripts/integration_environment.py audience`, `python3 scripts/integration_environment.py recovery`, `php tests/audience_export_test.php`, and `composer analyse` using the existing disposable runner configuration.

## Delivery state and limits

Changes remain local and uncommitted on the current branch. No schema migration, production data change, deployment, real outbound mail, or NAS access was performed. Disposable test projects were removed. Concurrent Test Account refresh/demo changes were left untouched.

This review combines source inspection with synthetic functional, isolation, and recovery tests. It does not certify production backup freshness, production-sized recovery performance, storage capacity, or an organization-specific retention policy. Previously erased job data cannot be reconstructed by this code fix; regeneration from still-existing contacts or a suitable recovery copy is a separate operation.
