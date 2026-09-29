# Reimbursements Implementation

Date: 2026-09-29  
Scope: Authorized follow-up to the reimbursement workflow review, applied to the local deployment. Payment tracking remains external.

## Changes

- Removed Bcc addresses from newly generated recipient-visible reports. Renamed the settings label to **Bcc Email Address**; recipient routing and stored settings are preserved.
- Submitted requests cannot be deleted or release their expenses for reuse. Archive preserves the record. Recording a correction requires a reason, preserves content locks, and cancels only deliveries that have not started. Resolving a correction records another audit event; it does not send mail or unlock expenses.
- Submitted downloads return the original checksummed ZIP or its original PDF. New submissions retain the owner name and an encrypted snapshot of the approved context, recipients, attachment manifest, template version, and exact messages.
- Added recipient-level delivery history, retry for known failures, and explicit resend for accepted, uncertain, or cancelled deliveries. Recovery requires authorization, a reason, and confirmation. Uncertain SMTP outcomes are never automatically retried. “Accepted by SMTP” does not imply receipt, reading, or payment.
- Added append-only application audit events for reimbursement edits, receipts, requests, corrections, delivery recovery, settings, chart-of-accounts entries, and reviewer-email changes. The web account cannot delete audit events.
- Added idempotent expense creation and version checks to reject stale edits. Validation retains entered values and explains when files need to be selected again.
- Moved PDF/ZIP rendering and storage outside the final submission transaction. A locked final fingerprint check prevents submission if the reviewed contents changed.
- Added request search, owner/status/date filters, sorting, readable request numbers, and return navigation that preserves list context. Expense selection shows totals and missing-receipt counts across pages. Request dates are separate from browsing filters.
- Improved receipt staging, cumulative count/size feedback, clipboard/camera integration, and submission readiness details. Missing receipts remain a visible advisory; pending/rejected required security checks block submission.
- Added protected, cached image/PDF first-page previews and a bounded CLI preview worker. Original receipts remain available through authorized downloads. Narrow layouts prioritize core expense fields and expose additional details in a disclosure.
- Moved **Delete Expense** below Receipts directly onto the page background, retaining its existing authorization and confirmation checks.
- Restyled new reimbursement PDFs using the existing engagement PDF masthead, DejaVu Sans, blue headings, pale panels, restrained table rules, green totals, and page-number footer. Expense/receipt filename groups stay together across page breaks. Submitted original PDFs remain unchanged. The header now reads “Expense Report”; the duplicate large title and cost-center section label are omitted. The “Receipt Files” index includes all receipt formats. New submissions record template version 4.

The account-management page, navigation, search, and related UI now use **Chart of Accounts**. Individual entries are labeled **Account**, including expense selectors, table columns, new reports, emails, and validation messages. Existing internal routes and identifiers remain compatible.

## Retention and Historical Records

The encrypted recovery snapshot is available for 365 days and then cleared by mail-worker maintenance. Original packages, receipt records, permanent owner-name snapshots, and audit events are not automatically purged by that policy. Storage maintenance recognizes the new artifact references; key rotation recognizes the encrypted snapshot.

Existing submitted packages are preserved where their original attachment reference remains available. Older successful messages whose payloads were already cleared cannot be reconstructed for exact-message recovery. The UI explains this limitation. Older submissions without a saved original artifact return an explicit error rather than a regenerated document presented as original.

The Bcc correction applies to new packages. Previously emailed or saved original packages are immutable and may still contain the former report content. Recording a correction cannot recall accepted mail or cancel a delivery already in flight.

## Deployment

Three migrations add workflow/version/audit/snapshot/scan metadata, permanent owner-name snapshots, and preview retry metadata. They are applied locally. The privilege provisioning script includes the required restricted grants.

Receipt scanning now uses the project's existing private scanning workflow. When `DNR_DOCUMENT_SCANNING` is enabled, unresolved or rejected receipts cannot be previewed, downloaded, or submitted. **Scanning remains disabled in the current local configuration.** Enabling it requires the existing scanner and scan worker; this change does not silently activate antivirus infrastructure or upload receipts to a public scanning service.

The `receipt-previews` service in the `reimbursements` Compose profile generates bounded private JPEG derivatives. PDF rendering is limited by timeout, CPU, memory, process count, a read-only container root, and dropped capabilities. Only that CLI service enables `proc_open`; web PHP retains its process-execution restrictions. The worker reports a `receipt-previews` health heartbeat. The app image now includes Poppler.

Limits are 20 receipts and 15 MiB of original receipt data per expense, 100 receipts and 15 MiB of originals per request, and 15 MiB for the final ZIP. MIME encoding adds approximately one third plus headers. The expense endpoint rejects HTTP bodies above 20 MiB before PHP processing; larger unrelated upload routes retain their own limits.

The local web and preview worker use the rebuilt image. No production deployment or externally delivered test message was performed. The production Compose wrapper enables the preview worker automatically, and the deployment writer/readiness checks include it. The guarded release applies migrations and grants before starting the qualified image. Restart long-running mail/scan workers when deploying updated helpers.

## Verification

- Disposable reimbursement HTTP integration suite: 127 assertions passed.
- Focused reimbursement JavaScript suite: 9 tests passed.
- Coverage includes authorization/CSRF, submitted locks, Bcc exclusion from PDF/message bodies, original-package identity after settings/profile changes, duplicate-save protection, stale edits, recovery of approved messages, uncertain-delivery guards, correction queue handling, upload limits, private preview authorization, quarantine enforcement, and real bounded PDF preview rendering.
- Browser inspection covered the desktop dark-theme request list, delivery/history controls, and receipt preview behavior. No real email was sent by these checks.
- Reran all 127 reimbursement assertions after the PDF restyle. Visually checked a three-page fictional sample, seven-page long-description/filename report, and an image receipt page; text checks verified all 21 long expense groups retained their filenames on the same page. A separate engagement render retained its original “ENGAGEMENT BRIEF” masthead.
- The broader existing engagement-export helper test stops at its plain-text contact-role capitalization assertion, before PDF checks. Its expected “Primary host” differs from the current Title Case output introduced earlier in this working tree. That unrelated assertion was left unchanged.

This was not a production load test, concurrent-session stress test, penetration test, or physical mobile-device test. No speculative database indexes were added; production query tuning should follow representative measurements.
