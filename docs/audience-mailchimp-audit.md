# Mailchimp → MOED MessageMonkey parity audit

Reference reviewed October 16, 2026, on `codex/mailchimp-clone`.
Stage 3 non-live closeout updated October 17, 2026, on `codex/ses-endpoint-layout`.
Local navigation, supported email types and Brand Preview follow-up: October 17, 2026, on `codex/mailchimp-cloning-continued`.
Stage 4 native collection implementation and local qualification: October 17, 2026, on the same branch.
Stage 5 native automation implementation and local qualification: October 17, 2026, on `codex/mailchimp-cloning-continued2`.

## Finding

**Current provider decision:** Amazon SES Essentials API for MessageMonkey marketing only, with credentials configured separately by each MOED account. The SES adapter, queues, scheduling, suppression, native analytics and signed SNS inbox are implemented and qualified without external mail. The shared production implementation is deployed through 2.6.0; the primary account’s sender/domain and SNS subscription are connected. Stage 5 automation flows and typography/logo refinements are qualified for the requested shared 2.6.1 release; deployment completion is tracked by the release runner receipt. Final live sending qualification remains open pending AWS production access. Operational MOED email keeps its existing SMTP setup.

**The native Stage 1 and Stage 2 implementation and qualification are complete;
full Mailchimp feature and pixel-level UI/UX parity remain unqualified.** Connections, profiles, tags,
groups, custom fields, import/export jobs and nested segmentation are functional.
Stage 2 includes campaign management, Campaign Manager plans, A/B
drafts, visual/code designers, templates and native Content Library/Brand Kit.
Reference layouts and interactions were reviewed; exact vendor typography,
artwork and the complete current new-builder workflow remain approximate. Stage 3 native delivery setup, sending/scheduling, tests, preferences/confirmation, observed tracking, authenticated event ingestion, experiments, resends and operational mail mapping are implemented and qualified locally. The primary domain and public authenticated SNS path have been activated; controlled inbox/signing/client, live receipt, scheduling and quota qualification remain outstanding. Stage 4 native hosted/embedded/popup forms, landing pages, surveys/polls and authenticated preference centers are implemented and qualified locally without external mail. Exact reference-editor parity, confirmed-subscription conversion attribution and live confirmation-email acceptance remain open. Stage 5 native automation authoring and non-live execution are implemented and qualified locally. Live flow email acceptance and exact populated reference-editor parity remain open. Inbox and full engagement analytics remain later work. The implementation record documents
verification, local import reconciliation and the passed Stage 1 UI/UX gate.
See [the qualification record](audience-mailchimp-parity.md#stage-1-uiux-qualification--october-16-2026)
for tested states, evidence and remaining product differences.

The [feature matrix](audience-mailchimp-feature-matrix.csv) is the implementation
backlog and review checklist. Each row identifies scope, reference evidence,
current implementation, UI status, stage and a concrete acceptance condition.
Rows are requirements, not equally weighted units of work; their counts must not
be converted into a percentage of product completion. **No row is qualified as
full parity by this audit.** The separate Stage 1 baseline gate has passed; full-vendor parity and capabilities
that need later stages remain open.

The matrix contains **218 rows across 19 areas**: 195 included requirements,
6 platform/qualification mappings, and 17 explicit omissions. Implementation
classification is 20 missing, 173 partial, 6 intentional adaptations, 2 unresolved
and 17 excluded. The evidence ledger covers 26 live surfaces and 93 official
sources. These are coverage counts, not claims that every underlying action was
executed or every vendor feature was discoverable in this account.

This review combines read-only inspection of the user's signed-in Mailchimp
account, current official help/product documentation, and inspection of MOED's
route, template, CSS, JavaScript, helpers, migration and existing test coverage.
The reference account was not edited, sent from, published from or populated with
test records. Account content, people, IDs, report values and screenshots
containing account data are not copied into this repository.

## Scope decisions and exact-match exceptions

| Area | Decision |
| --- | --- |
| Entry | Relationships → Audience, immediately below Speakers |
| Connections | User-facing records are Connections; preserve MOED Organization Contacts as separate records |
| VIP | Remove designation, columns, actions, import/export mappings, segment and dynamic-content conditions at the user’s request |
| Ownership | Native MOED data and execution; Mailchimp is the reference, not the backend |
| Email delivery | Amazon SES Essentials v2 API for MessageMonkey marketing, with each account’s own credentials and quotas. Native delivery/scheduling and isolated qualification are complete; AWS approval and controlled live qualification remain open. Operational SMTP stays separate. |
| Website builder | Omitted, including site navigation, store/appointment/site-building workflows |
| Campaign landing pages | Included, with signup, publication, hosted URLs and native reports |
| SMS | Omitted everywhere, including consent fields, filters, templates, flows and metrics |
| Integrations | Omitted, along with capabilities requiring a connected external app or platform |
| Connected dependencies | Omit social publishing, ads, store sync, abandoned-cart/product/revenue features, connected media apps, third-party workflow actions and third-party tracking |
| Native collection | Keep manual embed/popup snippets and MOED-hosted forms; these do not require a connected-app account |
| Native content | Keep ordinary social links and public-link metadata, file upload, manual brand settings and native AI assistance |
| Infrastructure | Delivery, inbound mail, storage and an eventual AI engine are implementation dependencies; do not expose Mailchimp/third-party connector setup as product parity |
| Brand/platform | MOED identity, its authentication/account context and shared authorization remain; do not mimic a paid Mailchimp subscription or offer Mailchimp support |
| Appearance | Light and dark modes follow MOED's sidebar preference; this is an explicit user-requested adaptation of the reference appearance |

The last three rows are implementation interpretations of the integration
exclusion. A future requirement that actually needs a connected account must be
reclassified as excluded, rather than added silently. Google reCAPTCHA-specific
configuration and Litmus Inbox Preview are not required under this scope; native
abuse protection and desktop/mobile email previews are required.

Mailchimp billing, plan upgrade banners, agency/referral programs and support
contracts map to MOED's own platform rather than fake Mailchimp account actions.
Their exact replacement UX is unresolved. Standalone mobile apps and any
uninspected enterprise-only tools are not certified by this web-workspace audit.
They are tracked as unresolved boundaries, not silently counted as completed.

## Evidence and limitations

`live-surface` means a page, menu or read-only view was inspected. It does **not**
mean its save/publish/send/delete behavior was exercised. `docs` means an official
source describes it, but the complete live workflow was not inspected. `MOED`
means a project-specific requirement rather than a claim about Mailchimp.
Combined evidence uses both kinds. A partially implemented row still has gaps;
`missing` includes disabled navigation. `adaptation` records an intentional MOED
difference and requires its own acceptance. `unresolved` requires inspection or
product mapping before implementation can be called exact.

### Live reference ledger

All routes below are on the signed-in Mailchimp account. Object identifiers are
omitted. Global navigation was observed repeatedly; a screenshot was visually
reviewed for the flow-template preview, legacy editor, advanced segment dialog
and marketing dashboard. Other entries were primarily inspected through the
browser accessibility tree; this is not a saved pixel-comparison baseline.

| ID | Screen / route | Observed surface and limit |
| --- | --- | --- |
| UI01 | Global shell and Create overlay | Header, search, account/help/notifications, expandable navigation; Create categories and regular-email entry. Search dialog rechecked October 17: filter pills, recent searches and campaign rows. No creation submitted |
| UI02 | `/campaigns` list | List/Calendar, type/status/folder/date filters, sorting, checkboxes, row details/analytics/actions; sent row menu including resend/report/replicate |
| UI03 | Campaign calendar | Month navigation, Today, type/status/folder/holiday filters, optimize-dates option |
| UI04 | Existing draft checklist | Name, settings/tracking, To, From, Subject, Send time, Content, preview/test, plain text, social cards, automatic resend and public campaign link. No values changed |
| UI05 | Draft Settings & Tracking | Opens, clicks, plain-text clicks, Inbox replies, video conversion; external trackers present in reference but excluded. Cancelled without saving |
| UI06 | Existing draft legacy editor | Canvas on left, Blocks/Style/Comments on right, Preview/Template/Continue; legacy block palette. New builder not directly inspected |
| UI07 | Email templates | Saved/recent/built-in tabs, search, formats, folders, grid/list, sorting, categories, Brand Kit toggle, code/from-scratch choices; gallery was partly loading |
| UI08 | `/audience/contacts` | Search and filter buttons, advanced filters, counts, analytics, columns/export, select controls, configurable fields; management menu |
| UI09 | `/audience/settings/` | Audience identity/ID, fields, sender/footer/notifications/archive defaults; notice that form defaults moved to Forms settings |
| UI10 | `/audience/segments` and create dialog | Saved/search/sort/actions, suggested segments; nested AND/OR dialog, logic, condition picker, duplicate/delete/review/use. No segment saved |
| UI11 | `/forms` and Create form dialog | Search/type/status/table/actions; Popup, Embedded, Signup landing page choices; legacy popup marked unsupported with migration action |
| UI12 | Forms settings | System signup/preferences/unsubscribe forms, edit/copy links, opt-in/GDPR/reCAPTCHA controls, advanced code; current fullscreen hosted editor inspected read-only October 17, including its vector rail/design/setup/share controls |
| UI13 | Surveys list | Recent/all, create/results/actions and starter categories |
| UI14 | Existing survey results | Question tabs, chart/table, date controls, chart/export/print, response drilldown and tagging entry points. No export or tagging performed |
| UI15 | Audience Inbox | Standalone header, Compose, Sources, Labels, notifications, templates, search, To Do/Done/Trash/All; empty onboarding |
| UI16 | Automation flows home | Build from scratch/Choose template, Automation flows/Classic Automations, onboarding and template recommendations |
| UI17 | Flow template gallery and preview | Channel/topic/app filters, native and connected templates; welcome preview, prerequisite and node path with zoom/reset. No flow instantiated |
| UI18 | Audience analytics | Date/comparison, channel tabs, growth interval/chart, source table, segment actions and pagination |
| UI19 | Content library and Brand Kit | Uploads/search/filter/folders/sort/grid/list/pagination; connected product/media tabs excluded; brand logo/fonts/colors/buttons entry controls |
| UI20 | Marketing dashboard | Date/comparison/message filters, bot data control, engagement and delivery metrics, channel tabs, funnel and segment actions |
| UI21 | Custom reports | Enhanced-report notice, classic-report menu, sort/favorites, empty report table; report builder not inspected |
| UI22 | Analytics AI | Beta chat entry, history area, suggested questions and input. No prompt submitted |
| UI23 | Subscriber preferences | Empty-state explanation and Build preferences center entry; editor not inspected |
| UI24 | Tags | Search, bulk tag, create, select all/rows, action menus and page size; no tag edits |
| UI25 | Home | Quick actions, date/bot controls, marketing snapshot, audience growth and recent campaign reports |
| UI26 | Existing draft Send time | Schedule/Send now/When triggered, date picker, optimized/specific/Timewarp choices and Save/Cancel. Expanded read-only; no settings saved and no Send clicked. Final confirmation wording verified against official sending/scheduling documentation |

The new email builder, populated flow editor, form/landing-page editors, individual
person profile tabs, import wizard, live send scheduling, paid optimization
features, detailed campaign report subviews and transactional console still need
targeted reference sessions before visual parity sign-off. Documentation supplies
the backlog for these; it cannot establish their exact current geometry or all
edge states. No recipient-facing submission or delivery experiment was performed
against the user's real Mailchimp account.

### Reference version conflicts

- The signed-in campaign list has its **New page** toggle off. This audit did not
  change that account setting. Record this rollout state with future visual
  baselines rather than blending both list layouts.
- The current regular-email checklist has **five** sections, including Send time.
  Older four-section instructions are not the current layout target.
- Form defaults have moved out of Audience settings. The live Forms settings
  page has a new signup-form version; the old popup is marked unsupported.
- Template/editor generations coexist. Preserve ability to work with existing
  legacy designs and implement the current builder; do not treat one editor as
  proof of the other's parity.
- Published feature/plan limits and advanced-segment limits vary between source
  versions. MOED should not copy pricing gates or impose a stale hard limit.
- Analytics AI is visibly Beta. Custom reports advertises an enhanced experience
  with a classic option. These require explicit versioned acceptance later.

## Stage 1b changes and remaining gaps

The user selected one canonical audience per MOED account. A switcher and
multiple-audience creation are intentionally absent. Marketing scope remains
separate from CRM Organization Contacts, and direct requests enforce that scope.
People now has filter popovers, nested rules, sorting, page sizes, selected/all
matching scopes, bulk actions and saved column visibility/order. Profiles have
tabs, typed fields, groups, removable tag chips, attributed notes and expandable
marketing status. Original consent metadata and suppressed status survive import.

Imports/exports use durable jobs, stored row outcomes, snapshot exports, error
files and limited undo. Tag and segment menus follow the supplied reference
screenshots; campaign actions remain disabled until campaigns exist. The local
Shalom fixture dataset was replaced with the supplied Mailchimp export and fully
reconciled; see the implementation record for counts and recovery evidence.

Remaining high-impact gaps:

1. Stage 1 People/data-management UI/UX qualification passed: 87 HTTP checks and
   84 desktop/mobile/theme rendering checks, with keyboard/editor/menu corrections.
   The documented product differences remain; this does not qualify full Mailchimp parity.
2. Create offers regular/plain-text/A/B email drafts and recent reuse.
   Centered header search opens a categorized dialog for Connections, campaigns,
   available workspace pages, permitted quick actions and existing help links.
   It includes browser-local recent searches and recent campaigns; integrations
   and vendor feedback are omitted. Home, flow, form, inbox and
   analytics workspaces remain later-stage work; campaign and content workspaces
   are qualified for Stage 2.
3. Profile engagement insights, ratings and email/form/survey/flow activity require
   real source events from their later stages.
4. Sending needs provider/domain setup, recipient snapshots, scheduled dispatch,
   suppression rechecks, unsubscribe, bounce/complaint handling and report rules.
5. Public signup, confirmation, preference updates, landing pages and surveys need
   scoped tokens, abuse controls and genuine lifecycle/reporting events.
6. Quoted multivalue names now round-trip. Import limits, export packaging/expiry
   and global job notifications require further parity work. The one-time ZIP migration is not
   a general upload feature.
7. MOED admin/editor/reviewer capabilities are enforced for current reads, writes
   and exports. Future design/publish/send/administer capabilities need explicit
   mapping; Mailchimp role names are not copied blindly.

Relevant implementation: [route](../src/audience.php),
[helpers](../src/audience_helpers.php),
[template](../src/templates/audience/workspace.php),
[JavaScript](../src/assets/js/audience.js),
[CSS](../src/assets/css/pages/audience.css),
[schema](../migrations/20261016_add_marketing_audience.sql).

## UI/UX contract for each stage

These are required MOED acceptance checks, not claims that every reference state
was already observed. Capture sanitized reference fixtures before each stage and
record the exact editor/report generation, viewport and date.

| Surface | Required comparison |
| --- | --- |
| Shell | Navigation order/nesting, selected and expanded states, header/rail dimensions, content inset, Create overlay, search results, return path to MOED |
| Page headings | Title, breadcrumbs, description, primary/secondary actions, split buttons and overflow menus in the same relative positions |
| Tables | Header/row height, alignment, field order, sort state, column controls, selection across pages, bulk toolbar, counts, page size and empty/filter states |
| Filters | Popover placement, search, multi-select, clear/apply behavior, summary chips, nested logic, count preview and saved-filter editing |
| Profiles | Identity summary, status, tabs, sidebar details, notes attribution, activity filtering/pagination, archive/restore/unsubscribe actions |
| Builders | Canvas and inspector placement, tabs, block insertion/reorder/duplicate/delete, selection states, style inheritance, autosave/recovery, undo/redo and preview |
| Flow map | Trigger/action cards, connectors and branch labels, node inspector, zoom/pan/reset, validation, status and execution overlays |
| Forms/landing pages | Design/setup/publish sequence, field validation, device preview, publication status/URL, confirmation pages, embed code and results |
| Reports | Filter/date/comparison placement, metric definitions, tooltips, chart/table switching, zero/no-data/loading states, drilldowns, exports and permissions |
| Interaction | Focus entry/return, keyboard navigation, Escape/cancel, disabled/loading controls, inline errors, confirmation dialogs, success feedback, back/forward and deep links |
| Responsive | At least equivalent desktop fixtures at 1280/1440px and 390px mobile; inspect wrapping, menus, drawers, table overflow, editor usability and keyboard focus |
| Visual details | Typography metrics, hierarchy, spacing, color, border/radius, icons and density. MOED branding and People terminology are intentional; proprietary fonts/assets are not currently bundled |

Pixel comparisons must use equivalent fictional data, dates and selected states.
Do not judge alignment from screenshots with different row counts or account
content. Document every approved deviation. A screenshot of one happy path is
insufficient: each feature needs its primary path, empty state, validation error,
permission restriction and recovery state. Accessible semantics and sensible
focus behavior remain acceptance requirements even when reference behavior is
uncertain.

## Stages and dependencies

| Stage | Complete scope |
| --- | --- |
| 1a | Existing foundation; retain its tests and fix reference gaps as 1b work proceeds |
| 1b | Audience model/settings, full People table/profile, typed fields, tags/groups, import/export jobs/history, bulk actions, advanced segments and capabilities |
| 2 | Campaign list/calendar/organization, checklist, templates, current and legacy design support, native content library/Brand Kit, personalization, preview/test UI, draft/revision persistence |
| 3 | Provider decision, domain/sender setup, queues/scheduling/resend, suppression, confirmation/unsubscribe/preferences foundation, delivery events and basic truthful reports; MOED-triggered transactional lane |
| 4 | System/hosted/embedded/popup forms, preference center, surveys/polls and **campaign landing pages**; native publication, collection and reporting |
| 5 | Flow authoring/templates, native triggers/rules/actions, execution/reentry/exits, pause/resume, migration/compatibility decisions for classic automations and per-node reports |
| 6 | Inbox sources, inbound routing, threading/labels/notes, compose/reply/templates, notifications and permissions |
| 7 | MessageMonkey Home dashboard; full email/audience/form/flow analytics, custom/comparison reports, native funnel outcomes, send-time optimization, benchmarks and bot controls |
| 8 | AI content assistance and analytics assistance with source-backed account results; remaining optimization/creative tooling |

Stage numbers communicate dependency groups, not a promise that features can be
released independently of their prerequisites. For example, stage 3's public
unsubscribe and confirmation foundation cannot wait until stage 4, and stage 5
requires the earlier execution/event contracts. Templates/content assets belong
in stage 2; stage 8 must not postpone the basic content library.

For each row, qualification requires: persisted behavior; account/audience and
role correctness; matching live UI states or an explicit adaptation; relevant
functional/visual verification; and operational recovery for jobs/public flows.
Campaign/flow qualification additionally requires suppression at dispatch,
idempotent event handling and reproducible metrics. Production promotion still
requires the repository's shared-release, backup, migration and account-parity
checks. The feature audit changes documentation; the accompanying theme request
changes Audience styles and preference synchronization. Nothing is deployed to
production.

## Official source catalog

Sources were reviewed on October 16, 2026. IDs below are referenced by matrix
rows. Documentation describes capability; live ledger entries establish only
the observed UI. Acceptance conditions also include MOED engineering decisions.

- **S01** — [Feature directory](https://mailchimp.com/features/)
- **S02** — [Search your account](https://mailchimp.com/help/search-your-account/)
- **S03** — [Audience basics](https://mailchimp.com/help/getting-started-audience/)
- **S04** — [Create an audience](https://mailchimp.com/help/create-audience/)
- **S05** — [Audience settings](https://mailchimp.com/help/audience-settings-and-defaults/)
- **S06** — [Fields and merge tags](https://mailchimp.com/help/manage-audience-signup-form-fields/)
- **S07** — [Contact types](https://mailchimp.com/help/about-your-contacts/)
- **S08** — [Profile pages](https://mailchimp.com/help/about-contact-profile-pages/)
- **S09** — [Update a profile](https://mailchimp.com/help/update-a-contact-profile/)
- **S10** — [Archive and restore](https://mailchimp.com/help/archive-unarchive-contacts/)
- **S11** — [Permanent deletion](https://mailchimp.com/help/delete-contacts/)
- **S12** — [Import people](https://mailchimp.com/help/import-contacts-mailchimp/)
- **S13** — [Import file format](https://mailchimp.com/help/format-guidelines-for-your-import-file/)
- **S14** — [Undo an import](https://mailchimp.com/help/undo-contact-import/)
- **S15** — [Suppression imports](https://mailchimp.com/help/import-suppression-lists/)
- **S16** — [View and export people](https://mailchimp.com/help/view-export-contacts/)
- **S17** — [Manage tags](https://mailchimp.com/help/manage-tags/)
- **S18** — [Groups](https://mailchimp.com/help/getting-started-with-groups/)
- **S19** — [Create groups](https://mailchimp.com/help/create-new-audience-group/)
- **S20** — [Advanced segments](https://mailchimp.com/help/create-an-advanced-segment/)
- **S21** — [Segment logic](https://mailchimp.com/help/understanding-advanced-segmentation-logic/)
- **S22** — [Segment conditions](https://mailchimp.com/help/all-the-segmenting-options/)
- **S23** — [Prebuilt segments](https://mailchimp.com/help/about-pre-built-segments/)
- **S24** — [Send to segments](https://mailchimp.com/help/create-and-send-to-a-segment/)
- **S25** — [VIP people](https://mailchimp.com/help/designate-and-send-to-vip-contacts/)
- **S26** — [Predicted demographics](https://mailchimp.com/help/about-predicted-demographics/)
- **S27** — [Regular email checklist](https://mailchimp.com/help/create-and-send-regular-email/)
- **S28** — [Send a regular email](https://mailchimp.com/help/send-regular-email/)
- **S29** — [Scheduling and pausing](https://mailchimp.com/help/schedule-or-pause-a-regular-email-campaign/)
- **S30** — [Recipient time zones](https://mailchimp.com/help/use-timewarp/)
- **S31** — [Resend to non-openers](https://mailchimp.com/help/resend-an-unopened-campaign/)
- **S32** — [Plain text campaigns](https://mailchimp.com/help/create-a-plain-text-campaign/)
- **S33** — [A/B tests](https://mailchimp.com/help/about-ab-tests/)
- **S34** — [Email builder generations](https://mailchimp.com/help/about-mailchimps-email-builders/)
- **S35** — [Current email builder](https://mailchimp.com/help/design-an-email-new-builder/)
- **S36** — [Section manager](https://mailchimp.com/help/use-section-manager-new-builder/)
- **S37** — [Template code](https://mailchimp.com/help/where-to-edit-template-code/)
- **S38** — [Template types](https://mailchimp.com/help/types-of-templates/)
- **S39** — [Save and reuse templates](https://mailchimp.com/help/save-use-email-template/)
- **S40** — [Preview and test](https://mailchimp.com/help/preview-and-test-your-email-campaign/)
- **S41** — [Inbox Preview dependency](https://mailchimp.com/help/test-with-inbox-preview/)
- **S42** — [Merge tags](https://mailchimp.com/help/troubleshooting-merge-tags/)
- **S43** — [Table of contents](https://mailchimp.com/help/add-a-table-of-contents-to-a-campaign/)
- **S44** — [Social cards](https://mailchimp.com/help/enable-and-customize-social-cards/)
- **S45** — [Marketing calendar](https://mailchimp.com/help/about-marketing-calendar/)
- **S46** — [Campaign Manager](https://mailchimp.com/help/about-campaign-manager/)
- **S47** — [Signup options](https://mailchimp.com/help/about-signup-form-options/)
- **S48** — [Current popup forms](https://mailchimp.com/help/create-a-popup-form/)
- **S49** — [Embedded forms](https://mailchimp.com/help/customize-embedded-signup-form/)
- **S50** — [Install signup forms](https://mailchimp.com/help/add-a-signup-form-to-your-website/)
- **S51** — [Popup mini quizzes](https://mailchimp.com/help/add-mini-quiz-to-popup-form/)
- **S52** — [Preference center](https://mailchimp.com/help/create-a-preferences-center/)
- **S53** — [Preference links](https://mailchimp.com/help/add-an-update-your-preferences-link/)
- **S54** — [Surveys overview](https://mailchimp.com/help/use-surveys-mailchimp/)
- **S55** — [Survey builder](https://mailchimp.com/help/create-a-survey/)
- **S56** — [Email polls](https://mailchimp.com/help/send-a-simple-poll-or-survey-to-subscribers/)
- **S57** — [Landing pages](https://mailchimp.com/help/create-a-landing-page/)
- **S58** — [Inbox setup](https://mailchimp.com/help/set-up-your-mailchimp-inbox/)
- **S59** — [Inbox messages](https://mailchimp.com/help/view-and-manage-inbox-messages/)
- **S60** — [Create a flow](https://mailchimp.com/help/create-customer-journey/)
- **S61** — [Flow concepts](https://mailchimp.com/help/about-customer-journeys/)
- **S62** — [Starting points](https://mailchimp.com/help/all-the-starting-points/)
- **S63** — [Edit a flow map](https://mailchimp.com/help/edit-customer-journey-map/)
- **S64** — [Flow templates](https://mailchimp.com/help/use-pre-built-journey-maps/)
- **S65** — [Flow email action](https://mailchimp.com/help/use-send-email-actions/)
- **S66** — [Flow group actions](https://mailchimp.com/help/use-group-ungroup-actions/)
- **S67** — [Flow reports](https://mailchimp.com/help/about-customer-journey-reports/)
- **S68** — [Classic automation migration](https://mailchimp.com/help/change-classic-automation-customer-journey/)
- **S70** — [Report overview](https://mailchimp.com/help/getting-started-reports/)
- **S71** — [Reporting index](https://mailchimp.com/help/reports/)
- **S72** — [Email campaign report](https://mailchimp.com/help/about-email-campaign-reports/)
- **S73** — [Marketing dashboard](https://mailchimp.com/help/about-email-analytics/)
- **S74** — [Custom report metrics](https://mailchimp.com/help/custom-report-metrics/)
- **S75** — [Custom reports](https://mailchimp.com/help/create-custom-reports/)
- **S76** — [Bot filtering](https://mailchimp.com/help/about-bot-activity/)
- **S77** — [Report exports](https://mailchimp.com/help/download-a-campaign-report/)
- **S78** — [Analytics AI](https://mailchimp.com/help/use-analytics-ai/)
- **S79** — [Connected conversion insights](https://mailchimp.com/help/about-conversion-insights-dashboard/)
- **S80** — [Popup reports](https://mailchimp.com/help/about-popup-form-reports/)
- **S81** — [Content Studio](https://mailchimp.com/help/use-the-content-studio/)
- **S82** — [Brand Kit](https://mailchimp.com/help/use-brand-kit-creative-assistant/)
- **S83** — [Default logo](https://mailchimp.com/help/set-a-default-logo/)
- **S84** — [Write with AI](https://mailchimp.com/help/write-with-ai/)
- **S85** — [AI email content](https://mailchimp.com/help/create-email-content-with-ai/)
- **S86** — [Content optimization overview](https://mailchimp.com/solutions/content-creation-tools/)
- **S87** — [Transactional email](https://mailchimp.com/help/about-transactional-email/)
- **S88** — [Transactional setup](https://mailchimp.com/help/add-or-remove-transactional-email/)
- **S89** — [Verify a domain](https://mailchimp.com/help/verify-a-domain/)
- **S90** — [Verify an email address](https://mailchimp.com/help/verify-email-address/)
- **S91** — [Domain authentication](https://mailchimp.com/help/set-up-email-domain-authentication/)
- **S92** — [Account roles](https://mailchimp.com/help/manage-user-levels-in-your-account/)
- **S93** — [Share campaign reports](https://mailchimp.com/help/share-a-campaign-report/)
- **S94** — [Dark-mode email limitations](https://mailchimp.com/help/design-emails-dark-mode/)

## Verification record

The first-slice application verification and the earlier local migration repair
are retained in [the implementation record](audience-mailchimp-parity.md).
That evidence establishes the foundation's behavior, not full reference parity.
The documentation audit did not require rerunning the foundation's application
tests. The accompanying theme change uses the shared pre-paint initializer and
saved `theme` preference; Audience also follows storage events, restored pages
and system-theme changes when no explicit preference exists. The actual signed-in
local app was checked in dark mode and light mode by changing MOED's sidebar
setting and navigating into Audience. The original dark preference was restored.
The Columns dialog opened and closed successfully. CSS/JS bundles and asset
fingerprints were rebuilt, syntax checked and the preference event behavior
checked separately. These checks do not qualify future pages or email designs.

The matrix is checked for valid CSV, unique IDs, valid evidence/stage/status
values, source references, exclusion consistency and summary counts; documentation
links and whitespace are checked as well.


### Stage 1b follow-up verification

The matrix reflects the subsequent People/tag/group/import/export/segmentation
implementation and explicit single-audience decision. Current verification is in
[audience-mailchimp-parity.md](audience-mailchimp-parity.md): disposable storage
checks, 64 authenticated HTTP checks, broad PHP and relevant regression checks,
source metadata reconciliation for the local import, and theme/profile previews.
No row has been promoted to full reference parity merely because its backend is
functional. Screenshot refinements include the filter popovers, Columns, tag and
segment split menus, Create icon, non-clickable header logo/text, removable
profile tags and aligned expandable marketing-status rows.


### Stage 2c designer and account controls

The remaining native designer feature pass adds advanced sections/styles, image
tools and a reusable asset library, video/social/code/TOC blocks, dynamic content,
HTML/ZIP and legacy templates, revision recovery, comments, personalized preview,
link checks and a separate plain-text counterpart. The header now uses a shared
notification bell and the authenticated user's profile photo, including the
existing initials fallback. Notifications show only the current user's recent
Audience job activity; persistent unread state and future send/flow events remain.

The [Stage 2c qualification record](audience-mailchimp-parity.md#stage-2c--remaining-designer-features-and-account-controls-2026-10-16)
distinguishes disposable HTTP persistence/permission checks from fictional browser
fixtures. Functional support does not promote a row to pixel-identical Mailchimp
parity. Original HTML is retained, while active or unsupported content is removed
from protected rendering with warnings. Twenty native starters provide the
included categories; the full reference-artwork catalog is still a gap.


### Stage 2d completion and qualification — October 16, 2026

The remaining native campaign and content scope is implemented and qualified.
The [qualification record](audience-mailchimp-parity.md#stage-2d--campaign-content-and-final-native-qualification-2026-10-16)
records the native feature boundaries and results. Stage 2 is complete for
draft/design/configuration workflows; actual dispatch, scheduling execution,
resends, delivery events and recipient-specific public preference/unsubscribe
resolution remain Stage 3. Exact Mailchimp pixel/client parity is not certified.

Recent user corrections are included: Connections terminology, six-tag overflow,
Date of Entry sorting, column dragging, export-history deletion, restored source
dates, unclipped Add Connection tags, removed VIP features, settings button spacing,
Language/Time zone dropdowns and a centered desktop header search. The date
restoration is a separately receipted data correction; qualification uses isolated
disposable data and fictional browser fixtures. No production deployment occurred.


### Stage 3a — email delivery setup and readability

The user selected MOED’s existing SMTP relay. MessageMonkey now has a Domains/
Email delivery workspace following the observed Mailchimp domain list, Add &
Verify Domain modal, code-entry flow and authentication controls. Verification
emails and connection-only checks run through the existing mail worker; SMTP
credentials remain outside the web process. The configured relay connected over
TLS during local qualification. No sender verification or campaign email was sent
to a real recipient by this work. Sender/domain activation remains an explicit
administrator workflow.

The [Stage 3a qualification record](audience-mailchimp-parity.md#stage-3a--email-delivery-setup-and-readability-2026-10-16)
details the setup-only scope, missing dispatch capabilities and native adaptations.
A mailbox check is not domain authentication; DNS publication is not a guarantee
of SMTP signing or inbox delivery. Future dispatch must enforce verified sender,
relay authorization, consent/suppression, public preference/unsubscribe links and
idempotent recipient delivery. Stage 3 is **not complete**.

The latest readability request raises the workspace base from 14px to 16px,
including navigation and table cells, and increases smaller labels and headings.
Saved email designs retain their authored font settings. All 64 current rendered
workspace/setup checks fit desktop/mobile viewports in both themes.


### Stage 3b — campaign sending and scheduling

Regular and plain-text campaigns now have native review, explicit confirmation,
Send now and specific-time scheduling, immutable encrypted recipient/content
snapshots, pause/resume/cancel, a rescheduling path through an unstarted draft,
and truthful delivery receipts. The worker rechecks consent and sender readiness,
uses bounded retries only before DATA, and quarantines uncertain acceptance.
Public unsubscribe/preference links, a private email browser view and hosted
snapshot images are included as prerequisites for marketing dispatch.

The [Stage 3b qualification record](audience-mailchimp-parity.md#stage-3b--campaign-sending-and-scheduling-2026-10-16)
records 393 authenticated HTTP/storage checks, a separate restricted-role worker
pass, 22 pure dispatch checks, the Test Account worker guard, fake-relay SMTP
protocol checks and desktop/mobile checks in both themes. No real email was
sent, and the real audience still has 10,884 Connections and zero campaigns.
Real sender/domain verification remains required; it was not bypassed.

Stage 3 remains incomplete: real test-email/inbox and high-volume relay
qualification, bounce/complaint event ingestion, full preferences and
confirmation/resubscription, automatic resends/experiments, recipient-local time,
optimization and additional native transactional workflows remain outside this
slice. Open/click tracking is explicitly unavailable in the send review. The
requested Connections column defaults now include Status and omit
Last Changed; Date Added defaults to descending.

### Stage 3c — delivery lifecycle and paid Proton qualification

The SMTP delivery foundation and isolated qualification are complete for the
previous Stage 3 provider scope. The subsequent SES decision below supersedes
that provider choice; SES is implemented in the following section. The paid Proton account and user-confirmed marketing approval are historical evidence;
no Free-plan quota is applied. Numeric allocations remain unset because the public
paid-plan documentation does not specify them.

The [Stage 3c record](audience-mailchimp-parity.md#stage-3c--paid-proton-delivery-lifecycle-qualification-2026-10-16)
describes shared capacity/reserves and provider deferrals, test sends, preferences
and delivered double opt-in, signed tracking and authenticated suppression events,
truthful reports, Timewarp, evidence-based optimization fallback, experiment winner
execution, automatic resends and the native operational Activity mapping.

436 disposable HTTP/storage checks, restricted worker/limiter checks, fake SMTP
protocol cases and desktop/mobile layouts passed. The encrypted local backup was
restore-verified and the migration preserved Connection dates, statuses and tags.
No real email was sent. **Stage 3 operational qualification is still open:** real
sender/domain activation, a configured MOED recipient allocation, controlled real
inbox/signing/client and volume checks, and an authenticated event source must be
completed before reporting Stage 3 fully finished.

### Provider decision — Amazon SES Essentials API (2026-10-16)

The user replaced Proton for **MessageMonkey marketing only** with Amazon SES
Essentials via the SES v2 API. Every MOED account must supply its own SES API
configuration and credentials. MOED account access, recovery, notifications and
other operational mail retain their existing SMTP setup. This supersedes the
Proton marketing decision above; the completed SMTP qualification remains
historical foundation evidence, not evidence that SES is connected.

Implementation requirements:

- One shared implementation with isolated per-account settings, credentials,
  readiness, identities, sender policy, quotas, receipts and event routing. No
  credential fallback from another MOED account, platform backup credentials or
  the operational SMTP relay. Restricted Test Account policy still blocks real
  provider credential configuration and delivery.
- An unrestricted administrator supplies region, SES access-key ID/secret (or an
  explicitly supported assumed-role configuration), allowed From identity and
  configuration set. Use dedicated least-privilege runtime IAM permissions; do
  not request AWS root or administrator credentials or SES SMTP passwords.
  Scope sending to the intended identity and configuration set. One-time AWS
  resource provisioning should be separate from runtime send permissions.
- Encrypt stored credentials within the account's isolation boundary, never
  re-display the secret, omit it from logs/exports/notifications, and expose
  replacement/revocation and audit metadata. The dedicated marketing worker
  reads/decrypts credentials; normal UI reads only masked setup metadata.
- Add the maintained AWS PHP SDK and a provider adapter. Reuse the native frozen
  content, recipient snapshots, scheduling, resends, experiment cohorts,
  preferences and suppression. Use SES v2 SendEmail with one recipient per
  request. Record SES's returned MessageId alongside the native operation ID;
  do not assume an authored SMTP Message-ID survives SES. Disable automatic
  SDK retries for non-idempotent sends after an ambiguous outcome and retain
  explicit uncertain receipts.
- Read GetAccount for actual regional production-access/sending-enabled status,
  rolling 24-hour usage and per-second sending quota. Apply a local cap beneath
  that provider allowance and respect throttling/paused-account responses.
  Essentials is a pricing plan, not automatic sandbox removal or a guaranteed
  sending quota. Remove Proton approval/budget requirements from the marketing
  readiness path while preserving the operational SMTP path.
- Each account verifies its sender domain and DKIM in the selected region,
  configures DMARC/alignment and, if selected, a MAIL FROM subdomain. Receiving
  mail and Reply-To can stay on Proton; do not redirect the main inbox MX records
  merely to send through SES. Region changes require requalification.
- Each account connects its configuration set to a dedicated SES event
  destination, initially SNS HTTPS notifications to an account-scoped receiver.
  Verify SNS signatures, trusted certificate URLs, configured TopicArn, AWS
  account/region and message binding before accepting subscription or delivery
  events. The existing generic HMAC receiver is not an SNS signature validator.
  Persist receipt IDs, deduplicate retries and tolerate reordered events.
- Keep campaign analytics in MOED. SES supplies acceptance/delivery, bounce,
  complaint, delay/rejection events and can supply opens/clicks. Choose one
  authoritative engagement-tracking path per message (SES or native) to avoid
  double link wrapping/counting. Native unsubscribe/preferences, A/B attribution
  and resend eligibility remain linked to the MOED recipient receipt. Opens and
  clicks are observations affected by image blocking, privacy proxies and bots;
  API acceptance is not inbox delivery.
- Separate IAM keys in one AWS account do not by themselves isolate regional
  quotas, billing or all reputation effects. Distinct AWS accounts provide a
  stronger boundary; shared AWS ownership needs explicit account allocations
  and validated sender/configuration-set/topic scoping. AWS SES tenants are
  separate optional SES resources, not synonyms for MOED accounts.
- Configure a budget and disclose sending/data/event-service charges; avoid
  enabling paid deliverability add-ons or dedicated IPs implicitly. AWS currently
  lists Essentials outbound sending at $0.16/1,000 in the first volume tier,
  without a fixed account/region monthly plan charge; attachment/data and
  event-service charges can apply. Pricing must be checked when activating.
- Qualification must include account isolation, revoked/wrong-region credentials,
  sandbox and unverified-domain states, quota/throttling, ambiguous API outcomes,
  SNS subscription/signature/replay/foreign-account rejection, simulator bounces
  and complaints, native unsubscribes and controlled real inbox/DKIM checks.

The SES API implementation is now qualified locally (2026-10-16):

- Each account has an administrator-only connection for Region, From address,
  configuration set, SNS topic and encrypted API credentials. The web role can
  replace ciphertext but cannot read it; the mail role can read but cannot
  replace credentials. Credential payloads are bound to the MOED account.
  Blank fields retain the saved credentials; disconnect removes them. Setup
  operations are audited without secrets. Key rotation covers SES credentials
  and the retained campaign/service ciphertext.
- All Email delivery tabs require the administrator role for read access, as
  well as setup actions. Editors and reviewers receive HTTP 403 on direct URLs,
  including alternate API and legacy audience selectors. Their navigation,
  campaign setup links and workspace search omit the restricted destination.
  Existing Test Account and restricted-demo configuration protections remain.
- Campaigns, reusable-template/designer tests and marketing subscription
  confirmations use SES v2 SendEmail. Operational mail keeps its existing SMTP
  settings and transport. Marketing never falls back to SMTP or ambient AWS
  credentials. Review snapshots bind the SES sender/account/Region/configuration
  set/topic, and real sends retain the AWS MessageId separately from the native
  recipient ID. Each request has one recipient and native unsubscribe headers.
- The worker checks STS account ownership, regional SES production access,
  sending status/quotas, domain verification, DKIM, DMARC, custom MAIL FROM when
  configured, and the configuration set’s SNS event destination. It paces sends,
  refreshes actual quota usage, defers throttled/quota-limited requests without
  consuming attempts, and disables SDK retries. Ambiguous responses are not
  replayed; verified provider receipts can reconcile them.
- The account-scoped SNS receiver uses AWS’s maintained signature validator,
  bounded HTTPS certificate fetching restricted to the configured regional SNS
  hostname, timestamp/topic/account/recipient binding, durable deduplication and
  signed subscription confirmation. Older unsubscribe notifications cannot remove
  newer subscriptions. The former generic HMAC receiver is retired for SES.
- Native signed links/pixels remain authoritative for observed opens/clicks and
  unsubscribe attribution; SES tracking overrides disable duplicate engagement
  tracking. Reports retain experiment/resend attribution and add authenticated
  delivery, delay, rejection, bounce and complaint receipts. Matching hard
  bounces and complaints suppress future marketing without changing unrelated
  Connections.
- Qualification: **434 authenticated HTTP/storage checks**, **37 SES validation /
  real RSA SNS signature / actual SDK request checks**, the dedicated mail-role
  quota/throttle/rejection/uncertainty/no-replay suite, and the maintenance-role
  credential rotation/retirement suite pass without external mail. Existing
  dispatch/runtime/demo guards and PHPStan level 5 pass. Fictional SES setup
  layouts were checked at desktop and mobile widths and in both themes.

The local primary app and mail worker use the qualified SES dependency image.
Migration and runtime receipts are under `var/backups/audience-ses/`. The fresh
pre-migration backup was restore-verified. Connection dates, subscription statuses
and tag memberships retain their exact prior fingerprint.

**Current activation checkpoint — October 17, 2026.** The primary account now has
saved, scoped IAM API credentials, a verified SES domain and DKIM, published DMARC,
a custom MAIL FROM domain, and a configured SNS destination. The public HTTPS
subscription is confirmed in AWS and MessageMonkey; its signed envelope was
processed by the mail worker. Versions 2.5.22 and 2.5.23 were deployed and verified
across active s1 accounts. This establishes the subscription path, not delivery of
an actual marketing message.

The production-access request was submitted. AWS’s initial API status `DENIED`
was clarified by its console and automated support response as **More information
needed**, rather than a final decision. The user-approved reply describing volume,
explicit opt-in, unsubscribe and event handling has been posted. An hourly monitor
waits for approval or a new actionable response. No external marketing email was
sent during this closeout. See the [Stage 3d record](audience-mailchimp-parity.md#stage-3d--non-live-closeout-and-ses-activation-2026-10-17)
for refreshed tests, local migration evidence and the bounded live acceptance plan.

**Non-live implementation and qualification are complete; overall Stage 3 remains
open.** The remaining release of local UI refinements requires a separate explicit
s1 deployment request. Final controlled inbox/authentication, simulator events,
provider-backed scheduling/quota pacing and report reconciliation require AWS
approval under MessageMonkey’s current readiness policy. Each other MOED account
must activate its own SES configuration; sharing the application release does not
copy credentials or grant sender access. Exact Mailchimp pixel/client parity is
not certified, and SES account setup is an intentional native adaptation.

Primary references reviewed: [SES pricing](https://aws.amazon.com/ses/pricing/),
[SES v2 SendEmail](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html),
[GetAccount](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetAccount.html),
[regional setup](https://docs.aws.amazon.com/ses/latest/dg/regions.html),
[production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html),
[quotas](https://docs.aws.amazon.com/ses/latest/dg/manage-sending-quotas.html),
[event destinations](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_CreateConfigurationSetEventDestination.html),
[SNS signature verification](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html),
and [metric limitations](https://docs.aws.amazon.com/ses/latest/dg/faqs-metrics.html).

### Stage 4 — native collection, October 17, 2026

FO01–FO12, LP01–LP08 and SU01–SU07 now have native local implementations and
isolated qualification. The [Stage 4 implementation record](audience-mailchimp-parity.md#stage-4--native-collection-and-local-qualification-october-17-2026)
describes the reference inspection, isolated HTTP qualification,
encrypted-response and opt-in lifecycle, narrowly scoped public embedding,
restore-verified local migration and remaining acceptance. Existing imported
records and storage were preserved. Stage 4 subsequently shipped in shared release 2.6.0.

The local completion pass adds safe hosted system-page HTML, editable public
labels/messages and RTL, challenge-bound confirmed-signup attribution, survey
dropdown/Other/option controls, recipient-only answer tagging and scheduled closure.
Template cards now have aligned controls above full-content previews, gray headers,
teal borders and fourteen distinct licensed Israel photos. The same photos are
available privately in local Shalom in Messiah Content → My files. Realistic image
payload qualification also covers sorting without loading image documents into
MySQL's sort buffer. See the implementation record for evidence and test counts.

The feature-matrix rows remain `partial`/`approximate` for vendor parity: exact
popup/landing/editor pixels and production host/edge behavior are not certified.
Controlled confirmation-email acceptance still awaits AWS approval. Native forms
retain immutable published revisions and protected consent actions. The signed-in
reference account was inspected without changing its records.


### Stage 5 — native automation flows, October 17, 2026

FL01–FL14 now have native local implementations or an explicit classic-compatibility
adaptation. The [Stage 5 qualification record](audience-mailchimp-parity.md#stage-5--native-automation-flows-october-17-2026)
describes immutable flow revisions, durable event cursors and effects, native
triggers, time windows, conditional/percentage paths, suppression-aware email
queues, data actions, pause/resume, reports and conversion boundaries.

The final disposable environment passed **64 flow checks and 661 total Audience
HTTP checks**, including restricted-worker privileges, native delivery no-replay,
credential rotation/binding and previous-stage regressions. Document/DAG/DST/event
checks, the full Composer suite, 316 JavaScript checks, route analysis and generated
assets passed. Actual local browser checks covered gallery filtering/full-page
preview, draft creation/naming, map insertion/inspector, keyboard save while a
field stays focused, persistence on return, undo/redo, zoom, reports, scoped
connection-picker empty state, and recoverable remove/restore. The temporary local
draft was returned to Trash; it was never activated or enrolled.

A fresh encrypted database/files backup was restore-verified before the local
migration. All 126 pre-existing application tables and existing delivery rows were
verified unchanged; existing volumes and network assignments were retained.
All four affected local service health checks passed after writers resumed.
Production and AWS settings were untouched. The receipt is retained privately at
`var/deployment/stage5-local-receipt.json` with its backup path and checksum.

Native non-live Stage 5 work is qualified. Feature rows remain `partial` and
`approximate` for vendor parity: the reference account provided empty lists,
gallery and template-preview surfaces, but no populated flow editor/report was
available for a pixel comparison. Email nodes intentionally reuse native saved
emails and their settings/designer rather than an inline vendor composer.
Live flow email, receipt/inbox behavior and client rendering remain gated by
Stage 3 AWS production approval and controlled live qualification. Mobile/dark
Stage 5 responsive/theme acceptance was subsequently completed as recorded below.

### Audience toolbar follow-up — October 17, 2026

The [toolbar qualification record](audience-mailchimp-parity.md#audience-toolbar-follow-up--october-17-2026)
documents the deeper read-only reference inspection and native implementation.
Status/tag/source menus now remain open for multiple selections while results
refresh in place. Chips, advanced editing, preview, saved segments and filtered
campaign drafts share validated criteria. Source categories retain original
provenance; three visible tags use an exact `+ N` overflow link. The headline
excludes pending/cleaned records while preserving all stored connections and a
separate cleaned shortcut.

The isolated suite passed 21 new toolbar checks and 682 total Audience HTTP
checks, plus 10 pure composition checks, full Composer, 316 JavaScript checks,
route analysis and generated assets. Actual local browser verification covered
multiple checked options without losing the open menu/search, criteria handoff,
selection/columns/export controls and the overflow count. No local segment or
campaign was created and no connection subscription changed. The approved CSV
comparison identified two later unsubscribes and two missing cleaned addresses;
a remaining one-record Mailchimp CSV/header discrepancy is unresolved.

Native combined name/email search, bounded criteria and account permissions remain
intentional adaptations. Vendor-only fields, exact pixels and mobile/dark browser
acceptance remain partial. This follow-up changed local source/assets only.

### Stage 5 responsive/reference closeout — October 17, 2026

The [responsive closeout](audience-mailchimp-parity.md#stage-5-responsive-and-reference-closeout--october-17-2026)
records 56 native browser combinations across seven flow screens, four viewport
widths and both themes, plus keyboard/dialog checks. Mobile editor margins and
tablet heading controls were corrected; preview breadcrumbs, back navigation,
spacing and node accents were refined against the current signed-in reference.
Mailchimp's populated editor/report remained unavailable, so exact pixels are not
certified. No vendor flow was created or changed.

The final isolated suite passed 66 flow checks and 684 total Audience HTTP checks,
including explicit activation rejection without SES production access. Pure flow
checks, 316 JavaScript checks, route analysis and generated assets passed. The
unused local draft was returned to Trash without activation or enrollment; theme
and temporary viewport settings were restored. The minor release request targets
shared version 2.6.1 through the normal verified-backup and readiness workflow.
Real flow email and inbox acceptance remain gated by AWS production approval.


Stage 6 native implementation is qualified locally on
`codex/mailchimp-cloning-continued3`; see the Stage 6 record in
`audience-mailchimp-parity.md`. Sources and notifications start disconnected/off.
Live gateway receipt and AWS-dependent reply/attachment/notification/SNS round trips
remain gated. The native acceptance evidence does not claim full vendor parity or
a production release. Home and full Analytics belong to Stage 7; Stage 8 content
assistance is intended to reuse MOED's existing Ollama engine on shunbun through
the established authenticated tunnel, without changing that engine during Stage 6.


### Stage 6 receiving and Stage 7 dashboard work — October 17, 2026

The current record is in [the Stage 7 qualification notes](audience-mailchimp-parity.md#stage-7-home-and-email-dashboard--october-17-2026).
The restricted native receiving pipeline is qualified with signed routes, durable
mailbox discovery/checkpoint storage, MIME attachments, replay protection and
rejection cases. The existing production Proton gateway was read with IMAP PEEK.
One explicitly approved self-addressed operational SMTP email arrived with its
signed plus address, body and attachment intact, but Proton asserted `unverified`.
It therefore does not qualify positive automatic Inbox association. Stage 6 is
not deployed in production; a receiving-verified message through that release
remains an operational prerequisite. Sources and all live sending remain off.

Stage 7 now has separate Home and Email dashboard layouts, reference date
popovers/calendars, previous-period comparisons, message/type/bot filters,
independent metric/interval charts, a native observational funnel, scoped CSV
and report links. Local fictional data covers current, preceding 30-day and
preceding 90-day periods. A/B testing is now excluded everywhere, including popup
variations; records are retained but configuration and execution are blocked.
The full isolated suite passes 768 Audience checks, including 36 Analytics checks,
plus 13 restricted gateway checks. Custom reports, historical growth/source
analytics, advanced drilldowns and full vendor parity remain open Stage 7 work.

#### Stage 7 native closeout, October 17, 2026

The remaining non-AWS scope is qualified locally: audience join/source/transition
analytics, recipient/link/client drilldowns and sandboxed immutable click maps,
custom saved/shared reports and detailed exports, cross-form/flow comparisons,
and native historical/content/timing insights. See the appended Stage 7 closeout
in `audience-mailchimp-parity.md` for 831 Audience/99 analytics checks and the
phone/tablet/light/dark evidence. No production deployment or real sending was
performed. A/B stays excluded. Provider round trips and unavailable historical or
external evidence are explicitly separated from locally qualified native work.
