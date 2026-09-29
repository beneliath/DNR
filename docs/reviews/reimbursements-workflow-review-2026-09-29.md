# Reimbursements Workflow Review

Date: 2026-09-29  
Scope: Current local working tree, including expense/receipt entry, requests, review and submission, PDF/ZIP exports, email delivery, cost centers, settings, permissions, and browser behavior.

## Summary

The workflow has a useful foundation: ownership checks, CSRF protection, server-side submission locks, integer-cent amounts, protected receipt downloads, a review fingerprint, and a persistent copy of the emailed ZIP. The most important improvements are protecting Bcc privacy, preserving submitted records, exposing the original submission package, and making delivery failures recoverable without resubmitting expenses.

This is a review and recommendation document. No application code, live financial records, settings, or outbound messages were changed during this review.

### Evidence and Limits

- Read the reimbursement routes, helpers, schema, frontend code, and relevant shared storage and email code.
- Inspected the live submitted-request page in Firefox, including its navigation and accessible controls.
- Reproduced the Bcc-address disclosure by generating a synthetic PDF and extracting its first-page text. No real recipient or expense data was used for that check.
- Read the existing 101-assertion reimbursement integration coverage, which passed in the preceding implementation work. It was not rerun for this read-only review.
- Ran the seven focused reimbursement JavaScript tests; all passed.
- Read local runtime limits: 20 file uploads per POST, 600 MB POST limit, 500 MB PHP per-file limit, 512 MB PHP memory limit. The application separately restricts receipts to 15 MB each. Document scanning is disabled in this local deployment.
- This was not a production penetration test, a concurrent-session stress test, a mobile device test, or a load benchmark. Performance consequences below are inferred from code paths rather than measured at production scale.

## Priority 1 — Address First

### 1. Bcc Address Is Disclosed Inside the PDF

**Confirmed privacy defect.** The Catalog address is routed as Bcc, but the report cover prints that same address as `Catalog: ...`. Every To/Cc recipient receives the PDF in the ZIP and can read it, contrary to the review page's statement that Bcc recipients are hidden.

- Evidence: `src/reimbursement_pdf.php:30`, `src/reimbursement_submission_helpers.php:49`, `src/reimbursement_submit.php:69`.
- Synthetic reproduction: the extracted PDF text contains `Catalog: hidden-catalog@example.test`.
- Recommendation: exclude Bcc identities from recipient-visible PDFs, HTML, plain text, attachment manifests, and filenames. Keep any Bcc audit information restricted to authorized internal users.
- Acceptance check: generate an entire submission with a unique Bcc-only address and assert that the address is absent from every recipient-visible message body and ZIP document, while remaining present in its intended SMTP envelope.

### 2. Deleting a Submitted Request Releases Previously Submitted Expenses

**Confirmed data-integrity risk in the current administrator-only delete behavior.** Deleting the request cascades away its item links. Its expenses and receipts become editable and can be submitted again. Meanwhile, email delivery rows survive with a null request ID, and the worker can still claim pending/retry rows. Deleting the request does not retract or cancel its queued message.

- Evidence: `src/reimbursement_helpers.php:279`, `migrations/20260928_add_reimbursements.sql` request-item foreign key, `migrations/20260929_add_reimbursement_submission_email.sql:22`, `src/reimbursement_email_helpers.php:47`.
- Existing integration coverage explicitly verifies that an owner can delete a receipt after an administrator deletes its submitted request.
- Recommendation: use Archive for hiding submitted records. Introduce an explicit Void/Correction flow with a reason, actor, timestamp, and links to any replacement request. Preserve the original expense association and submission record. Define queue cancellation only for messages that have not started delivery; treat in-flight/uncertain deliveries separately.
- Acceptance check: voiding or archiving cannot silently make the same expense eligible for a second payment request; pending and in-flight delivery outcomes remain visible and auditable.

## Priority 2 — Reliability, Security, and Data Management

### 3. Downloads Do Not Reliably Represent the Original Submission

**Confirmed historical-consistency gap.** Submission saves an immutable ZIP, but Download Report PDF/Download Package ZIP regenerate the files using the current owner profile, current reimbursement settings, and current report template. Expense amounts and category snapshots are preserved in request items; the report's other identifying details are not all read from the submitted artifact. The expense list also reads live cost-center labels even for submitted items.

- Evidence: `src/reimbursement_submission_helpers.php:134`, `src/download_reimbursement.php:18`, `src/download_reimbursement.php:42`, `src/reimbursements.php:119`.
- Recommendation: make submitted requests offer **Download Submitted Package** using the saved ZIP and checksum. Retain a submission snapshot for the owner name, recipients, organization/bookkeeper details, attachment manifest, and template version. If a freshly formatted export is useful, label it separately from the original submission.
- Acceptance check: changing a profile, cost center, setup field, or report template cannot change the original submitted package or historical detail display.

### 4. Delivery Failures Have No Complete User Recovery Path

**Confirmed operational gap.** The worker supports retries and correctly stops automatic retries after uncertain SMTP delivery. The request page shows only a grouped status message. There is no recipient-level delivery history or controlled retry/resend action. Successful and terminally failed deliveries clear the encrypted message payload, leaving recipient hashes rather than a reviewable recipient snapshot.

- Evidence: `src/reimbursement_email_helpers.php:127`, `src/reimbursement_email_helpers.php:148`, `src/reimbursement_request.php:26`, `src/reimbursement_request.php:97`.
- Recommendation: distinguish request state from delivery state: Submitted + Queued/Sending/Accepted by SMTP/Failed/Needs Review. Show the bookkeeper's delivery separately from copy recipients. Add an authorized retry of known failed recipients and a separately confirmed resend of accepted messages. Preserve enough encrypted submission metadata to reproduce the exact approved message under a defined retention policy.
- Do not automatically retry uncertain deliveries or unlock expenses merely because an email failed. SMTP acceptance should not be presented as proof that the bookkeeper read or processed the request.

### 5. Receipt Uploads Bypass the Project's Document-Scanning Workflow

**Confirmed control gap; exploitability was not tested.** Receipt validation checks MIME, a PDF signature, and image dimensions, then stores the original file. The existing document-scanning system is used for presentation assets, but reimbursement receipts do not enter that workflow. Enabling the existing scanner alone would not add receipt scanning.

- Evidence: `src/reimbursement_helpers.php:73`, `src/reimbursement_helpers.php:142`, `src/persistent_file_helpers.php:109`; compare `src/presentation_slidedeck_helpers.php:101` and `src/document_scanning_helpers.php`.
- Recommendation: add receipt quarantine and scan states. Release clean files for preview/download/email; show clear pending/rejected states; prevent submission while required checks are unresolved. Keep file processing bounded and outside a long database transaction.
- Basis: [OWASP File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) recommends layered validation, appropriate size limits, and antivirus/sandbox checks where available. Sensitive receipts should not be sent to a public scanning service by default.

### 6. File Limits Are Disconnected From Submission Limits

**Confirmed UX gap with resource-use risks.** The UI allows multiple 15 MB receipts, while the final ZIP must also be at most 15 MB. The final limit is checked only after generating the report and ZIP. Image receipts are represented both inside the PDF and as originals, which can increase package size. There is no application-level receipt-count or cumulative-byte limit across repeated saves. PHP's local 20-file upload cap is not communicated in the picker.

- Evidence: `src/reimbursement_helpers.php:73`, `src/reimbursement_submission_helpers.php:81`, `src/reimbursement_pdf.php:68`, `src/assets/js/reimbursements.js:104`.
- Recommendation: show receipt count and total selected bytes immediately; enforce count and aggregate limits on the server; estimate package size before final confirmation; explain which files cause an oversized package. Consider bounded image optimization for the report while retaining originals, or an authenticated package-download option for oversized requests. Account for MIME/base64 overhead when setting the delivery limit.
- Acceptance checks: multiple large images, more files than PHP permits, repeated uploads, large PDFs, and oversized final packages produce clear validation rather than partial saves, generic failures, or unbounded processing.

### 7. Package Creation Holds Broad Database Locks

**Confirmed implementation behavior; contention is a performance risk, not a measured outage.** Submission begins a transaction, locks the request, owner, shared setup row, expense/cost-center rows, and receipts, then renders the PDF, builds the ZIP, hashes/copies storage, and queues email before commit. All submissions contend on the same setup row. Downloads rebuild and rehash their packages on every request.

- Evidence: `src/reimbursement_submission_helpers.php:9`, `src/reimbursement_submission_helpers.php:125`, `src/download_reimbursement.php:35`.
- Recommendation: prepare an artifact from a versioned snapshot outside the final transaction, preferably in a bounded background job. Use a short final transaction to recheck the version/fingerprint, lock records, and queue the approved artifact atomically. Reuse the stored artifact for submitted downloads. Standardize lock ordering and handle deadlock retries without duplicate submission.
- Acceptance checks: simultaneous submissions, an expense edit during preparation, receipt deletion during preparation, and a setup change after review. A stale snapshot must still fail safely.

### 8. Expense Saves Need Duplicate-Submit and Stale-Edit Protection

**Confirmed absence of these safeguards.** New expense saves have no idempotency key; edits update fields without comparing a version or `updated_at`. Submission itself has much stronger protection. Two new-expense POSTs can create separate rows, and two editors' tabs for the same owner can silently overwrite each other's draft changes.

- Evidence: `src/reimbursement_expense.php:34`, `src/reimbursement_helpers.php:110`.
- Recommendation: add a form operation token for new expenses and an optimistic version check for edits. Disable repeat submit while a save is pending, preserve entered fields on failure, and warn before leaving with unsaved work. Offer a duplicate-expense warning based on owner/date/merchant/amount or matching receipt checksums without rejecting legitimate repeated expenses.

### 9. Add a Reimbursement Audit Trail and Explicit Retention Policy

**Design gap.** The workflow stores useful timestamps and delivery state, but normal expense, receipt, request, recipient-setting, and cost-center changes do not create a dedicated business event trail. Original ZIPs remain referenced by delivery rows even after a request is deleted; there is no reimbursement-specific retention decision visible in the reviewed code.

- Evidence: mutation helpers in `src/reimbursement_helpers.php`, settings save in `src/reimbursement_setup.php:51`, and `src/file_storage_maintenance_helpers.php:17`.
- Recommendation: record who changed what, when, and why, including submission, correction/void, receipt removal, recipient changes, retries, and resends. Restrict visibility of recipient data and record explicit retention periods for messages, receipts, and submission packages. Consider recent Admin Unlock for changes to global submission recipients, consistent with other sensitive administrative actions.

## Priority 3 — Navigation and User Experience

### 10. Make the End-to-End Path Visible

**Confirmed navigation friction.** Creating a draft opens its edit page, but the Review/Submit link exists only on the Requests list. Request expense links are shown only when editing is permitted, so submitted or other-owner request rows cannot be used to open an authorized read-only expense view. Expense View has no obvious Edit action for its eligible owner. Back to Expenses drops the previous search, owner, dates, sort, and page.

- Evidence: `src/reimbursements.php:74`, `src/reimbursement_requests.php:66`, `src/reimbursement_request.php:111`, `src/reimbursement_expense.php:127`.
- Recommendation: provide consistent Expenses / Requests / Chart of Accounts navigation; a clearly named **Review and Submit** link from an eligible draft to the existing verification page; **View Expense** links for every authorized row; and an **Edit Expense** action only where permitted. Preserve a validated local return URL through view/edit/save flows. These links should not expand editing permissions or bypass verification.

### 11. Bring the Requests List Up to the Expense List's Standard

**Confirmed feature gap.** Requests has pagination and Active/Archived controls, but no search, owner filter, date filter, status filter, or selectable sorting. Sixteen-character hashes are the only request label. The date range is inherited from expense-list filters and cannot be edited directly on a draft.

- Evidence: `src/reimbursement_requests.php:32`, `src/reimbursement_requests.php:61`, `src/reimbursement_request.php:43`.
- Recommendation: add request search and owner/status/date filters; sortable totals and submission dates; a readable request title or short reference alongside the hash; and a validated draft date-range editor. Show a selected-expense total and missing-receipt count before creating a request. Keep filters distinct from the actual dates chosen for a request.

### 12. Improve Receipt Staging and Readiness Feedback

**Confirmed UX gap.** Before saving, users get a filename or count rather than a per-file preview/removal list. A new native picker selection replaces prior files, whereas drag/drop and paste append. Any server validation failure requires reselecting uploads. The review page's Package Contents list uses original filenames, while the new expense detail uses packaged filenames.

- Evidence: `src/assets/js/reimbursements.js:112`, `src/assets/js/reimbursements.js:191`, `src/assets/js/reimbursements.js:227`, `src/reimbursement_expense.php:61`, `src/reimbursement_submit.php:73`.
- Recommendation: use one staging list with preview, filename, size, remove/retry controls, and consistent append behavior for picker/drop/paste/camera. Validate ordinary fields before starting uploads; clearly identify files that must be reselected. Group the final package manifest by expense and amount and show the exact packaged filename, optionally with its original name.
- Add a readiness summary: expense count, total, missing receipts, package-size state, recipient readiness, and scan state. Treat missing receipts as warnings unless the business explicitly requires them.

### 13. Reduce Preview Cost and Improve Narrow-Screen Reading

**Performance/UX recommendation.** PDF cards render page one in the browser, and receipt responses disable caching. Image cards also fetch the original image. Lazy loading is present, but repeated visits can still transfer and decode large originals. Nine-column tables and long package filenames require substantial horizontal space.

- Evidence: `src/assets/js/reimbursement-receipt-preview.mjs:13`, `src/reimbursement_receipt.php:24`, `src/reimbursement_expense.php:130`, `src/assets/css/pages/reimbursements.css`.
- Recommendation: generate bounded first-page/image thumbnails once, store them with the original checksum, and serve them through the existing authorization model. Preserve a usable fallback when rendering fails. On narrow screens, show date, merchant, amount, status, and actions first, with receipt details expandable. Keep icons keyboard accessible and retain the app's existing colors, confirmation dialogs, typography, and theme tokens.
- For database scaling, measure representative query plans before adding indexes. Current substring searches and aggregate request-list queries are reasonable initial candidates for profiling, not evidence of a present bottleneck.

### 14. Consider a Bookkeeping Completion State

**Optional product decision.** The domain currently ends at Draft/Submitted; Archive controls visibility. It cannot distinguish a request awaiting the bookkeeper from one returned for correction or paid.

- Recommendation: if the app should track reimbursement completion, add Received / Needs Correction / Paid with an authorized actor, date, note, and payment reference. Keep archive state separate from payment and delivery state. Confirm whether this belongs in MOED or the bookkeeper's existing system before implementing it.

## Suggested Implementation Order

1. Remove Bcc disclosure and replace destructive submitted-request deletion with an explicit historical correction policy.
2. Expose original submission packages, add delivery recovery/history, and preserve a minimal encrypted submission snapshot.
3. Add receipt scanning, aggregate limits, short submission transactions, and save/version protection.
4. Improve navigation, list filtering, receipt staging, readiness feedback, and protected thumbnail rendering.
5. Decide whether payment tracking is in scope; profile realistic data before database tuning.

## Focused Verification to Add

- Bcc absence from all recipient-visible content, including ZIP/PDF output.
- Submitted-request deletion/void while messages are pending, processing, accepted, or uncertain.
- Original-package identity after profile, setup, category, or template changes.
- Multi-tab stale edits and duplicate expense saves.
- Multiple receipts, many-file limits, large images/PDFs, failed scans, and package-size rejection.
- Concurrent submission/edit/receipt operations and deadlock recovery.
- Keyboard-only navigation, narrow screens, both themes, and return-to-filter behavior.
- Failed-recipient retry versus an intentional resend, with unchanged recipients and immutable attachments.
