# MessageMonkey and Mailchimp functional review

Reviewed October 18, 2026, on `codex/mailchimp-cloning-continued5`.

## Authorized follow-up — local qualification, October 18

The review below records the **pre-fix baseline**. The first implementation pass on the same branch addresses the requested reporting/engagement priorities and related findings:

| Finding | Current result |
| --- | --- |
| 1 Campaign metrics | Sent rows now show native open/click rates, pending receipts and direct report links. |
| 2 Engagement segments | Sent/accepted, opened (including click-only evidence), clicked, negative, relative-date and before/after conditions; optional campaign/link and known-bot controls. The same evaluator is available to flow conditions and recipient selection. |
| 3 Contact Insights | Lifetime native totals, per-campaign rates and links; unified contact/email activity and permission-scoped Inbox metadata. No fabricated contact rating. |
| 4 Reporting consistency | Shared recipient aggregates across lists, Insights, overview, custom reports, delivery reports, drilldowns and AI. Engagement denominator is accepted minus unique recorded failures; pending receipts remain explicitly provisional. Clicks imply opens. Repeated and unique activity are separate. |
| 5 Bot claims | Controls and explanations explicitly say known/detected bots. No claim that privacy proxies or unknown automation are eliminated. |
| 6 Home growth | Recorded additions and observed subscription changes now have prior-period trends and weekly charts/table. Active audience counts are distinguished from all recorded additions. |
| 7 My files | GIF images up to 1 MB and downloadable PDF documents up to 10 MB; private by default, explicit publication, account/key checks, attachment/sandbox responses and immediate revocation. Existing configured document scanning is retained. PDF support does not claim executable-document sanitization. |
| 8 Reporting controls | Removed the latest-100 campaign selector limit; deleted reports free an active slot and restore respects capacity. Added total opens/clicks, complaints, provisional denominator/pending metrics, quarter/year custom dimensions. |
| 9 AI evidence | Selected email/audience/forms/flows dataset, bounded aggregate facts and up to five ranked entities. No recipient identities or bodies, and no real inference request during qualification. |

Qualification: **909 isolated Audience HTTP checks**, including **127 analytics** and **38 AI HTTP** checks; **320 JavaScript tests**; pure analytics (31), AI (33), filter composition (10), delivery runtime (18), advanced designer (42), and Stage 1b validation pass. Worker privilege, signed inbound gateway, provider-event and key-rotation fixtures pass without external email. Regression cases cover click-only opens, repeat activity, failures, missing receipts, known bots, negative conditions, account isolation, older-than-100 campaign choices, report capacity and PDF private/public/revoked access.

Browser checks covered populated campaign rows, profile activity/Insights, the engagement builder, and mobile/light/dark layouts. A narrow-screen profile overflow and incorrect bot labels were found and fixed. The new schema migration is applied to localhost; **no production deployment or sending-gate change was performed**.

## Remaining code findings — local closeout, October 18

The second authorized pass implements the remaining native settings, notification and reporting work:

- **Audience defaults:** optional subject and sender defaults are copied into new drafts. Existing drafts retain their content. Saves lock and version the audience row, preserve unrelated settings, and require an administrator on the backend.
- **Subscriber notifications:** independently configurable bell and staff-email alerts for new subscriptions, unsubscribes and a previous-complete-UTC-day summary. Only active editors/administrators with verified email addresses in the current MOED account are eligible for email. Pending signups and imported snapshots are excluded. Alerts begin at the saved event cursor; identical preferences preserve their generation. Individual alerts and summaries are deduplicated; deleted contacts, revoked staff access, changed email addresses or changed preferences invalidate queued alerts. Daily summaries are emitted only when changes exist.
- **Sending boundary:** encrypted alert jobs use the existing SES service queue, sender binding, quotas, production-access checks and uncertain-send handling. No operational SMTP bypass was added. Notification preferences remain disabled locally until a user configures them; no external notification was sent during qualification.
- **Flows:** three OR entry triggers and 200 steps, including legacy single-trigger revisions, independent date cursors, shared entry/reentry policy, account validation and once-only event enrollment. Percentage splits remain simple path routing; they have no content variations, experiment scores or winner selection. A/B testing remains excluded.
- **Reporting:** up to 3,660 days per native report; older click-map deliveries are selectable without a latest-100 cutoff. Only the selected encrypted message snapshot is decrypted. Long-range AI series are summed into at most 120 periods, retain exact totals and identify their date spans; the chart no longer mislabels these buckets as daily observations.
- **Settings navigation:** direct links to signup forms, opt-in configuration and subscriber preferences.

**Qualification:** 928 isolated Audience HTTP checks, including 129 analytics, 38 AI HTTP and 16 defaults/notification checks; 39 pure flow, 32 pure analytics, 33 pure AI, 40 SES signature/API, 22 dispatch and 320 JavaScript tests. Restricted notification-worker permission checks and the signed Inbox gateway qualification pass. Migration integrity, changed PHP syntax and JavaScript syntax pass. The additive notification migration and privilege policy are applied to localhost only. Existing production releases and sending configuration were not changed.

Browser checks cover desktop/mobile/light/dark settings, keyboard progression through notification and trigger controls, and a populated three-trigger flow. The inactive **Sample · Multiple entry points** draft on localhost Shalom contains a delay, subscription condition and exit, with no email step. Mailchimp was inspected read-only: its accessible new-editor draft has a populated recent-email template preview, and its existing classic automation exposes subscription/open/link/date/field triggers and delivery-day controls. The account has no populated modern automation flow, so exact modern-flow visual equivalence is not claimed. Vendor references: [audience settings](https://mailchimp.com/help/audience-settings-and-defaults/), [automation flows](https://mailchimp.com/help/about-customer-journeys/).

**Operational prerequisites remain explicit:** actual receiving-mailbox routing, delivered-client rendering and installation on the intended public websites require real environment qualification. The signed MIME/gateway fixtures are complete; they do not certify a working production mailbox. No additional external email, website publication, receiving/DNS change or production deployment was performed. SES live delivery remains gated. Email Beamer’s vendor-specific inbound address and exact editor geometry remain compatibility differences rather than implemented native functionality; the native email designer and guarded Inbox remain the supported content paths.

## Original review baseline

**MessageMonkey has substantial native coverage of the everyday email workflow, but it is not yet functionally or visually equivalent to Mailchimp.** The most consequential gaps are the connection between engagement data and the user interface, engagement-based segmentation, reporting definitions, and operational qualification. Completing the eight implementation stages did not close every parity requirement.

This assessment covers the current localhost Shalom in Messiah workspace, its implementation, the accessible Mailchimp account, and current Mailchimp documentation. It distinguishes verified defects, narrower capabilities, intentional product choices, and work that still requires real delivery evidence. Product data and settings were not changed during this review; no email or AI request was submitted.

The previously recorded s1 release is 2.6.3. The local removal of Mailchimp history is newer than that release. The imported historical records were already removed locally and on s1 in the preceding task, but the feature-removal code has not been deployed to s1. Findings below describe current local code unless explicitly stated otherwise.

## Feature coverage

“Implemented” means the capability exists in the interface and implementation. It does not certify every edge case or real email delivery.

| Area | MessageMonkey coverage | Remaining difference or qualification |
| --- | --- | --- |
| Workspace and navigation | Native shell, MOED account context, Preview Access banner, theme toggle, notifications and role checks. | MOED branding and account controls are deliberate adaptations. Detailed visual equivalence remains incomplete in editors and reports. |
| Home | Date presets and custom range, comparison period, percentage trends, email metrics, campaign shortcuts and audience snapshot. | Missing Mailchimp's Home audience-growth chart and net subscriber trends. |
| Connections | Search, configurable columns, sorting, pagination, selection, bulk actions and subscription/source/tag filters. | The multi-select dropdown behavior now works. Contact totals are separate snapshots, not a live Mailchimp synchronization. |
| Tags, groups and fields | Editable tags, groups, typed custom fields, membership management and form integration. | Engagement cannot be combined with these fields in the general segment builder. |
| Segments | Saved segments, nested AND/OR rules, typed operators, previews and recipient filtering. | No general campaign sent/opened/clicked/not-opened conditions. |
| Contact profiles | Identity, fields, groups, tags, notes, permission history and contact-management activity. | Insights is a permanent placeholder; campaign and Inbox activity are not a unified timeline. |
| Imports and exports | File/paste import, mapping, deduplication, consent preservation, import results, undo constraints and exports. | No automatic ongoing synchronization with Mailchimp. Historical analytics import is intentionally removed. |
| Campaign management | Drafts, folders, search, duplication, archive/trash operations, recipient checklist and delivery links. | The list displays empty open/click metrics even for campaigns with report data; sent-row navigation is weaker. |
| Email designer | Native blocks, sections, styles, personalization, previews, saved templates and content reuse. | Different panel structure and editing interactions; the available Mailchimp legacy editor was inspected, but populated new-editor parity remains unqualified. |
| Templates and content | Native starter templates, saved templates, folders, image assets, reusable sections and public asset controls. | My files is an image library rather than a general file library. GIF and PDF uploads are unavailable. |
| Forms and preferences | Hosted, embedded and popup forms, landing pages, surveys, translations, publishing and preference management. | Editors differ from Mailchimp. Website installation, trusted ingress and actual confirmation-email behavior need end-to-end qualification. |
| Automations | Native triggers, delays, conditions, waits, profile/tag/group actions, email steps, revisions and durable execution. | One entry trigger and 100 steps; narrower authoring experience and template selection. Live flow email remains gated. |
| Inbox | Conversations, statuses, sources, templates, notifications, signed reply routing and guarded sending. | Current source configuration is not qualified for automatic trusted inbound routing. The accessible Mailchimp Inbox was empty, limiting populated comparison. |
| Email analytics | Separate marketing dashboard, comparisons, funnels, campaign reports, activity, links, devices, locations and exports. | Different rate definitions and bot treatment; missing metrics and bounded selectors limit parity. |
| Audience and conversion analytics | Native contact additions, observed subscription changes, form conversion and flow reporting. | Historical states cannot be reconstructed where no events were recorded; Home and AI do not expose all available datasets. |
| Saved reports | Email, audience, forms and flows; selected columns, plots, sharing within MOED, favorites, copy and restore. | Fewer dimensions/metrics and a 50-report limit that includes removed reports. |
| Content AI | Private shunbun engine, drafting, rewriting, subject suggestions, content checks and reviewed application. | Primarily text assistance, not Mailchimp's complete creative/layout toolset. The private engine is intentional. |
| Analytics AI | Aggregate evidence, current/previous comparison, daily series, explanations and limitations. | Only six email summary metrics are supplied; campaign rankings, growth, forms and flows are absent from the evidence. |
| Delivery and operations | SES adapter, queueing, schedules, suppression, provider events, quotas and account authorization are implemented. | AWS approval and controlled live delivery qualification remain separate release gates. |

## Findings to address first

### 1 Campaign lists conceal analytics that already exist

**High priority — verified in the interface and source.** Every campaign row renders dashes for Opens and Clicks, plus the accessibility text “No delivery analytics yet.” This is unconditional, including sent campaigns. The local Community newsletter sample shows those dashes while its delivery report contains 11 deliveries, 7 unique opens and 2 unique clicks. A user cannot compare campaign performance from the main list despite the data being available.

Mailchimp's inspected sent-campaign rows display performance figures and lead with View report. MessageMonkey's primary View action opens the campaign checklist; the separate delivery link is less prominent.

Evidence: [campaign row renderer](/Users/dgilmore/DNR/src/templates/audience/campaigns.php:43). Acceptance: populate sent-row metrics using the same definitions and filters as reports, distinguish unavailable data from zero, and make the report the primary destination for a sent campaign. This requires no AWS approval.

### 2 The segment builder cannot use email engagement

**High priority — verified in the rule catalog and compiler.** Rules cover identity, status, dates, tags, groups, custom fields and contact-management events. They do not cover receiving a campaign, opening it, clicking a link or failing to open it. An unknown engagement field is rejected by the backend, so this is more than a missing menu item.

This prevents ordinary targeting such as “subscribers who clicked the last newsletter” or “subscribers who have not opened any of the last three emails.” Automation-specific engagement triggers and a resend feature do not provide reusable audience segments. Mailchimp supports campaign-activity conditions in segmentation. [Mailchimp advanced segment conditions](https://mailchimp.com/developer/marketing/concepts/audiences/advanced-segments)

Evidence: [rule catalog and compiler](/Users/dgilmore/DNR/src/audience_rules.php:69). Acceptance: add scoped campaign/link/date predicates with explicit bot treatment, then verify preview, saved segments and dispatch-time reevaluation against the same sample events. No live sending is needed to implement or test these rules.

### 3 Contact Insights never progresses beyond its empty state

**High priority — verified in the template.** Insights always says that email engagement will appear when delivery and reporting become available, although those systems already exist. Receiving new events will not change the current template. The profile timeline reads contact-management events rather than combining them with message events and Inbox conversations.

Mailchimp's contact profile provides individual activity context. A MessageMonkey user currently has to leave the connection and search elsewhere to understand that person's engagement. [Mailchimp contact profiles](https://mailchimp.com/help/about-contact-profile-pages/)

Evidence: [Insights placeholder](/Users/dgilmore/DNR/src/templates/audience/person.php:46), [profile activity query](/Users/dgilmore/DNR/src/audience.php:153). Acceptance: show native sends, opens, clicks, delivery outcomes and linked conversations, with genuine empty states and privacy limitations. Any engagement score should have a documented native definition rather than imitate an unexplained vendor rating.

### 4 Matching metric labels do not mean matching calculations

**High priority — verified with an isolated calculation using the actual implementation.** Native open and click rates divide by messages accepted by SES. Mailchimp defines those rates against successfully delivered email and counts a click as evidence of an open even when the tracking image did not load. MessageMonkey currently counts open and click events independently. [Mailchimp rate definitions](https://mailchimp.com/help/about-open-and-click-rates/)

For an illustrative cohort of 100 accepted messages, 90 delivered, 45 unique opens and 9 unique clickers, the native functions return **45% open and 9% click**. A delivered-message denominator returns **50% and 10%**. This example was computed without a database or sending mail. Click-only observations can also produce clicks-per-open above 100%, or an unavailable value when no opens were observed.

Evidence: [recipient aggregation](/Users/dgilmore/DNR/src/audience_analytics.php:84), [rate calculation](/Users/dgilmore/DNR/src/audience_analytics.php:109). The native interface discloses the accepted-message denominator; this is a parity/interpretation problem, not hidden arithmetic corruption.

Acceptance: define a consistent delivery denominator, treatment of clicks without pixel opens, late provider events and missing receipts. Apply it to Home, Analytics, campaign lists, exports and AI together. Merely substituting explicit delivery receipts for accepted messages would be insufficient when receipts are incomplete. Synthetic event cohorts can qualify the calculation before AWS approval.

### 5 Bot filtering is much narrower than the Mailchimp control suggests

**High priority for trustworthy reporting — verified in the tracking endpoint.** The current classifier looks for a small set of words in the browser's user-agent string. It has no separate Apple Mail Privacy Protection classification. A scanner using an ordinary browser identity can pass this filter.

Mailchimp documents filtering Apple privacy-proxy opens and other automated activity. Its “bot filtered” result therefore should not be assumed equivalent to MessageMonkey's result. [Mailchimp bot filtering](https://mailchimp.com/help/about-bot-activity/), [Apple privacy implications](https://mailchimp.com/help/apple-privacy-faq/)

Evidence: [tracking classification](/Users/dgilmore/DNR/src/audience_track.php:12). Acceptance: either label the existing option precisely as exclusion of detected bots, or improve classification and expose unknown/proxy categories. Preserve raw observations and never describe filtered opens as proof that a person read the email.

## Additional functional gaps

### 6 Home omits useful audience trends

**Medium priority.** The inspected Mailchimp Home includes audience growth and net subscriber movement. MessageMonkey shows total connections, current subscribers and additions within the period, without that trend chart. Native Audience analytics already contains some of the underlying observations.

Evidence: [Home audience section](/Users/dgilmore/DNR/src/templates/audience/analytics.php:22). Acceptance: reuse recorded additions and subscription transitions, label the measurement window, and provide a direct Audience analytics link. Do not fabricate subscriber history before event collection began. Current contact totals between the two products are not expected to stay identical without synchronization.

### 7 My files does not support normal document attachments and links

**Medium priority with a practical newsletter impact.** Uploads accept only PNG, JPEG and WebP, capped at 700 KB. A user cannot upload a PDF handout or an animated GIF to My files. Mailchimp's Content Studio supports images up to 1 MB and other files up to 10 MB, as well as image URL import. [Mailchimp Content Studio](https://mailchimp.com/help/use-the-content-studio/)

Evidence: [upload validation](/Users/dgilmore/DNR/src/audience_content.php:5). Acceptance: if general file sharing remains in scope, add clearly typed documents and safe download URLs with account ownership, visibility and revocation controls. Keep the existing Israel images and reusable sections. This is independent of SES approval.

### 8 Analytics has selection and report limits that become visible with use

**Medium priority.** The message selector loads only the 100 most recently updated non-draft campaigns. There is no search or pagination for older choices. All-message aggregation can still include older campaigns, but selecting one older campaign through this control becomes impossible.

Saved reports are capped at 50 **including removed reports**. Deleting a report therefore does not release capacity. Native email custom reports also omit total opens, total clicks and complaint count/rate; complaints do appear in other reporting, so they are not wholly unrecorded. Grouping is limited to campaign/day/week/month, and report windows are limited to 366 days. Mailchimp exposes a broader custom-report metric and grouping catalog. [Mailchimp custom-report metrics](https://mailchimp.com/help/custom-report-metrics/)

Evidence: [message choices](/Users/dgilmore/DNR/src/audience_analytics_controller.php:39), [report catalog](/Users/dgilmore/DNR/src/audience_analytics_reports.php:4), [saved-report limit](/Users/dgilmore/DNR/src/audience_analytics_reports.php:208), [date validation](/Users/dgilmore/DNR/src/audience_analytics.php:24).

Acceptance: qualify 101-plus campaigns, selection of an older campaign, report removal at capacity, repeated versus unique activity, and longer-term reporting requirements. Keep the distinction between unknown delivery evidence and zero results.

The native link map is also a constrained reconstruction: it chooses among the latest 100 matching deliveries and uses a sanitized preview. It should be described as such rather than presented as a fully equivalent Mailchimp click-map experience. Device and location estimates remain coarse or unknown when trustworthy evidence is absent.

### 9 Analytics AI sees less information than the reporting screens

**Medium priority — verified in the evidence payload.** The private engine receives six current/previous email metrics and a daily accepted/click series. It does not receive per-campaign rankings, audience growth, form results, flow results, or device/location breakdowns. An AI link opened from another report section still carries the general email window and filters rather than that section's dataset.

It therefore cannot reliably answer “Which newsletter performed best?” or explain audience/form trends from the displayed dataset. Mailchimp's documented Analytics AI covers campaigns, automations and audience growth, with follow-up questions and reviewed actions. [Mailchimp Analytics AI](https://mailchimp.com/help/use-analytics-ai/)

Evidence: [aggregate evidence payload](/Users/dgilmore/DNR/src/audience_ai.php:44), [request evidence selection](/Users/dgilmore/DNR/src/audience_ai_api.php:64), [assistant context](/Users/dgilmore/DNR/src/audience_ai_controller.php:14). Acceptance: retain shunbun and the existing privacy boundary, but supply bounded, labeled evidence for the selected report. Test that unsupported questions produce a clear limitation rather than invented campaign or audience facts.

Content AI is useful for text drafting and editing, but it does not reproduce the complete Mailchimp design assistant. No AI model request was necessary for this review; prior model qualification remains separate evidence.

### 10 Automation authoring supports a narrower flow model

**Medium priority.** Native flows have one entry trigger and a maximum of 100 nodes. Mailchimp documents up to three triggers and 200 steps on eligible plans. Native email steps reference an existing campaign/designer rather than reproducing Mailchimp's complete inline authoring workflow. [Mailchimp automation flows](https://mailchimp.com/help/about-customer-journeys/)

Evidence: [flow document](/Users/dgilmore/DNR/src/audience_flows.php:41). Multiple triggers and a larger step limit are capability differences, not automatically necessary for Shalom in Messiah. More useful acceptance work is a populated comparison covering a signup trigger, wait, tag change, branch, email, exit, reentry and pause/resume. The accessible Mailchimp account had no populated flows to inspect without creating one.

The native percentage-split node deserves a scope check against the user's removal of all A/B testing. Routing a percentage of contacts is not inherently a winner-selecting experiment, but it should not become a way to reintroduce excluded testing features.

### 11 Audience defaults and notifications are incomplete

**Medium to lower priority.** Native settings cover audience identity, sender defaults, permission reminder and mailing address. The inspected Mailchimp settings also expose signup/unsubscribe notifications, daily summaries, default subject and Email Beamer. The general MessageMonkey notification bell is not a replacement for configurable audience-change notifications.

Evidence: [audience settings](/Users/dgilmore/DNR/src/templates/audience/manage.php:6), [job notifications](/Users/dgilmore/DNR/src/audience_jobs.php:21). Mailchimp's settings are documented in [Audience settings and defaults](https://mailchimp.com/help/audience-settings-and-defaults/).

Prioritize subscriber-change notifications and clear links to existing form settings if they are wanted. Email-to-draft via Beamer is a lower-value compatibility feature. MessageMonkey already has per-campaign browser publication, so this finding does not mean email archive functionality is entirely missing.

## Operational and visual qualification

**Keep live sending gated.** The latest existing SES monitoring record still showed production access disabled; this review did not change or recheck AWS configuration. The previous request for more information is not evidence of a new final denial.

The following remain separate from ordinary interface parity:

| Qualification | Current evidence | Required completion |
| --- | --- | --- |
| SES live delivery | Adapter and automated coverage exist; approval is pending in the existing monitoring record. | After approval, controlled sending, provider receipts, bounce/complaint suppression, quotas, pause/resume and unsubscribe handling. |
| Trusted inbound routing | Native Inbox sources were not enabled/confirmed in the inspected local configuration. The earlier self-addressed message did not establish trusted automatic routing. | Prove signed-route validation, correct account/conversation association, duplicate handling and attachment policy. Receiving-path qualification itself need not wait for SES production access. |
| Real email clients | Native browser previews and prior local qualifications exist. | Inspect actual delivered mail in representative Gmail, Outlook and Apple Mail clients, including dark mode, mobile layout and personalization. Browser dark mode is not a substitute. |
| Public forms | Native publishing, consent and validation are implemented. | Verify hosted/embed/popup installation on the intended site, trusted request context and confirmation conversion after a real email round trip. |
| Populated Mailchimp editors | Existing legacy email editor and form editor inspected; automation gallery and new-email entry inspected. | Compare populated new email and flow editors without treating empty onboarding screens as parity proof. |
| Responsive and keyboard behavior | Earlier qualification records cover mobile/light/dark states. | A focused regression of the final changed screens is still needed before another release; this review is not a new full viewport or accessibility certification. |

Visual differences include editor panel order, flow authoring, the Home audience section, campaign report actions, and the breadth of report controls. The contact table and filter bar are closer to the reference than those editors. The locally checked subscription dropdown remained open while two statuses were selected, so that earlier complaint should not remain on the open-defect list.

## Intentional differences to preserve

The following should not be counted as unfinished parity work:

- A/B and multivariate testing, including popup variants, were explicitly removed from scope.
- Mailchimp historical analytics and its importer were explicitly removed. No finding here calls for restoring them.
- MOED account authorization, Preview Access, account/deployment banners, native branding, theme controls and the user's preferred pagination remain deliberate choices.
- AI continues through shunbun rather than Mailchimp or a new cloud provider.
- The agreed scope excludes SMS, a general website builder, connected commerce/revenue features, external marketing integrations, ads/social publishing and VIP behavior.
- A canonical audience per MOED account, native sender infrastructure and stricter consent controls are architectural adaptations.
- Israel photographs and the native template selection are product choices; copying Mailchimp's entire template catalog is unnecessary.

Mailchimp plan gates and phased feature rollout matter. A capability described in its documentation may not be enabled in this particular account. Vendor limits quoted above identify a difference, not a requirement to reproduce every paid-plan feature.

## Status records need reconciliation

The original audit introduction still describes Inbox and full analytics as future work and contains obsolete release/stage descriptions. The 218-row feature matrix mixes earlier missing-feature assessments with later implemented stages; all rows still have `parity_qualified=no`. It also contains obsolete A/B/popup assertions.

Those fields cannot support a meaningful completion percentage. Reconcile each row against current code, intentional exclusions and an explicit acceptance result. Keep separate statuses for implemented, locally qualified, visually compared, and live qualified. The detailed later entries in [the implementation record](audience-mailchimp-parity.md) provide useful historical evidence, but they do not erase the concrete gaps above.

## Recommended work order

1. Connect existing data to campaign lists and contact profiles, then add engagement-based segments. These close everyday workflow breaks without AWS approval.
2. Settle metric definitions and bot/proxy labeling before expanding charts or AI. Use the same synthetic cohorts across lists, reports, exports and assistant evidence.
3. Complete Home audience trends, campaign selection beyond 100 entries, saved-report capacity handling and missing custom-report metrics.
4. Extend Analytics AI to the actual selected dataset while retaining the private engine and human review of actions.
5. Decide whether document/GIF uploads, subscriber notifications and multiple flow triggers are needed; implement the selected scope and compare populated editors.
6. Finish trusted inbound qualification and a focused mobile/dark/keyboard regression. After AWS approval, complete controlled live delivery qualification before enabling sending.

The first five steps can proceed with local sample data and read-only reference inspection. AWS approval will not fix placeholders, missing segmentation, reporting definitions or narrow AI evidence.

## Review evidence and limits

Current evidence includes read-only browser inspection of both products' Home, Analytics, Audience, campaign, content, form, automation and Inbox surfaces; native source inspection; and an isolated execution of the analytics calculations and segment catalog. The calculation produced 45%/9% versus the illustrative delivered-denominator 50%/10%, and the catalog contained contact-management events only.

The preceding implementation task's recorded suite included 874 Audience HTTP/storage checks, 28 analytics pure checks, 33 AI pure checks and 320 JavaScript checks. Those results were not rerun or relabeled as a fresh full regression here. They verify their covered behavior, not Mailchimp equivalence. This review also did not perform a new penetration test, load test, deployment, model evaluation or live send.

Only review documentation was added or updated. Existing history-removal changes remain uncommitted and separate from these findings.
