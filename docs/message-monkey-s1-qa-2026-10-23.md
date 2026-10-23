# MessageMonkey s1 functional qualification — October 23, 2026

**Result: not fully qualified.** Broad isolated regression coverage passed, and four live campaign test emails were delivered and confirmed received. Three production issues remain. Ordinary campaign dispatch and a real inbound reply were not qualified in this run.

## Scope and safeguards

- Deployed release: **2.7.0**, commit `82e99986bce98c13282453aaac3f53f8c3ad2867`.
- Working branch: `codex/messageMonkey-testing`, starting at `120468a`; includes notification-branding work from `codex/message-monkey-notification-branding`.
- Live account: Shalom in Messiah Ministries on s1. This is not a separate functional qualification of every production account.
- Outbound recipients were restricted to the four subscribed members of `testSegment` (segment 1). Membership was revalidated immediately before the campaign test send and independently audited afterward.
- No deployment, production code change, credential change, privilege grant, sending-policy override, or live automation activation was performed.
- Existing production connection subscription states were preserved. Destructive operations, imports, suppression, and permission matrices were exercised in a disposable environment instead of against business records.

## Production findings

### High: Inbox delivery fails before provider submission

Reproduction: compose a test message to a testSegment member, save a draft, satisfy normal Inbox readiness, and queue a reply from the test conversation.

The reply failed with **“Unable to prepare this message.”** Service-mail request 22 never started provider delivery. A read-only diagnostic inside the actual restricted mail-dispatch container reproduced MySQL error **1142** while reading `marketing_inbox_thread_labels` during recipient readiness. The application worker lacks a required SELECT privilege for this path.

Relevant implementation: `src/audience_inbox_runtime.php`, `src/audience_delivery_lifecycle.php`, and the canonical mail-worker grants. Avoid unnecessary UI-only queries and add a regression that invokes real Inbox recipient readiness with thread labels under the worker identity. Qualify the correction before applying it across accounts; do not bypass readiness or retry the failed message blindly.

### High: normal campaign dispatch remains approval/budget gated

The QA campaign reached all five completed checklist steps and exactly four eligible recipients. Send review remained disabled with **“Campaign sending is gated until delivery is approved and sending budgets are set.”**

The SES connection and quota check passed, but normal dispatch still requires `audienceMailPolicyReady()` (bulk approval and positive hourly/daily budgets). The SES interface exposes provider quotas without the legacy policy controls. The older delivery-policy form contains Proton-specific approval language.

Relevant implementation: `src/audience_delivery_setup.php`, `src/audience_mail_budget.php`, `src/audience_ses_worker.php`, and `src/templates/audience/delivery-runtime.php`. Use SES’s actual live quotas rather than the legacy SMTP approval/budget gate, then qualify ordinary campaign dispatch. Existing approval flags and budgets were deliberately not forced in the database.

### Medium: campaign recipient preview returns HTTP 404

On QA campaign 3, open To, select testSegment, and click Preview recipients. The interface displays **“Unable to read the operation status (HTTP 404). Reload the page to retry.”**

The backend recipient calculation independently returned four eligible contacts, zero outside testSegment, and automatic resend disabled. Browser submission in `src/assets/js/audience.js` uses `form.action` while the form contains an input named `action`; this is a likely route-resolution issue requiring a focused browser regression. The backend preview handler is present. No send relied on the broken preview result.

## SES and actual mail evidence

The initial SES readiness result was stale. After checking that pending delivery/setup queues were empty, the normal **Check connection** action refreshed it at 15:47 UTC. SES reported production access and sending enabled, a 50,000-recipient rolling daily quota, and 14 recipients/second. Inbox readiness then returned true with zero blockers. No AWS settings were changed.

Readiness checks expire after 15 minutes; a later review again displayed the freshness requirement. Refreshing readiness does not change AWS limits.

| Service-mail IDs | Path | Final result | Provider send started | Recipient restriction |
|---|---|---|---|---|
| 21 | Inbox draft | Cancelled by moving its conversation to Trash | No | In testSegment |
| 22 | Inbox reply | Failed during preparation | No | In testSegment |
| 23–26 | Campaign test email | Accepted; each has signed send and delivered events | Yes, four total | All in testSegment |

The user confirmed **all four test messages were received**. Real inbound reply routing and client-by-client visual rendering were not established by that receipt confirmation. No additional test emails were sent after confirmation.

## Coverage matrix

“Isolated” means exact deployed source with synthetic data and disposable delivery adapters, not a live production send or byte-for-byte qualification of the s1 runtime image.

| Area | Live s1 evidence | Isolated regression coverage |
|---|---|---|
| Navigation and account context | Authenticated screens, release footer, workspace search found QA campaign | Authorization, CSRF, account isolation, toolbar/search |
| Connections and segments | testSegment rules, four members, subscribed status, recipient scope | Profiles, tags, groups, fields, nested rules, concurrency, notes, management, counts |
| Imports/exports/jobs | Entry screens loaded; business data not bulk-mutated | Import wizard, duplicate/blank matrix, resumable jobs, exports, undo, deletion |
| Campaigns | Create from segment, From/subject/time/content save, 5/5 checklist, exact recipient calculation, test-email delivery | Completion, dispatch, tracking, suppression, retries, ambiguity/no-replay |
| Email design and content | AI-reviewed template saved; designer desktop/mobile canvas and link checks | Content/Brand Kit, template folders and bulk moves, permissions |
| Inbox | Empty landing/reload; unread toggle; mark unread; subject 700/400 weight; done/reopen; compose/template/draft; Trash cancellation and restore | Inbox HTTP/storage, source/template authorization, restricted ingestion and notification worker |
| Reusable replies | Create, archive, restore; delete unlock attribute and icon labels | Admin-unlock enforcement and counts |
| Forms | Unpublished embedded form created, title edited, saved/reloaded, archive/restore verified; left archived | Hosted/embedded/popup/landing collections, submissions and authorization |
| Surveys/preferences | Management screens loaded | Collection HTTP tests; live subscription changes not performed |
| Automations | Existing active flow inspected without alteration or activation | Authoring/execution separation and flow execution tests |
| Analytics | Dashboard, Last 7 days filter applied correctly, Analytics AI completed | Reports, recipient activity, native observations and analytics HTTP |
| Writing AI | Live synthetic prompt completed and reviewed template persisted | AI HTTP qualification |
| SES/security | Fresh readiness check and signed delivery receipts | Setup, signed-event verification, forged-envelope rejection, quota/throttle/rejection, credential rotation, restricted worker permissions |

The deployed-source disposable suite passed **1,282 Audience HTTP checks + 80 unlock/count checks + 196 pagination checks = 1,558 explicitly counted checks**, plus storage, worker, signed-event, credential-rotation and 18 gateway checks. Subsuite numbers within the 1,282 total are not additional counts. Its containers, networks and volumes were removed after successful completion. The production grant failure demonstrates why isolated passes alone do not establish s1 readiness.

Command: `python3 /tmp/mm-s1-2.7.0-qa/scripts/integration_environment.py audience`, using cached local runtime images and a `git archive` of the deployed commit. Detailed local output: `/tmp/mm-s1-2.7.0-regression.log`.

## Retained QA records and outstanding checks

- Campaign 3, **QA 2026-10-23 — testSegment delivery**: draft, not dispatched; retained to reproduce preview and policy blockers.
- Inbox thread 3: retained with cancelled/failed request history; existing thread 2 restored to its original read/To do state.
- Reusable reply 1 and email-design template 3: named QA fixtures retained for reproduction.
- Form 5, **QA 2026-10-23 — unpublished form**: archived; never published.
- Synthetic Writing AI and Analytics AI request results retained.

Next qualification requires fixing the three production findings, deploying through the normal shared release procedure, and repeating normal campaign dispatch and Inbox reply delivery solely to testSegment. A real reply from a test recipient is still needed for end-to-end inbound routing in this run. Public signup/opt-out mutations, live automation email execution, every email-client rendering variant, and every production role/browser combination were not exercised live; relevant synthetic suites passed, but these must not be reported as exhaustive live coverage.

Evidence screenshots: `review-assets/message-monkey-2026-10-23/recipient-preview-404.png` and `review-assets/message-monkey-2026-10-23/campaign-send-gate.png`.

## Fix and retest follow-up — October 23

All three corrections are implemented on `codex/messageMonkey-testing`. They are **not yet deployed to s1**, so the production findings above remain applicable to 2.7.0.

- **Inbox:** recipient eligibility now reads only thread identity and status, avoiding display-only label queries. Existing database privileges are unchanged. The new restricted-role regression prepares and delivers a labelled reply through the fake SES adapter, asserts that label SELECT remains forbidden, and rejects a missing conversation. Existing consent and Trash checks remain intact.
- **SES sending limits:** per the user's final direction, SES campaign review, dispatch, and automation readiness now use AWS production/sending status and live quota/rate checks. The proposed extra MessageMonkey budget form and enforcement were removed before release. Legacy SMTP allocation remains unchanged. Explicit campaign confirmation, current consent, connection checks, throttling, and no-replay protection remain enforced. No production quota or policy values were changed.
- **Recipient preview:** the browser uses the form's explicit action attribute or the current URL, preserving campaign query parameters despite a hidden field named `action`. The built bundle and asset manifest are updated. Browser checks passed with both omitted and explicit form actions, returning four synthetic eligible recipients and restoring the button state.

The earlier budget-based implementation passed its disposable suite, but was superseded before deployment. The final AWS-limits-only revision passed **1,281 Audience HTTP + 80 unlock/count + 196 pagination = 1,557 counted checks**, plus restricted worker, SES quota/throttle/no-replay, signed-event, gateway (18), and credential-rotation checks. `/tmp/mm-ses-live-limits-regression.log` exited 0 and the disposable environment was removed. Focused runtime (36) and SES validation (57) checks also passed. Browser verification of the preview fix used a localhost-only synthetic fixture with the real bundled JavaScript and no mail transport; a repeatable browser test is in `tests/browser/audience-recipient-preview.test.cjs`.

Two earlier disposable runs exposed errors in the new fixtures (an unverified synthetic sender could not link to a contact; a fake adapter returned a receipt ID instead of the required delivery state). Both fixtures were corrected before the successful complete run. These were not additional production failures.

**No real outbound email was sent during this fix/retest.** The next step is the normal shared patch release, followed by live verification using freshly resolved testSegment membership only. Do not replay failed/uncertain requests. Inspect pending queues and explicitly confirm the four-recipient campaign scope before any live send. No synthetic budget values should be applied to production. The branding branch is already an ancestor of the testing branch and remains included in the next release.

### Authorized patch qualification

The user authorized a patch deployment using actual SES limits and a live retest restricted to testSegment. Pre-release read-only checks found no queued/processing campaign recipients, service mail, or setup jobs. QA campaign 3 still targets four contacts, zero outside testSegment, with automatic resend disabled. Live retest results will be recorded after deployment.

Release preflight: both active accounts have empty MessageMonkey queues. The read-only network audit found 23 networks, 82 live IPv4 assignments, no collisions/overlaps, and all six static reservations outside dynamic pools. No schema or Comprehensive Guide changes; AI Coach source hashes match. Both requested branch tips are included.

## Consolidated qualification on 2.7.2 — October 23, 2026

This section supersedes earlier outstanding-check and deployed-version statements above. s1 is running **2.7.2 / 02e8591**. Earlier fixes were released through 2.7.1 and 2.7.2; the two fixes below are qualified together on `codex/plain-text-preview-fix` and **have not been deployed**. No intermediate release was made during this consolidated pass.

### Confirmed issues and combined fixes

1. **Plain-text campaign Preview returned HTTP 500.** Campaign 5 reproduced this on s1. Review comments incorrectly required a regular visual-design campaign. Review and comment ownership now supports plain-text campaigns while preserving draft-only comment mutations, audience/role authorization, and the plain-text visual-designer restriction. Ten added HTTP assertions cover rendering/escaping, comment persistence/resolution, reviewer permissions, CSRF, foreign audience, and sent-campaign immutability. Local browser Preview and comment save/reload passed.
2. **Idle SES readiness expired and disabled double-opt-in forms.** A published QA landing form was unavailable with a previously successful SES check older than 15 minutes; the normal Check connection action restored it. The existing worker only refreshed ready connections at send time, but blocked signup could not enqueue a send. The setup worker now refreshes enabled, previously ready connections after ten minutes, before their readiness window expires. Explicit queued checks take priority, the claim remains atomic and generation-scoped, and failed verification still blocks sending. Regression coverage under the actual restricted mail database role proves proactive idle refresh, no redundant fresh check, no email sent during refresh, and failure remaining blocked until an explicit check. No credentials, permissions, quotas, or database schema changed.

### Completed coverage

| Area | Live s1 evidence | Isolated qualification / scope limit |
|---|---|---|
| Campaigns and delivery | Four testSegment campaign deliveries received; Inbox delivery received; schedule/pause/resume/cancel; plain-text draft persistence and Preview defect reproduction | Combined HTTP/dispatch suite covers tracking, suppression, resend, retries, ambiguity, no replay and plain-text review correction |
| Inbound replies and attachments | Real campaign and Inbox replies routed into their exact conversations; user attachment imported once and downloaded with matching SHA-256 | Gateway checks cover signed routes, MIME, duplicate UID, unverified senders and automatic replies |
| Inbox and reusable replies | Read/unread, empty landing, draft/edit, done/reopen, Trash/restore, notes, attachments, templates and archive/restore | Authorization, permanent deletion unlock, notification and restricted ingestion checks |
| Imports, segments and reporting | Export job 6 contains exactly four testSegment rows; job 7 skips existing/duplicate rows and rejects one invalid row; copied segment narrowed to one member; reports, favorites, CSV and activity filters | Nested rules, concurrency, resumable jobs, isolation, undo and deletion |
| Public collection and consent | Embedded form validation, repeat signup without profile changes; approved beneliath@gmail.com unsubscribe → confirmation email → user confirmation → subscribed; landing report shows 1 submission, 1 confirmed opt-in, 100% conversion; archived public link becomes unavailable | Private preference-center saves, hidden-field preservation, invalid/expired tokens and collection authorization are isolated tests, not claimed as live recipient-preference edits |
| Surveys and popup forms | Survey publish/response/report and Questions desktop/mobile preview passed in earlier 2.7.2 retest. Popup draft origins, seven-second delay, page-prefix rules persist after reload; mobile preview works; fixture archived | Installed popup trigger/origin behavior and destructive lifecycle coverage use disposable fixtures |
| Campaign planning | Plan 1 created; task appears on calendar; marking Complete persists in Agenda and removes it from incomplete tasks | Date/order validation, ownership, replication and deletion exercised in HTTP suite |
| Designer and templates | QA template 4 duplicated/renamed; heading edited; prior version restored and verified after reload; comment added/resolved; desktop, mobile and simulated-dark preview render | Blocks, conditional content, revisions, permissions, folders and bulk operations covered by existing suites |
| Content and AI | Synthetic image upload, rename, alt text, folder move/filter/list; Brand Kit loaded; live Writing AI and Analytics AI synthetic requests completed | Content Trash was stopped at the normal Admin Unlock gate; destructive actions and role variants qualified in isolation |
| Automations and SES | TestSegment-only automation send delivered, then flow paused/archived; existing cleaned-contact housekeeping flow left unchanged; live SES readiness check and receipts | Execution/authoring privilege separation, live-quota adapter behavior, throttling, rejected/uncertain sends, signed SNS, secret rotation and worker grants |

This is bounded feature qualification, not a claim that every production role, third-party site, browser, or email client was exercised live. No production permanent deletion or Admin Unlock bypass was performed. An earlier unlocalized MutationObserver console error did not recur and produced no confirmed failed behavior; it is not included as a defect.

### Combined retest and outbound audit

- Canonical disposable run passed **1,291 Audience HTTP + 80 unlock/count + 196 pagination = 1,567 counted checks**, plus storage, SES setup/dispatch, restricted-worker, flow, notification, 18 gateway and credential-rotation checks. Subsuite totals are included in the 1,291 count. Exit status 0; temporary containers, volumes and network were removed. Log: `/tmp/mm-consolidated-regression.log`.
- Command: `DNR_TEST_APP_IMAGE=dnr-test-77e3e45ae8c8-app:latest DNR_TEST_INGRESS_IMAGE=dnr-test-77e3e45ae8c8-ingress:latest DNR_TEST_DATABASE_IMAGE=dnr-database:local python3 scripts/integration_environment.py audience`. Current source is mounted in an isolated database/runtime, and all automated email transports are fake. An initial sandbox invocation could not access Docker and executed no tests; the authorized Docker run passed.
- Live final audit: fresh testSegment resolution still returns four contacts; all four are subscribed and unarchived. The approved contact's original name, tags and groups remain unchanged. Service mail 29 is the accepted confirmation to beneliath@gmail.com; mail 30 is the accepted signup alert to doug@shalominmessiah.com. Both recipients match testSegment. No campaign recipient rows were created after ID 16. No pending service/campaign mail, pending Inbox alert, or active flow run remains. Read-only evidence: `/tmp/mm-s1-batch-final-audit.json`.
- QA forms 7, 8 and 9 are archived/paused. Retained fixtures include the completed planning task, QA template 4, unsent plain-text campaign 5, earlier synthetic content/report fixtures and the real inbound QA thread. They are not scheduled for delivery.

The next release can include both fixes in one normal shared patch. Post-release checks should verify plain-text Preview and idle readiness on the deployed version, preserving account release parity and restricting any outbound test email to freshly resolved testSegment membership.

![Completed double-opt-in result with archived test form](review-assets/message-monkey-2026-10-23/double-optin-complete.png)
