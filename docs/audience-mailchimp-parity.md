# Audience: Mailchimp interface and feature parity

Original reference branch: `codex/mailchimp-clone`

Stage 3 closeout branch: `codex/ses-endpoint-layout`

Current local follow-up branch: `codex/mailchimp-cloning-continued2`

Reference review: October 16, 2026

## Local follow-up — October 17, 2026

Based on deployed release 2.5.25. These changes are available through the local
development bind mount and rebuilt assets; they have not been released to s1.

- Connections use **Status** for the former marketing-status column and its
  corresponding form/export labels.
- MessageMonkey navigation uses the reference normal/active SVG pairs, served
  locally and tinted for both themes. Provenance and hashes are recorded in
  [the icon source ledger](audience-nav-icon-sources.json). Hover and keyboard
  focus change the icon/background without underlining links. Current main and
  submenu rows retain their highlight without a selection outline; a selected child owns
  selection while its section is expanded. The main MOED sidebar is unchanged.
- Supported email types are regular, plain-text and A/B. A/B configuration and
  dispatch accept one variable with two or three variations; unsupported kinds
  and combined-variable snapshots are rejected before delivery.
- Brand Preview grows to its content, including image/font loads and width
  changes. Only this preview permits same-origin measurement; sandboxed script
  execution remains disabled. Other preview sandboxes are unchanged.

Verification: changed PHP syntax, route PHPStan, generated assets, 27 campaign
validation checks and 18 delivery-runtime checks passed. The isolated database
suite initially passed Stage 1b storage, 82 campaign completion HTTP checks, 46
Content/Brand HTTP checks and 28 SES setup HTTP checks, then stopped because the
older local test image lacked `Aws\Sns\MessageValidator`. The image and full
qualification were subsequently refreshed as recorded below.
Reference-template checks covered icon hover/focus/selection, selected submenu
geometry in both themes, and collapsed icons. The running authenticated localhost
Brand Kit was refreshed and visually verified with the saved logo and the
content-sized preview. No account data or external mail was changed by these checks.

The first disposable database startup failed when Docker's virtual disk was
full. Removing 1.847 GB of regenerable build cache left approximately 643 MB free
(99% used). Subsequent tests used labelled disposable memory-backed volumes with
the normal storage safeguards enabled; those resources were removed afterward.
Existing application data, volumes and release images were retained.

### Docker capacity and qualification closeout — October 17, 2026

- Increased Docker Desktop's disk limit from 61,035 MiB to 131,072 MiB through
  its resource settings and applied the required engine restart. The resulting
  filesystem has approximately 65 GiB free (47% used).
- Compared metadata before and after: all 120 pre-existing containers and 57
  volumes remain present, mounted storage is unchanged, and all 62 previously
  running containers resumed. MOED services with health checks are healthy. A
  read-only audience check still reports 10,884 Connections: 7,203 subscribed,
  1,854 unsubscribed, 1,825 cleaned and two non-subscribed.
- No Docker network configuration changed. All static assignments were retained;
  no duplicate assigned/reserved addresses or overlapping Docker subnets were
  found before or after the engine restart. This verifies the performed restart,
  not every possible stopped-container allocation or startup order on the
  pre-existing networks.
- Rebuilt `dnr-app:local` from the current Dockerfile and lockfile. Qualified
  image ID: `sha256:9772134b8338c8fd00196e008768593d55225bca17f76d859617aeb2c63166b3`.
  The image includes both the SNS signature validator and SES v2 client.
- The standard disposable Audience integration command completed successfully
  using the rebuilt image and ordinary disposable disk volumes. Results include
  Stage 1b storage, 82 campaign completion checks, 46 Content/Brand checks, 28 SES
  setup checks, 18 signed-event/storage checks, 58 delivery completion checks,
  94 campaign dispatch checks and 444 Audience HTTP integration checks. Restricted
  worker checks passed for durable SNS retry/confirmation, forged-envelope
  rejection, suppression, quotas/throttling, ambiguity/no replay, credential/API
  access and mail-role isolation. Encrypted-credential rotation, account binding,
  empty rows and key retirement also passed. No external email was sent. The
  helper removed only its own labelled test resources; pre-existing volumes were
  verified again afterward.
- Removed the persistent selection outline from both main and submenu rows at
  the user's request, retaining the background highlight and normal keyboard
  focus indicator. Generated assets pass validation. Browser inspection of the
  current template confirms a selected submenu has a colored background with
  no border, outline or box shadow. Docker's restart expired the previous local
  browser session; viewing the authenticated page again requires signing in.

The local capacity/dependency blockers are resolved. Later-stage implementation
can proceed independently of AWS approval. Stage 3 live SES qualification still
requires production access and separately authorized controlled recipient sends.

### Workspace search dialog — October 17, 2026

Clicking the centered header search opens a rounded, modal search dialog modeled
on the current Mailchimp search surface. It contains the search input and submit
button, Close control, filter pills, recent searches and recent campaigns with
status badges and last-edited dates. Popular integrations, the Integrations
filter and the vendor feedback footer are omitted. The dialog follows the
workspace theme and wraps its filters on narrow screens.

The read-only search endpoint covers active Connections by name/email, campaign
names, existing workspace pages, permitted quick actions and existing help
links. It uses the authenticated account's canonical audience, excludes deleted
records and archived Connections, bounds result sizes and returns uncached JSON.
Reviewer results omit editor-only quick actions and import/delivery pages.
Later-stage pages are not represented as available search destinations. Recent
search terms remain in browser storage under the existing account/user/audience
key, with at most five entries; opening or typing does not change account data.

Implementation: `src/audience_search.php`, the early API branch in
`src/audience.php`, `src/templates/audience/search.php`, the shared workspace
header and the Audience JavaScript/CSS bundles. Browser checks covered real
authenticated localhost page results plus fictional populated campaigns in both
themes, a 390px frame, filter selection, recent-search replay, unavailable-result
handling, modal keyboard containment, Close and Escape with focus restoration.
Keyboard checks used the unextended in-app browser; the authenticated Firefox
page also confirmed the working search, suggestions and Close focus restoration.

The local development bind mount serves this change; no production release or
external email is part of this follow-up. Qualification passed PHP syntax,
route PHPStan, generated-asset validation and the full disposable Audience
suite, including 29 new search HTTP checks and 473 total Audience HTTP checks.
Search checks cover authentication, account/audience isolation, role restrictions,
deleted/archived exclusions, literal query handling, safe JSON, bounded recent
campaigns, invalid input, the campaign-list query limit and absence of data writes.
The worker, SES/SNS, suppression, no-replay, quota and credential checks also
passed; only the helper's own labelled disposable resources were removed.

## Product decisions

- Enter the marketing workspace from **Relationships → Audience**, immediately below Speakers.
- Reproduce Mailchimp's navigation, page structure, control placement, terminology,
  and workflow order. The deliberate terminology exception is **Connections** in place
  of Mailchimp's Contacts. MOED Contacts remain associated with Organizations.
- Each MOED account has one unitary Audience. No audience selector or creation flow is exposed; Connections are organized with tags, groups and segments.
- Header: **MOED MessageMonkey**, non-clickable SVG monkey and label; return link: **Return to MOED dashboard**. The monkey uses thick black outlines and white facial areas on the white header in light mode, and the original white-on-transparent artwork in dark mode.
- MOED owns the marketing implementation and data. Mailchimp is a reference,
  not the backend. Select delivery providers in the sending stage.
- Audience supports light and dark mode using MOED's sidebar theme selection.
  The shared initializer applies the preference before paint; an open Audience
  tab follows preference changes and restored pages recheck the selection.
- SMS, the Website builder, Integrations, and features requiring external
  integrations are explicitly excluded. Campaign landing pages and their signup
  forms remain included. Remove excluded options throughout the product, including
  creation menus, templates, editor blocks, segment conditions, flow nodes and
  reports, rather than only removing their sidebar entries.
- Deliver the remaining suite in qualified stages. A visible navigation item does not
  establish feature parity. Incomplete areas must remain visibly unavailable.
- Preserve one shared implementation across accounts. Marketing people belong
  to the current account database; no automatic copying or subscription of CRM
  Contacts. Apply existing role and demo policies on backend requests.

## Reference observations

The signed-in account's current interface was inspected without modifying its
data. It has a thin yellow top border, a white global header with central search,
a roughly 240px left navigation, teal primary actions, pale selected navigation,
and bordered tables with compact field labels. The displayed typeface is Graphik
Web with Helvetica/Arial fallbacks. This implementation uses the fallback stack;
the proprietary typeface is not bundled.

Observed Mailchimp navigation order: Create, Home, Campaigns, Automations, SMS,
Forms, Audience, Analytics, Website, Content, Integrations. MOED keeps this order
while omitting SMS, Website and Integrations. Audience expands into Tags,
Segments, Surveys, Subscriber preferences, and Inbox. Campaigns expands to
Email templates; Automations expands to Flow templates and Transactional.

The People reference screen (Mailchimp labels it Contacts) places management and
add actions at top right, then search, segments, subscription status, tags,
signup source, and advanced filters. Counts sit above the table; Columns and
Export sit in its toolbar. The reference account includes custom fields, which
must become configurable fields rather than hardcoded account-specific columns.

Campaigns has List/Calendar views, search, type/status/folder/date filters,
sorting, and rows with status, audience, analytics, and actions. Automations has
Automation flows/Classic Automations tabs and entry points for building from
scratch or choosing templates. Plan-gated functionality needs further inspection
against official documentation; it is not verified by a navigation label alone.

## Delivery stages and acceptance boundaries

| Stage | Deliverable | Completion evidence |
| --- | --- | --- |
| 1a | People foundation: navigation, list, profile, manual entry, tags, status and permission history, search/filter, saved filter segments, CSV export, archive/restore | Storage and consent tests, role/CSRF checks, responsive rendering; no sending |
| 1b | Full audience workflows: CSV import with mapping/preview/error results, bulk operations, editable tags and segments, custom fields, groups/preferences, one canonical audience per account, profile activity and complete advanced filter builder | Import reconciliation, no loss of opt-out state, account isolation and reference-screen comparisons |
| 2 | Campaigns, templates, content library, email designer and previews; campaign list/calendar/folders; sender/recipient/subject/content checklist; personalization, reusable blocks and custom HTML | Saved editable drafts and revision handling; rendered email tests; editor comparison |
| 3 | Email delivery provider adapter, sender/domain setup, schedule/cancel, recipient snapshots, suppression checks, unsubscribe/preferences, bounce/complaint events and basic reports | Controlled delivery to designated test recipients, retries without duplicate sends, opt-out enforcement before dispatch |
| 4 | Forms, hosted/embedded/popup signup, double opt-in, surveys and preference center | Public submission, verification, abuse controls, tagging and reporting; reference workflow comparisons |
| 5 | Automation map, templates, triggers, splits, waits, actions, pause/resume and reports | Deterministic event execution and replay, suppression at each send, visible queue/history |
| 6 | Audience inbox and reply history | Inbound reply routing, thread association, permissions and reply reconciliation |
| 7 | Full analytics, A/B campaigns, comparison reports, conversion attribution and optimization | Reproducible metric calculations, event provenance, experiment assignment and bot filtering |
| 8 | Content expansion and AI assistance | Asset management, permissions and user-reviewed generated content |

Some stages can overlap after their data dependencies are stable. These are
implementation boundaries, not claims that all of Mailchimp has been reproduced.
The detailed inventory and qualification criteria are recorded in the
[October 16 audit](audience-mailchimp-audit.md) and its
[feature matrix](audience-mailchimp-feature-matrix.csv). They supersede broad
stage descriptions where scope is more specific. In particular, stage 4 includes
campaign landing pages; stage 7 excludes connected-store revenue attribution and
conversion insights. Native signup and email engagement outcomes remain included.

## Official feature research

- [Feature directory](https://mailchimp.com/features/): email creation, content,
  targeting, automation, reports and AI assistance form distinct product areas.
  SMS, Website and third-party Integrations are excluded from MOED’s parity target.
- [Audience overview](https://mailchimp.com/help/getting-started-audience/) and
  [audience creation](https://mailchimp.com/help/create-audience/): tags are
  operator-assigned labels, groups represent interests/preferences, and segments
  are rule-defined sets. Fields supply personalization data. These concepts
  should remain distinct in MOED.
- [Contact types](https://mailchimp.com/help/about-your-contacts/): subscribed,
  unsubscribed, non-subscribed, pending and cleaned are distinct states. Archive
  is separate. Preserve email permission semantics
  when replacing the UI label Contacts with People.
- [Profiles](https://mailchimp.com/help/about-contact-profile-pages/): identity,
  origin, marketing status, tags/groups, activity, notes and settings are separate
  profile concerns. Commerce/predictive sections depend on connected data.
- [Email builder](https://mailchimp.com/help/design-an-email-new-builder/):
  reproduce block-based design, layout/style controls and preview/test workflows
  in stage 2; inspect the actual editor before claiming visual parity.
- [Automation creation](https://mailchimp.com/help/create-customer-journey/) and
  [triggers](https://mailchimp.com/help/all-the-starting-points/): model trigger
  entry, rule/action nodes, branching, delays and execution state. Email,
  tagging, groups, profile updates and archiving need real execution,
  not just a diagram editor. Exclude SMS and external-integration actions.
- [Signup options](https://mailchimp.com/help/about-signup-form-options/),
  [surveys](https://mailchimp.com/help/use-surveys-mailchimp/): support hosted,
  embedded and popup collection, configurable fields, publication and results.
- [Reports overview](https://mailchimp.com/help/getting-started-reports/): retain
  campaign/channel distinctions and calculate audience growth, delivery,
  engagement and conversion metrics from recorded events. Never present example
  metrics as actual account results.

## Stage 1b: implementation and remaining limits

People now supports typed custom fields, profile tabs, attributed notes, VIP,
groups, lifecycle operations, advanced nested rules, sorting, pagination,
selection/bulk actions and persistent column visibility/order. Tags and Segments
have searchable indexes and split action menus. Profile tags have outlined chips
with individual removal, a searchable picker and transactional create/apply.
Marketing status expands into source and permission details; imported timestamps
retain their original values without inventing a timezone or a new opt-in date.
Profile refinements include aligned detail rows, reference-style tabs and notes
composer, dedicated marketing/details/groups/settings editors, status help,
and calendar-year activity filtering. Editors update only their section and
preserve unrelated fields and memberships; invalid/stale submissions reopen
with their values and error. The sidebar now has a persistent 80px icon-rail
collapse control and an Audience submenu on hover/keyboard focus. Both themes
use the same layout. Address fields now expose street, address line 2, city,
region, postal code and country while retaining scalar storage and CSV compatibility.
Legacy imported text is preserved. Predicted demographics, email-format preferences
and campaign engagement still belong to their later-stage dependencies.

Imports accept CSV/TXT/TSV/XLSX or pasted data, with field mapping, preview,
consent/status review, per-row results, detached jobs, resume/cancel and limited
undo. Exports capture stable snapshots of selected or all matching people.
Import/export history and complete person-data export are implemented. Editors
and administrators may manage/import/export; reviewers can inspect but cannot
write or export. CSRF and account scope are enforced by the backend.

This is not full Mailchimp parity. Campaigns, email editors/sending, forms,
landing pages, surveys, automations, inbox and reporting remain later stages.
Campaign actions in tag/segment menus are visibly unavailable. The general
Create overlay and global marketing search require the later workspaces. The Stage 1
UI/UX gate below qualifies the implemented People/data-management baseline, not full
Mailchimp parity or every assistive-technology/browser combination. Tags/Segments now have confirmed page-selection
bulk deletion with atomic version/account checks. Native suggested segments were
already present; the stale audit entry is corrected. Quoted list cells preserve
literal pipes and quotation marks in tags, groups and checkbox choices. Native uploads
are bounded at 20 MB/50,000 rows; persistent Mailchimp ZIP import is not a product
feature yet. The supplied ZIP was imported through a guarded local migration.

## Stage 1b verification

- PHP validation, import parsing, typed rules, consent/suppression and permission
  timestamp tests pass. PHP syntax, level-5 analysis of the route/core helpers, and generated-asset identity checks pass.
- Disposable storage tests cover isolation, typed fields, group/tag membership,
  nested rules, optimistic concurrency, notes, import reconciliation, resumable
  jobs, immutable exports, undo, deletion and reimport restrictions.
- Authenticated Audience HTTP tests pass **81 checks**, including role/CSRF
  enforcement, scoped profile editors, consent, stale-form rollback, calendar-year
  activity filtering, profile tags, bulk definition deletion, canonical audience routing,
  management screens, import wizard/detached worker, exports and isolation.
- The broad PHP test suite passed; relevant heading, UI structure, asset and
  migration-integrity checks passed again after the latest UI edits. The existing
  data-management integration suite passed for CRM/financial/merge regressions.
  A separate reimbursement integration run was blocked by the cached local image
  lacking `/usr/bin/qpdf`; do not describe that suite as passed.
- Browser checks used the signed-in local app for People, columns and themes,
  then rendered fixture previews for the latest dropdown, logo and profile
  refinements. Both permission detail rows were checked after the alignment fix.
  Fixture previews are not substitutes for an authenticated reference parity run.

Production deployment remains unrequested and has not occurred. No sending
provider is selected, and importing does not schedule or send marketing email.

## Local application update

The first local attempt exposed the source-mounted route before its migration
and web grants were applied. Request `bf94b8f646bcf52948686142` failed when the
application user attempted to read `marketing_segments`.

On October 16, 2026, fresh encrypted backups passed restore verification for
Shalom in Messiah, Test Account, Aleph, and Account Isolation Preview. The normal
migration runner then applied `20261016_add_marketing_audience.sql` and refreshed
web grants in all four local databases. All paused writers resumed; all four
accounts passed HTTP readiness and marketing-table reads using application
credentials. Recovery receipts are under `var/backups/audience-local-update/`.

The actual signed-in Firefox session successfully opened People, Tags, Segments
and Add a single person at `/a/shalom-in-messiah/audience.php`. No sample people were inserted during that initial repair; the later fixture and export import work is recorded below. Future schema changes must run through the local migration path
before a source-mounted route is reported ready; a static preview is insufficient.

## October 16 local Stage 1b update and Mailchimp import

The stage expansion and nullable tag-created timestamp migrations were applied
through the normal runner to all four local accounts after encrypted backups
were restored and verified. Existing CRM/business schemas and data were checked
for isolation. Recovery receipts are retained locally under
`var/backups/audience-stage1b-update/` and
`var/backups/audience-tag-timestamp-update/`; exported personal data is not kept
in this document or committed to the repository.

Only localhost:8080 **shalom-in-messiah**, canonical audience 1, was populated.
At the user's request, its 87 fictional People, 10 test tags, five test segments,
and associated fixture fields/groups/history were replaced in one guarded
transaction using `audience_export_9c22c2e710.zip`. All 10,884 normalized source
identities, profile values, tag/group memberships, and original CSV rows were
reconciled after import:

| Imported state | People |
| --- | ---: |
| Subscribed | 7,203 |
| Non-subscribed | 2 |
| Unsubscribed | 1,854 |
| Cleaned | 1,825 |
| Total | 10,884 |

Also retained: **21 tags**, **five custom fields**, **three group categories**
with **seven options**, 23,625 tag assignments and 17,601 group assignments.
The ZIP contains no saved segment definitions, so Segments is intentionally empty.
Historical tags/preferences named SMS are data only; no SMS functionality exists.
Two malformed addresses were preserved exactly as unsubscribed records. Source
Mailchimp timezone codes that are not IANA names, IDs, ratings, dates and other
export metadata remain in the original import rows/history rather than being
silently guessed or discarded. Imported tag creation dates were not in the file
and remain unknown. MOED's creation timestamp records the import itself.

The metadata-only reconciliation receipt is
`var/backups/audience-mailchimp-import/receipt.json`. All nonmarketing business
table checksums match the verified recovery snapshot; operational
`data_management_health`, `security_audit_log`, and `users` differed across the
interactive testing interval. The import script writes only marketing tables.
No other account data was modified by the import.

## Stage 1 UI/UX qualification — October 16, 2026

**Gate result: passed for the implemented Stage 1 People/data-management baseline;
ready to start Stage 2 campaign management.** No blocking defect remains in the
flows tested below. This is not a claim of pixel-perfect or full-suite Mailchimp
parity. The feature matrix continues to retain unimplemented capabilities.

Reference inspection used the signed-in Mailchimp People, add-contact dialog,
Groups/create-group, audience fields/create-field, import method chooser and
segment builder, plus the supplied profile/menu/column screenshots. No Mailchimp
records were created, changed or deleted.

### Corrections made during qualification

- Compact Add a person dialog with expandable additional details and standalone
  fallback; permission controls remain explicit. Typed addresses now have six
  components without a schema change; original imported text is retained.
- Searchable grouped segment combobox with arrow/Enter/Escape behavior,
  duplicate/delete controls, AND/OR additions and focus restoration after edits.
  Mixing AND/OR preserves existing rule meaning through nested groups.
- Group-type radio choices, option add/remove/reorder focus, collapsed group cards;
  field action menus, immutable type controls, conditional choice editing and
  suggested merge tags. Existing field-type values remain in submissions.
- Segment information popover, first/previous/page-number/next/last pagination,
  default 100-row view and native suggested-segment detail entry points.
- Tag/group/field/segment/bulk-tag errors reopen the editor with submitted values
  and the original optimistic version. Import paste/mapping inputs and stale note
  drafts survive errors. Audience-default errors retain the submitted version.
- Filter popovers have coherent dialog semantics, searchable/empty states, wrapped
  long labels and explicit Escape-to-trigger behavior. Custom choice fields use
  named fieldsets. Columns keep draft changes separate until Apply.

### Verification record

| Area | Evidence and result |
| --- | --- |
| People / empty / reviewer states | Search/filter controls, columns cancel/apply and keyboard reorder, page/all-match selection; reviewer writes/exports denied |
| Person profile | Overview, notes, settings, marketing disclosure, section editors, tag chips, address/custom/group controls; stale writes preserve stored data |
| Tags / groups / fields | Page selection and confirmation, split menus, repeated field editing with correct immutable type, create/edit validation recovery, group option focus |
| Segments | Search, row actions, suggestions, grouped search picker, typed operators, duplicate/delete, nested AND/OR, preview API and version checks |
| Import / export | Upload/paste selection, organize/tag/match/subscribe/review, error/results/retry surfaces; real detached processing, error CSV, snapshot export, undo and scope tested over HTTP/storage |
| Responsive/theme | 21 rendered views × light/dark × 1280×900/390×844 = 84 checks, no PHP errors or page overflow; separate visual checks of mobile dialogs/filter menus, long labels, and desktop segment editor |
| Keyboard | Dropdown search and Escape return, combobox arrow/Enter/Escape, modal cancel, column reorder, group option add/remove/reorder focus; native focus rings retained |
| Backend regression | **87 HTTP integration checks passed** in disposable Docker databases; storage/consent/concurrency/import/export/address round-trip tests passed |
| Static/build | PHPStan level 5 clean; PHP/JS syntax, heading consistency, UI consistency, minified asset checks and diff whitespace checks passed |
| Local deployment | localhost:8080 CSS and JS return 200 and match the generated workspace assets; shared bind-mounted implementation, no schema/deployment restart required |

Browser rendering used the actual templates with fictional records. Backend and
persistence checks ran separately against the actual PHP routes in disposable
Docker accounts. The real imported Shalom data was not altered by qualification.
This distinction matters: preview screenshots do not themselves prove a write
persisted or a job completed.

Evidence from this run (temporary local files):
`/private/tmp/audience-qualification-rendering.json`,
`/private/tmp/audience-qualification-final-tests.log`,
`/private/tmp/audience-qualification-final-phpstan.log`,
`/private/tmp/audience-qualified-people-filter.png`,
`/private/tmp/audience-qualified-segment-light.png`,
`/private/tmp/audience-qualified-group-dark-mobile.png`.

### Known boundaries carried forward

- Stage 1 uses MOED's People naming, canonical audience, authorization model,
  accessible fallback font, native date controls and shared dark theme. These
  intentionally differ from the vendor product.
- Uploads remain capped at 20 MB/50,000 rows. The product accepts CSV/TXT/TSV/XLSX;
  ZIP ingestion was a one-time local migration, not a general uploader. Table
  exports are CSV; complete-person exports are JSON. Mailchimp export packaging,
  download expiry and notifications remain explicit backlog items.
- Built-in identity fields are fixed; custom fields are editable. Country is a
  free-text address component. MOED exposes its supported typed-field catalog,
  not every vendor-specific data type/format or prediction.
- Suggested segments currently use real native subscription/VIP/date data.
  Engagement suggestions, ratings, predictions, campaign targeting, send-time
  evaluation and confirmation/bounce events require their later-stage sources.
- General Create/search/Home/notifications, sender verification, public forms,
  preferences and campaign archive settings stay in the dependency stages listed
  in the audit. They are not counted as completed by this Stage 1 gate.

Stage 2 starts with the campaign list/organization and persisted draft management,
then checklist, templates/content and design/preview. Sending remains Stage 3.

## Stage 2a — campaign management (2026-10-16)

Campaign management is available at `audience.php?view=campaigns`. This qualifies the initial management slice, not the entire Stage 2 editor/template scope or complete Mailchimp parity.

### Implemented and verified

- Campaign navigation and selected state; All campaigns List/Calendar tabs; Details/Analytics/Actions layout; searchable campaign names; type/status/folder/date filters; edited/created/send-date/name sorting and direction; 25-row paging.
- Searchable folders with create, rename and delete. Deleting a folder preserves its campaigns as unfiled items. Folder and campaign edits use version checks.
- Page selection and explicit selection of all matching results, capped at 5,000 records. Bulk move/delete operates on the selected id/version snapshot, never silently including records created afterward. Stale selections roll back the whole operation.
- Draft creation for regular and plain-text emails, recent-campaign reuse, rename, replicate and delete. Tag and segment menu email actions open targeted draft creation; removed target definitions cannot silently broaden the new draft. Replication resets delivery state and send date. Active scheduled/sending records cannot be deleted. Sent content cannot be edited.
- Draft checklist persists To, From, Subject and plain-text Content independently, retaining submitted values after stale edits. To supports all subscribed People, one tag or saved segment, and a tag exclusion. Archived, deleted and non-subscribed People are excluded; stale target definitions fail visibly.
- Month calendar, Today and previous/next month. Drafts use last-edited date; sent records use send date. Dates are explicitly UTC. No invented delivery analytics, optimization suggestions or sending behavior.
- Creation screen, folder and row menus, keyboard menu navigation, native dialog focus/escape, confirmations, theme inheritance, responsive layouts and existing sidebar collapse.

### Reference evidence

Read-only inspection of the signed-in Mailchimp account on October 16, 2026: `/campaigns`, List and Calendar, Type/Status/Sort controls, folder search/create dialog, date filter, draft overflow menu, Create overlay, and an existing draft checklist. No source campaigns were created, changed, replicated or sent. Existing user-owned Mailchimp tab was preserved.

The native management UI follows the observed layout and labels. Known differences remain: Arial/Helvetica fallback rather than the proprietary reference font; MOED branding and single audience; regular/plain-text creation only; public browser preview, team draft-sharing, holiday overlays, date presets or Campaign Manager plans yet. Calendar dates are UTC. The full visual designer/templates, design revision history, tracking settings, personalization, multi-target/exclusion controls, and preview/test-email tools remain subsequent Stage 2 work. Delivery verification, scheduling and sending remain Stage 3. Email templates and Send are explicitly unavailable, rather than pretending to complete those operations.

### Qualification and local migration

- Disposable authenticated suite: **126 HTTP checks**, plus existing Stage 1b storage suite. Covers CRUD, CSRF, reviewer permissions, isolation, stale edits, atomic bulk rollback, all-results snapshot semantics, folder preservation, suppression/exclusions, immutable sent content, safe replication and blocked active-delivery deletion.
- PHPStan level 5: no errors for route, campaign controller/trait and shared store. PHP syntax, JS syntax, minified-assets, heading and UI consistency checks passed.
- **16 render checks** across campaign list, empty state, calendar and checklist at desktop (1280 px) and mobile (390 px), each light/dark: no PHP warnings or page overflow. Manually checked creation, rename, folder search and selected-record propagation into move dialogs. Preview uses fictional data; it is not an authenticated screenshot of the user's account.
- Encrypted full localhost database backup restored and fingerprint-verified before applying the additive `20261016_add_marketing_campaigns.sql`. Backup receipt: ignored `var/backups/audience-stage2/20261016T212209Z-1d8527c4/receipt.json`. Application commit/version, database version/state and checksums are recorded there.
- Migration applied only to localhost's Shalom in Messiah database. Existing data remained **10,884 People, 21 tags, 0 saved segments**. No campaign test records were added to that account. No existing People or organization schema was altered.
- Live app-user campaign queries and current CSS/JS hashes verified on localhost:8080; campaign route retains normal login protection. No production deployment, app restart, branch switch, commit or push.
- Evidence: `/private/tmp/audience-stage2-final-tests.log`, `/private/tmp/audience-stage2-final-phpstan.log`, `/private/tmp/audience-stage2-render-checks.json`, `/private/tmp/audience-stage2-campaigns.png`.

Next implementation slice: regular-email visual designer and reusable templates, building on the saved native draft checklist.

## Stage 2b — visual designer and reusable templates (2026-10-16)

The workspace is now branded **MOED MessageMonkey**. Campaigns → Email templates opens the library; a regular draft's Content → Design email opens the visual designer. This is the working native designer/template slice, not a declaration of complete Mailchimp editor parity.

Implemented:
- Add/Styles inspector, content/layout tabs, draggable heading, paragraph, image, button, divider and spacer blocks; one to four columns; block move/duplicate/delete and layout ordering, with keyboard button alternatives. Rich text supports bold/italic/underline/lists, plain-text paste, and merge-tag insertion. Text and image blocks support links; buttons support colors and rounded corners.
- Email/body backgrounds, fonts, text/link/button colors, padding, alignment, widths and line height; desktop/mobile canvases and rendered previews. Workspace light/dark mode follows MOED while authored email colors remain unchanged.
- Debounced autosave, explicit Save, Save and exit, save/error status, in-session undo/redo, navigation warning for unsaved work, immutable sent content and optimistic version checks. Failed saves preserve the open editor's contents; no durable offline draft or server revision history is claimed.
- Required identity/address/preferences/unsubscribe footer placeholders, escaped text, allowlisted rich text and links, bounded PNG/JPEG/WebP uploads stored with the design. Images are limited to 700 KB and 16 megapixels; whole documents to 3 MB. Delivery-time asset hosting, merge resolution and unsubscribe endpoints remain Stage 3 prerequisites.
- Six native starter templates, category/search filters, newest/oldest/alphabetical sorting, grid/list views, Saved and Recently sent tabs; save as template, edit, rename, replicate, folder assignment/filter, delete, create email and reuse a sent design. These are native layouts, not copies of Mailchimp's proprietary template artwork. Reuse snapshots the document; later edits/deletion of a template cannot alter existing campaigns.
- Account-scoped storage and editor/admin mutation checks; CSRF on all saves; scripts disabled inside previews. Audience adds nonce-authorized preview CSS to its existing policy while retaining `style-src-attr 'none'`. Other pages retain their existing policy. No external image URL fetching, arbitrary HTML execution, or email dispatch is introduced.

Reference: read-only inspection of Mailchimp `/templates` gallery and Saved library, and the legacy editor of an existing draft; [new builder](https://mailchimp.com/help/design-an-email-new-builder/) and [layouts](https://mailchimp.com/help/use-layouts-new-builder/) documentation. No Mailchimp campaigns/templates were created, edited, or sent. The newer builder was documented rather than entered by creating a live draft. Pixel-identical new-builder qualification remains open.

Qualification: 150 authenticated disposable HTTP checks (including prior Audience/campaign regressions), 32 focused renderer/validation checks, PHPStan level 5, PHP/JS syntax, generated-asset and shared UI checks. Browser qualification passed 12 layouts at 390/1280 px, light/dark, with no horizontal overflow or PHP warnings. It used fictional local fixtures under the same preview CSP: editing, previews, template filtering, layout insertion, undo/redo and light/dark rendering. Live account browser login was not bypassed.

Local migration: `20261016_add_marketing_templates.sql` adds one independent table. Fresh encrypted backup was restore-verified before migration (`var/backups/audience-designer/20261016T214549Z-c8c8b264/receipt.json`), and migration receipt is in the same parent directory. Shalom's **10,884 People, 21 tags and 0 segments** were unchanged; no sample templates/campaigns were inserted into its live local data. Production was not deployed.

At the Stage 2b checkpoint (superseded by Stage 2c below), remaining parity included legacy/code-your-own template import/editing, advanced section/mobile style inheritance, saved sections, image cropping/cards/groups, video/social blocks, conditional content, table of contents, durable revision recovery, editor comments, recipient-specific preview, link validation, plain-text counterpart editing and full reference-template coverage. Email-client/inbox rendering qualification and actual test sends require the delivery stage. See the feature matrix for individual partial/missing statuses.


## Stage 2c — remaining designer features and account controls (2026-10-16)

The native designer feature pass is implemented. This is a functional qualification
of the included designer scope; it does not declare Stage 2 complete or promise
pixel-identical rendering across email clients.

- The Blocks / Sections / Styles / Optimize rail follows the current reference
  section-manager layout. Content / Style / Settings controls support global,
  section and block inheritance, independent desktop/mobile styles, defaults,
  borders, spacing and selected-text font/color/size/link formatting.
- Sections support Manage / Pre-built / Saved, rename, duplicate, move, save and
  delete with keyboard alternatives. Saved insertion regenerates unique IDs.
  Image cards/groups, linked video thumbnails, ordinary social links, custom
  code and generated table-of-contents blocks extend the original content set.
- Content Studio stores images and reusable sections with search, 20-result
  paging, rename and soft deletion. Image tools provide crop, zoom, positioning
  and aspect selection. Existing image/document bounds remain enforced.
- Section/block dynamic rules and bounded conditional merge tags use native
  People fields, defaults, tags and groups. Personalized preview has People
  search and live merge data. Link checking covers anchors, placeholders,
  merge fields and identity/alt gaps; explicit per-link HTTPS checks reject
  non-public addresses, unsafe destinations and redirect following.
- Serialized autosave, undo/redo and optimistic updates now include seven-day
  account/actor-scoped browser recovery and 30 server revisions with versioned
  restore. Comments retain author, timestamp, block context, replies and
  resolve/reopen state. Sent designs remain immutable.
- Code-your-own supports paste, HTML and bounded ZIP import, local image
  embedding, validation, named editable regions and safe HTML export. Legacy
  creation offers one to three columns. Raw original code is retained; current
  named-region edits survive reopening source. The protected preview strips
  scripts/active markup and unsupported CSS with warnings, blocks remote images
  and preserves supported safe tables/media queries. It does not claim perfect
  rendering of arbitrary imported HTML.
- A separately editable plain-text counterpart can replace automatic generation.
  Desktop/mobile preview and a clearly labelled approximate dark simulation are
  available. Actual test dispatch and client/inbox testing remain Stage 3.
- Twenty native starter templates cover the included categories. The library
  includes type / All filters controls and 20-result pagination. The observed
  288-item reference-artwork catalog is not reproduced.
- Shared upper-right controls use a notification bell and the authenticated
  user's 40px profile photo, with the existing initials fallback. The accessible
  popover shows that user's ten latest queued/processing/complete/failed Audience
  jobs from the last 30 days and links to operation details. Reviewer accounts
  see no privileged job records. Persistent unread state, live refresh and
  future send/flow notifications remain backlog items. No new notification
  schema, outbound message or external service was introduced.

Reference comparison used read-only Mailchimp template pages, official
[new-builder](https://mailchimp.com/help/design-an-email-new-builder/),
[section-manager](https://mailchimp.com/help/use-section-manager-new-builder/),
[dynamic-content](https://mailchimp.com/help/about-dynamic-content/),
[conditional-tag](https://mailchimp.com/help/use-conditional-merge-tag-blocks/),
[TOC](https://mailchimp.com/help/add-a-table-of-contents-to-a-campaign/) and
[template-code](https://mailchimp.com/help/where-to-edit-template-code/) references.
No Mailchimp record was created, edited or sent. The new builder's complete live
workflow has not been entered by creating a draft; reference typography, artwork
and exact pixel comparison remain open.

Browser qualification used fictional local fixtures under the production preview
CSP, separately from authenticated disposable HTTP checks. Exercised flows include
saved-section insertion/undo, image upload/crop/library save, comments/replies/resolve,
device-specific style persistence, personalized preview, plain-text editing, link
checks, code validation/region editing, revision restore, gallery filters and
notification open/close. All 12 layouts (designer/library/saved library at 390px
and 1280px, light/dark) fit without page-level horizontal overflow or PHP warnings.
The desktop preview modal fits its viewport without outer overflow.

Qualification passed **194 authenticated disposable HTTP checks**, 74 core
renderer checks, 42 advanced renderer/import checks and two JavaScript
serialization regressions. PHPStan level 5, syntax, generated assets, heading/UI
consistency and whitespace checks passed. The notification assertions verify
real outcomes and separation by audience, user and permission. A pre-existing
fixture assumption about empty admin activity was corrected to assert ownership
by operation name; the production ownership query did not need changing.
Evidence is in `/private/tmp/designer-remaining-integration.log`,
`/private/tmp/designer-remaining-phpstan.log`,
`/private/tmp/message-monkey-designer-qualified.png` and
`/private/tmp/message-monkey-notifications-light.png`.

The additive migration `20261016_expand_marketing_designer.sql` adds independent
audience-scoped revision/comment/library tables and application grants. Fresh
encrypted database and file backups were restore-verified before the local
migration: `var/backups/audience-designer-remaining/20261016T220255Z-93058dc8/receipt.json`.
The sibling `local-migration-receipt.json` records application of the migration.
Normal application-user reads succeeded for all new tables. Localhost serves
the current built CSS/JS. Shalom's **10,884 People, 21 tags, 0 segments, 0 campaigns
and 1 template** are unchanged; no qualification content was inserted there.
Production was not deployed.

### Stage 2 campaign work identified for the completion pass

The following gaps were recorded before Stage 2d and are now addressed below:
Campaign Manager plans; A/B drafts; date presets and calendar holiday
overlays; advanced recipient inclusion/exclusion and personalized To; subject
merge-tag pickers; tracking settings; send-time/resend configuration; public
browser versions/archives and social-card metadata; team draft sharing; and
final reference UI/UX sign-off. Actual scheduling/sending, sender verification,
public unsubscribe/preferences resolution and delivery analytics require Stage 3.
Campaign landing-page creation remains in its later retained scope.


## Stage 2d — campaign, content and final native qualification (2026-10-16)

**Stage 2’s retained native draft/design/configuration scope is complete and
qualified locally. Full Mailchimp pixel parity and actual email-client rendering
are not certified.** Current live reference review retained the four-column
campaign list (status/audience within Details), five-section checklist and
Settings & Tracking drawer. Content navigation, filters, selection, grid/list,
folders and pagination were compared read-only against Mailchimp Content Studio.
No Mailchimp campaign, template, asset or setting was created, changed or sent.

Campaign completion includes date presets/holiday calendar; regular/plain-text/
A/B creation; up to three A/B variations; separate
content variations and histories; advanced recipient inclusion/exclusion and
personalized To; subject merge tags; five tracking flags; send-time/trigger/
optimization/Timewarp and resend preferences; social-card metadata; authenticated
team review links/comments; and confirmed, revocable sent-only browser snapshots
with a paginated public archive. Drafts cannot be published through that route.
Actual dispatch, test sends, winner selection, automatic resends and execution of
delivery preferences remain Stage 3; optimization analytics remains Stage 7.
Recipient-specific public unsubscribe/preferences endpoints remain Stage 3.

Campaign Manager includes versioned plan goals/dates/notes and task/email/event
touchpoints; calendar/month/week/agenda/list, search/sort/page controls and scoped
date windows. Explicitly applying a plan’s tracking preferences updates only an
associated editable email draft when that touchpoint is saved. Future plan edits
do not silently rewrite existing emails.

Standalone Content/My files includes private PNG/JPEG/WebP uploads, filename/alt/
link/dimensions/size metadata, search and folder/type filters, name/date/size sort,
grid/list, 10/25/50/100 paging, page selection, folder CRUD, image/section rename,
bulk move/Trash/restore and usage counts. Native numeric crop/resize/rotate works
on a local copy. Replacing, moving or trashing an asset preserves copies already
inserted into drafts/templates. Public links require an explicit switch and
rights confirmation; opaque keys are canonical-account scoped, sent with no-store
headers, revoked immediately when disabled/trashed and remain private on restore.
No external connected-media/product content was added.

Brand Kit supports default/secondary logo uploads or owned saved-image selection,
colors, email fallback fonts, licensed WOFF/WOFF2/TTF embedding, paragraph size and
button radius. Saved settings drive the preview. Explicit application to a saved
visual/code template records a revision, preserves unrelated layout and explicit
block styles, and leaves campaign snapshots independent. Visual templates can
insert a default logo; custom HTML logos are inserted manually in its code editor.
Embedded fonts are validated native data URLs; unsupported clients retain the
chosen fallback. Protected custom-code rendering still strips arbitrary source
CSS/resources, then inserts only the validated native brand font.

Recent corrections: no user-facing/backend VIP features or conditions remain
(the dormant schema column is retained); Add Connection tags flow without a
nested clipped region; Language and Time zone are native selects with Not set and
saved-value persistence; Edit settings has a 24px gap; the desktop header search
centers across its row and mobile uses a second row. Sorting custom Date of Entry
uses typed dates with blanks last in both directions. More than six row tags show
a clickable [...] profile link. Column pointer dragging and focus clipping were
qualified earlier in this pass. Export-history single/selected/all deletion
checks ownership, administrator role, active operations and atomicity.

Qualification evidence:

- **320 authenticated disposable HTTP checks**, including 80 campaign-completion
  and 46 Content/Brand checks; scoped ownership/roles/CSRF, stale writes, malformed
  recipients, independent variations, plan/touchpoint persistence, public-link
  lifecycle, font licensing, multipart upload, Trash restore and settings
  preservation are covered.
- **74 core design, 42 advanced design and 26 completion validation checks**,
  plus two JavaScript serialization regressions. PHPStan level 5, PHP/JS syntax,
  heading/UI consistency and whitespace checks passed.
- **40 rendered desktop/mobile checks**: 1280x720 and 390x844, light/dark, campaign
  checklist/review/manager/plan/list/agenda, Content grid/list, Brand Kit and Add
  Connection. No page-level horizontal overflow or PHP warnings. Browser actions
  verified recipient dropdown select/reset, delivery-mode fields, experiment
  add/remove/limits, tracking drawer, touchpoint editor, image selection/crop/resize
  and Brand upload controls. Settings selects retain saved values and the
  header search is centered against the visible viewport. A further 12 checks
  requalified final Brand Kit, profile settings and embedded-font designer layouts.
  The designer loads the native font under the production preview CSP with no
  browser errors.

The HTTP suite uses isolated containers and databases; browser previews contain
fictional records under the protected-preview CSP. This is functional/native
qualification, not an inbox/client-rendering certification. The live newer
Mailchimp builder was not populated to enter its complete workflow; original
vendor font/artwork and its entire template catalog remain approximate. These
limits keep matrix rows partial/approximate rather than falsely asserting full
parity. Later-stage navigation and integrations remain outside this qualification.

Local campaign tables were migrated only after restore-verified encrypted
backups: `var/backups/audience-campaign-completion/20261016T225645Z-b4a0c8d9/receipt.json`
and `var/backups/audience-campaign-completion/local-migration-receipt.json`.
Content/Brand uses existing audience settings and library storage and adds no DDL.
Localhost serves the rebuilt assets; no production release or external sending
was performed. Real account content was not used as disposable test data. A final read-only check
confirmed **10,884 Connections, 21 tags, 0 campaigns and 1 template**, with the same
status totals (7,203 subscribed; 1,854 unsubscribed; 1,825 cleaned; 2 non-subscribed)
and 7,242 distinct Date Added / 7,541 distinct Last Changed timestamps.

Logs: `/private/tmp/campaign-completion-integration.log`,
`/private/tmp/campaign-completion-phpstan.log`,
`/private/tmp/campaign-completion-final-layouts.json`. Screenshots:
`/private/tmp/message-monkey-centered-search.png`,
`/private/tmp/message-monkey-content-editor.png`,
`/private/tmp/message-monkey-brand-kit.png`.


## Stage 3a — email delivery setup and readability (2026-10-16)

**Delivery setup is implemented and qualified locally; Stage 3 dispatch is not
complete.** The user explicitly selected MOED’s existing SMTP relay. This pass
adds Domains/Email delivery under Campaigns, including custom-domain address
verification, provider DNS requirements, authentication checks and relay readiness.
The Mailchimp Domains page and its verification modal were inspected read-only,
along with the current official [verification](https://mailchimp.com/help/verify-a-domain/)
and [authentication](https://mailchimp.com/help/set-up-email-domain-authentication/)
guides. No reference-account record was modified. Billing, website domains and
connected-provider login screens are outside this native setup.

### Behavior and boundaries

- Setup changes require an unrestricted administrator, CSRF and canonical account
  scope. Editors can read setup status; reviewers cannot open the setup workspace.
  The shared demo-session/account-security policies govern configuration access.
- Verification is a fixed, transactional setup message; it does not subscribe or
  alter Connections. Codes are single-use, expire after 24 hours, permit five
  guesses and are replaced on resend. Requests have a one-minute per-address
  cooldown and a 20-per-account hourly bound. Pending/unaccepted codes do not
  verify an address. Payloads are sealed with the application key and cleared
  after acceptance, consumption, expiry or failure.
- The existing mail worker claims bounded jobs with row locks/leases. Failures
  remain failed; ambiguous SMTP acceptance becomes uncertain and is never replayed
  automatically. Explicit resend invalidates the prior code. SMTP accepted means
  relay acceptance, not proven arrival. The development preview is labelled and
  records log provenance; it cannot satisfy live sender or domain readiness.
- Connection checks authenticate with the existing SMTP relay and quit without
  sending a message. The receipt exposes From address, transport, TLS flag, time
  and readiness, without host credentials/passwords. The web app never receives
  SMTP credentials. The worker’s new grants cover only sending-domain/address
  reads and setup-job reads/updates, not marketing Connections. Existing account
  and reimbursement mail behavior remains separate.
- DNS requirements are the existing relay provider’s exact DKIM TXT/CNAME values.
  The admin enters up to three selectors; a successful check requires exact
  matching records, a usable RSA/Ed25519 public key and one DMARC policy. SPF is
  diagnostic; the app does not invent provider include values or modify DNS.
  Changes/disable/remove invalidate authentication immediately; stale edits and
  concurrent DNS checks are rejected. Live readiness requires an SMTP-verified
  address, recent DNS check, recent successful relay check, the configured
  authorized From address and organization mailing address. This adapter currently
  authorizes the relay’s configured From address; additional aliases are not
  silently assumed to be supported.
- The native badge says **DNS verified** rather than claiming that observing DNS
  proves message signing or inbox placement. Verification currently uses code
  entry; Mailchimp’s email magic-link variant is not implemented. Custom domains
  are required by this relay-backed setup rather than reproducing Mailchimp’s
  public-domain From-address rewriting.

Remaining Stage 3 work includes reviewed send confirmation, full recipient
snapshots/queues, schedule/pause/cancel, dispatch-time suppression, public
unsubscribe/preferences and opt-in confirmation, real test sends, batch retry
semantics, delivery/bounce/complaint events and truthful delivery reports. No
existing Send or Send Test button was enabled prematurely. Sending-volume/rate
limits and any relay-authorized aliases must be enforced when campaign dispatch
is implemented. The setup adapter cannot accept arbitrary campaign content.

### Qualification and local activation

The disposable suite passed **357 authenticated HTTP checks**, including 37 new
setup checks; a separate dedicated-mail-role smoke check processed a fake probe
and confirmed that the role cannot read marketing Connections. Cases include
permission/CSRF/isolation, encrypted payloads, single-use/expired/guessed codes,
cooldowns, stale DNS edits, CNAME/key/DMARC results, development isolation, failed/
uncertain/cancelled jobs, relay From authorization and preservation of tags and
subscription status. All fixture sends used an injected disposable adapter; none
were transmitted outside the test environment.

**19 pure setup checks**, the 26 completion/74 core/42 advanced design checks,
two JavaScript serialization regressions and existing fake-relay SMTP protocol
checks passed. PHPStan level 5, syntax, heading/UI consistency and whitespace
checks passed. Browser qualification covered **64 layouts** at 1280x720 and
390x844 in both themes: Contacts/profile/Add Connection, campaign/manager/plan/list,
Content, Brand, templates/designer and Domains/empty/relay/code/authentication.
There were no page-level horizontal overflows, PHP warnings or open dialogs
outside the viewport. Verification dialogs and DNS controls were exercised with
fictional records under the protected-preview CSP.

The user also requested slightly larger UI text. Workspace body/navigation/table
text is now **16px** (previously 14px); supporting labels and headings increase
proportionally, and the header search remains centered. Email document typography
is still determined by the saved design; the UI change does not rewrite emails.

Fresh encrypted database and file backups were restore-verified before the
additive local migration:
`var/backups/audience-delivery-setup/20261017T002041Z-daf2bdd2/receipt.json`.
`var/backups/audience-delivery-setup/local-migration-receipt.json` records the
applied checksum and migration state. No container/network configuration changed;
the existing source-mounted worker reloaded the shared setup implementation.

A single real **connection-only** probe completed successfully over TLS using the
existing relay’s authorized From address `moed@beneliath.com`. It sent no email.
No real sender/domain was automatically marked verified, and no campaign or
verification email was sent to a real recipient during this qualification.
Local source/assets are live; production was not deployed. The final read-only
check retained 10,884 Connections, 21 tags and zero campaigns, with status totals
7,203 subscribed / 1,854 unsubscribed / 1,825 cleaned / 2 non-subscribed.
The real sender/domain tables remain empty; only the accepted connection-only
probe was created. Web, database and mail worker are healthy. The 80px collapsed
rail still fits, and browser error logs were empty.

Evidence: `/private/tmp/delivery-setup-integration.log`,
`/private/tmp/delivery-setup-phpstan.log`, `/private/tmp/delivery-setup-layouts.json`,
`/private/tmp/message-monkey-readable-font.png` and
`/private/tmp/message-monkey-delivery-verification-dialog.png`.

## Stage 3b — campaign sending and scheduling (2026-10-16)

**Regular/plain-text campaign sending and specific-time scheduling are implemented
and qualified locally. Full Stage 3 and real inbox delivery remain unqualified.**

The checklist, Send time radio choices and Save/Cancel interaction were inspected
read-only in an existing Mailchimp draft. No Mailchimp values were saved and no
Send was clicked. The final confirmation follows the documented
[Send a Regular Email](https://mailchimp.com/help/send-regular-email/) and
[Schedule or Pause a Regular Email](https://mailchimp.com/help/schedule-or-pause-a-regular-email-campaign/)
flows. Typography, MOED branding and native delivery receipts remain adaptations;
exact vendor artwork/pixel parity is not certified.

Implemented:

- Review To, From, Subject, Send time and Content, then a keyboard-accessible
  “Ready to send?” or “Ready to schedule?” dialog. A 15-minute encrypted proof
  binds the actor, campaign version, sender/footer identity, merge definitions
  and exact eligible recipient identities. Changed/stale reviews are rejected;
  repeated confirmation returns the same durable operation.
- Immediate sending or a specific future local time with an IANA zone and stored
  UTC due time. Reject past/too-near dates, dates beyond a year, nonexistent DST
  times and repeated fall-back hours. Campaign list/calendar use scheduled dates.
- Immutable encrypted campaign and per-recipient personalization snapshots,
  stable SMTP Message-IDs, unique recipient records and bounded worker batches.
  Account/operational queues run before each five-recipient marketing batch.
  Credentials stay in the existing mail worker. The worker has only the contact
  identity/consent columns needed for suppression; it cannot read names, custom
  fields or notes from Contacts. The encrypted queued context supplies rendering.
- Pause/resume/cancel with campaign advisory locks and optimistic state versions.
  Progress updates do not invalidate Pause. Cancel before any attempt returns
  the email to Draft; edit its time/content and review a new schedule. Once any
  attempt starts, cancellation applies to remaining messages and does not make
  the old campaign editable. Replication creates a separate draft.
- Current consent/status, archive/delete, changed email, deletion-tombstone and
  public opt-out checks immediately before each envelope. Hash-based public
  opt-outs survive re-import/operator status changes. Revoked sender/domain
  readiness pauses a job; stale domain DNS is checked again. The shared Test
  Account policy also prevents the worker from claiming campaign recipients.
- SMTP acceptance, waiting, suppressed, failed, uncertain, cancelled and
  development-only receipts with progress and recent connection activity.
  Only proven pre-DATA failures retry automatically, at most three attempts.
  Expired started claims become uncertain; no potentially accepted envelope is
  replayed automatically. An in-flight message may finish before Pause/Cancel
  returns. SMTP acceptance is not inbox delivery or engagement measurement.
- Mandatory identity/address/preferences/unsubscribe content for HTML and plain
  text, encoded sender/To names and one-click list headers. Opaque per-recipient
  subscription capabilities keep GET safe and POST idempotent. The
  [RFC 8058](https://www.rfc-editor.org/rfc/rfc8058.html) one-click POST is accepted;
  DKIM coverage of these headers still requires real message qualification.
  Cleaned addresses, tags and unrelated/new addresses are not changed by opt-out.
  The preference page currently offers unsubscribe only, with no resubscribe
  bypass. A private browser view and immutable hosted PNG/JPEG/WebP snapshots
  resolve outgoing email links/images independently of login sessions.

Connections now defaults, in order, to **Email Address, First Name, Last Name,
Tags, Status, Source, Date Added**, with Date Added descending. Last
Changed remains available in Columns. The former default visibility set is
upgraded even when its order was rearranged; other customized visibility is kept.
The server's first render and saved-preference JavaScript agree on the defaults.

Qualification:

- **393 authenticated HTTP/storage checks**, including **36 dispatch checks**:
  CSRF/role controls, explicit/stale/repeated confirmation, immutable content,
  due-time execution, pause/resume/cancel, opt-out surviving a subsequent status
  edit, archived/changed-address suppression, pre-DATA retries, uncertain
  acceptance, restart recovery, revoked sender, hosted image bytes, public
  browser/subscription endpoints and audience isolation.
- Separate execution under **dnrmaildispatch** processed a fictional campaign
  with a development adapter; broader contact-column reads were denied.
  **22 pure dispatch checks**, a restricted-account worker guard, shared SMTP
  fake-relay protocol tests (including reviewed From/personalized To/list headers),
  26 campaign completion checks, 19 setup checks, 74 design checks, 42 advanced
  design checks and two JS model checks passed.
- PHPStan level 5, PHP/JS syntax, heading/UI consistency and whitespace checks
  passed. **36 layout observations** cover nine screens in both themes at
  1280×720 and 390×844, with no outer overflow or PHP warnings. Four desktop
  confirmation variants and a mobile Schedule dialog fit; Escape closes the
  dialog and returns focus. The later repeated viewport override was ignored by
  the browser, so those duplicate desktop measurements are excluded from mobile
  evidence. Earlier observations explicitly reported innerWidth=390.
- A fresh encrypted, restore-verified backup preceded the local structural
  migration. Receipt:
  `var/backups/audience-campaign-dispatch/20261017T004906Z-1ab33518/receipt.json`;
  SHA-256 `bef4e4f2f639d91d8b131ea47a10eeaae7ab05de0ba7242894dd4c2122c1d7dc`.
  `var/backups/audience-campaign-dispatch/local-migration-receipt.json` records
  the applied migration checksum. No Docker network/configuration changed.

Local web/database/mail worker are healthy and served CSS/JS match rebuilt
sources. Real data remains **10,884 Connections**: **7,203 subscribed / 1,854
unsubscribed / 1,825 cleaned / 2 non-subscribed**, with **zero campaigns and zero
campaign deliveries**. Real sending-address/domain tables remain empty. No real
campaign, verification or test email was sent, and no production deployment was
performed. The fresh in-app-browser session reached the local sign-in page;
UI evidence uses explicitly fictional rendered fixtures, while authenticated
functional tests use the isolated disposable environment.

Still outstanding: administrator verification of the real sender/domain,
controlled real test-email/client/inbox and provider-volume qualification,
bounce/complaint event ingestion, full profile/group preferences,
confirmation/resubscription, experiments/winner execution, automatic resends,
recipient-local time, optimization, engagement tracking and additional native
transactional workflows. Dispatch explicitly rejects experimental/resend/
triggered/optimized/Timewarp schedules. Review states that opens/clicks are not
measured. These gaps are not silently reported as working.

Evidence: `/private/tmp/campaign-dispatch-integration.log`,
`/private/tmp/campaign-dispatch-phpstan.log`,
`/private/tmp/campaign-dispatch-layouts.json`,
`/private/tmp/campaign-dispatch-local-migration.log` and
`/private/tmp/message-monkey-send-confirmation.png` (fictional preview).

## Stage 3c — paid Proton delivery lifecycle qualification (2026-10-16)

The native Stage 3 delivery features are implemented and qualified locally.
**Stage 3 operational qualification remains open**: no real sender/domain has
been activated, no real test/campaign was sent, and inbox signing/rendering,
provider capacity and an authenticated bounce/complaint producer remain external
activation work. Exact Mailchimp pixel and email-client parity are not certified.

### Paid account and shared capacity

The user confirmed a paid Proton account and approval to use the relay for MOED
marketing. The [Proton sending-limits page](https://proton.me/support/email-sending-limits)
does not publish a fixed paid hourly/daily allocation. Free-plan numbers are not
used as paid defaults. Approval is recorded locally; hourly and daily allocations
remain unset, so live campaigns/tests cannot start until configured.

Email delivery now has Domains, Email delivery, Sending limits, Activity and
Delivery events tabs. Administrators set rolling-hour/day recipient allocations,
operational reserves and an interval. SMTP messages remain individually addressed;
no marketing recipient addresses are exposed through CC/BCC. The shared limiter
covers native account, notification, engagement and reimbursement queues as well
as marketing, tests and subscription/sender confirmation. Activity distinguishes
reserved attempts, SMTP acceptance and authenticated delivery events. It never
claims to know Proton’s remaining quota. Other deployments or mail clients using
the same Proton account must be included when allocating MOED capacity.

Known sender quota/ban responses pause the shared relay. Quota deferral preserves
encrypted payloads and retry allowance. A recipient mailbox quota does not pause
the entire account. Ambiguous DATA acceptance is never replayed automatically.
Operational triggers/templates and receipts remain in MOED’s existing native
source workflows; the administrator’s Activity summary maps them without adding
an arbitrary-recipient marketing bypass or another transactional service.

### Recipient and campaign workflows

- Campaign checklist and visual/template designer support test sends to up to
  ten distinct addresses, with an optional note, sample merge data, inert test
  subscription links, version checks, idempotent requests, a per-editor hourly
  bound and durable relay receipts. Tests share marketing capacity and recheck
  sender/domain readiness when dispatched.
- Private preference links expose only public fields and visible interest groups;
  names/time zone can be changed with CSRF and optimistic versions. Email,
  subscription status, private fields and tags cannot be changed through that form.
  Resubscription requires an expiring, single-use confirmation actually accepted
  by SMTP; development receipts and GET requests cannot grant consent. Replacement,
  expiry, address changes, archive/cleaned state and deletion tombstones are checked.
- Signed native click links and open pixels retain event provenance, exclude
  recognized bots in reported observations and avoid tracking preview/test messages.
  Reports show unique-message open/click/unsubscribe observations, relay acceptance
  and authenticated delivery/bounce/complaint events separately. Privacy proxies
  and image blocking remain measurement limitations.
- The native HMAC event endpoint accepts timestamped, bounded, deduplicated events
  tied to an actual started SMTP message. Hard bounces clean the matching address;
  complaints suppress marketing. Older provider events cannot overwrite newer
  confirmed consent. Creating a key does not connect a provider feed: Proton SMTP
  alone is not represented as delivering such a feed.
- Recipient-local scheduling stores individual UTC due times, uses the chosen
  zone for unknown recipient zones, requires 24 hours’ notice, advances DST gaps
  and uses one occurrence of repeated local hours. Ordinary specific schedules
  still reject ambiguous/missing times. Provider allocations can delay actual sends.
- Send-time optimization uses observed non-bot audience click hours only after
  at least 100 distinct recipients have recent evidence; otherwise it explicitly
  uses the selected time. This native heuristic is not Mailchimp’s proprietary model.
- A/B dispatch freezes combinations and deterministic, disjoint test
  and hold-out cohorts. Independent HTML content variants render separately.
  Manual or observed open/click-rate winners release only the confirmed hold-out;
  automatic ties use the first combination. Reports separate test-phase results.
- Automatic resends consider only original accepted recipients, recorded engagement
  or frozen tag/segment membership, and current dispatch eligibility. Each resend
  has its own message/token/receipt and can be created only once. A pending resend
  can be cancelled independently. Tag-triggered automation execution remains Stage 5.

### Evidence and local installation

- Disposable authenticated HTTP/storage suite: **436 checks**, including **43 new
  delivery-completion checks**, plus the Stage 1b storage suite. Dedicated worker
  grants were tested for campaign execution, shared allocation, operational reserve,
  interval, provider pause and preservation of an operational payload during deferral.
  The mail role cannot edit sending budgets or read contact personalization fields.
- Pure checks passed: 15 delivery runtime, 22 dispatch, 19 setup, 26 campaign
  completion, 74 design, 42 advanced designer, restricted-account campaign/service
  worker guards, two JS model tests, heading/UI consistency and SMTP loopback
  protocol scenarios including greeting/envelope/DATA quota responses. No external
  SMTP connection was used by these tests.
- PHPStan level 5 and PHP/JS syntax passed. Sixteen explicit desktop/mobile layout
  observations (1280×800 and 390×844, both themes) cover limits, Activity, event setup
  and reports without outer overflow or PHP warnings. The designer’s test dialog
  fits both sizes and receives focus; native close/cancel works. These screenshots
  use **fictional preview fixtures**, not real account records.
- A fresh encrypted backup was restore-verified before the local migration:
  `var/backups/audience-delivery-completion/20261017T012721Z-adb8c4bf/receipt.json`,
  SHA-256 `ac620d9291f78585ce11f0b8f6dc57dc48237414317314d7ea3a8fe55d226af3`.
  `var/backups/audience-delivery-completion/local-migration-receipt.json` records
  migration `20261016_complete_marketing_delivery.sql` and matching before/after
  hashes of Connection dates, statuses and tag memberships. No Docker networking
  configuration or production deployment changed.
- Local web, database and mail-dispatch are healthy; served rebuilt assets match
  the source hashes. Real data remains **10,884 Connections**, **7,203 subscribed /
  1,854 unsubscribed / 1,825 cleaned / 2 non-subscribed**, **21 tags**, and **zero
  campaigns, deliveries or service test/confirmation messages**. Real sender/domain
  setup remains untouched.

Logs: `/private/tmp/delivery-completion-integration-qualified.log`,
`/private/tmp/delivery-completion-phpstan-qualified.log`,
`/private/tmp/delivery-completion-smtp-final.log`,
`/private/tmp/message-monkey-completion-migrate.log`.

## Subsequent provider decision — SES API, marketing only

The user subsequently selected Amazon SES Essentials through its API, with each
MOED account supplying its own SES configuration and credentials. Only
MessageMonkey marketing is in scope; operational MOED SMTP remains unchanged.
The Stage 3a–3c SMTP results above remain historical qualification evidence.
SES credentials, API adapter, regional readiness/quota checks and verified SNS
event ingestion are now implemented and qualified locally. Native campaign
analytics, preferences and suppression remain in MOED; operational SMTP is kept.
See the [current implementation and activation gates](audience-mailchimp-audit.md#provider-decision--amazon-ses-essentials-api-2026-10-16).

- 434 authenticated HTTP/storage checks, 37 SES validation/signature/SDK checks,
  mail-role API/quota/error/no-replay checks, maintenance-role credential rotation,
  existing dispatch/runtime/demo guards and PHPStan level 5 pass. No external mail.
- Fictional SES setup layouts fit desktop/mobile and both themes. The template
  test dialog lists the account’s SES sender rather than retired SMTP identities.
- Fresh backup: `var/backups/audience-ses/20261017T015548Z-2cb6e50b/receipt.json`,
  SHA-256 `a9fb11966cbb4a35e3153ea763b9d7b6bf2be5c32b92fb6fa26ec772144b341a`.
  Local migration receipt records `20261016_add_marketing_ses.sql`, checksum
  `edd9c7c1ea0b63562c1f278c92e0719a2ba288d6c8040b7cce1a05ff95c904d9` and
  unchanged Connection date/status/tag fingerprint
  `7eb23861681968ca77b329c3349c37e8bdc90ce47efe431c68929db6b0a52493`.
- Qualification logs: `/private/tmp/message-monkey-ses-integration-qualified.log`,
  `/private/tmp/message-monkey-ses-phpstan.log` and
  `/private/tmp/message-monkey-ses-migrate.log`.
- The initial local checkpoint did not activate live AWS resources. The later
  Stage 3d checkpoint below supersedes that activation status; simulator events
  and controlled inbox checks remain open.

## Stage 3d — non-live closeout and SES activation (2026-10-17)

**Non-live Stage 3 implementation and qualification are complete. Overall Stage 3
is still open for the controlled live acceptance below.** This checkpoint uses
`codex/ses-endpoint-layout`, based on release 2.5.23 at
`7255e8d40aee53d5411da7f1a32b7ce66def7f35`. The latest UI changes are local;
they have not been deployed to s1 and require an explicit production release
request. No external email was sent during this closeout.

### Pending UI fixes completed

- The read-only HTTPS endpoint fills the available value column and aligns with
  SNS topic, Subscription and Last verified event. Its reachability help is
  associated with the input. Long topic/subscription values wrap within the card.
- Both credential inputs show `•••••••• (saved)` only when saved credential
  metadata exists. They remain empty password inputs: neither the access-key ID
  nor the secret is read back into HTML. Leaving the form unchanged preserves
  the saved credentials; disconnect removes both saved indicators. The shared
  instructions are associated with both fields for assistive technology.
- The domain DNS guidance has 24px above and below it, separating the identity
  details, instructions and action. The source stylesheet, minified bundle and
  asset manifest agree.
- Sixteen explicit observations cover unconfigured setup, saved/sandbox setup,
  authenticated domain and confirmed-event screens at 1280×800 and 390×844 in
  light and dark themes. There is no outer horizontal overflow or PHP warning.
  The endpoint is 727px wide on desktop and 313px on mobile, aligned with all
  three values; DNS margins are 24px in both themes/sizes. The responsive setup
  stacks fields without clipping their controls.
- Screenshots use fictional `community.example` data rendered from the real
  templates with `scripts/generate_audience_preview.php`; explicit fixture themes
  do not modify the browser’s saved preferences. These are visual evidence,
  separate from the authenticated integration checks and the real local app.
  This refines the existing Mailchimp-style shell, cards, labels and spacing;
  SES setup remains a native adaptation, with no claim of exact pixel parity.

### Refreshed qualification and local installation

- The disposable suite passed **442 authenticated HTTP checks**, including
  **28 SES setup** and **18 signed event/storage checks**. Four added checks cover
  unconfigured/saved/disconnected credential indicators and accessible help; the
  unchanged-form test submits the actual rendered empty values. The suite also
  exercises campaign completion (80), Content/Brand (46), delivery completion
  (58), campaign dispatch (94), permissions, CSRF, imports/exports and isolation.
  These are included counts, not additional checks to add to 442.
- Actual restricted worker roles passed durable SNS inbox retry, real RSA
  signature verification, signed confirmation, forged-event rejection, account
  and recipient binding, receipt ordering, bounce/complaint suppression, SES
  quota/throttle/rejection/ambiguous outcome/no-replay, shared budgets/reserves,
  and encrypted credential rotation/retirement. Stage 1b storage also passed.
  All sends used disposable adapters; these are not AWS delivery receipts.
- Pure suites passed: **40 SES validation/signature/SDK**, **22 campaign
  dispatch**, **15 delivery runtime**, **19 delivery setup**, **26 campaign
  completion**, the restricted-account campaign guard, and **two designer JS
  model tests**. Full routes PHPStan level 5, changed PHP syntax, generated asset
  hashes, AI Coach bundle hashes and whitespace checks passed.
- The local main app was missing the already-qualified SNS inbox migration.
  Before applying `20261017_queue_marketing_sns_events.sql`, writers were paused
  and a fresh encrypted backup was restored and verified. The migration receipt
  records **120 protected application tables unchanged**, including all 10,884
  Connections and 21 tags; dates, subscription status and tag memberships were
  preserved. Local web, downloads and mail-dispatch are healthy. No Docker
  networking or production data changed.
- Backup receipt:
  `var/backups/audience-stage3-closeout/local-migration-receipt.json`.
  Encrypted backup SHA-256:
  `5241e996e2acc597beeaf794190523925db721ba71c9a5c4f4e1b381b67d4a67`.
  The live local URL serves the sign-in page in the browser’s current session;
  authenticated behavior was verified by the disposable HTTP suite, not by
  claiming a signed-in local visual session.

Evidence for this run:
`/private/tmp/message-monkey-stage3-offline-integration.log`,
`/private/tmp/message-monkey-stage3-offline-routes.log`,
`/private/tmp/message-monkey-stage3-offline-js.log`,
`/private/tmp/message-monkey-stage3-local-closeout.log`, and
`/private/tmp/message-monkey-stage3-qualified-preview/layout-results.json` with
the sixteen adjacent screenshots. Temporary logs/screenshots are local evidence,
not release artifacts or permanent backups.

### Production activation and remaining acceptance

The shared s1 implementation was deployed through
[2.5.22 / PR 234](https://github.com/beneliath/DNR/pull/234) and
[2.5.23 / PR 235](https://github.com/beneliath/DNR/pull/235). The primary account has
scoped API credentials, verified `shalominmessiah.com`, DKIM success, DMARC,
`bounce.shalominmessiah.com`, the `message-monkey` configuration set and a confirmed
`message-monkey-events` subscription in Ohio (`us-east-2`). Its signed confirmation
was processed by the mail worker. This proves subscription confirmation, not
campaign delivery, bounce or complaint receipt processing from a real SES send.
Each other MOED account still supplies and activates its own SES configuration.

AWS Support case `179223179900041` requested more information after the initial
production-access request. The user-approved reply was posted, describing about
20,000 newsletter emails monthly, affirmative opt-in and one-click unsubscribe.
The existing hourly monitor remains responsible for notifying on approval or
new actionable information. The initial API `DENIED` status must not be presented
as a final refusal while the support case requests clarification.

Once approval is observed, use this bounded acceptance sequence, with a separate
explicit authorization for the test recipients and sends:

1. Refresh the primary account’s readiness and verify production access, sending
   status, exact identity/configuration-set/topic and actual regional quotas.
   Keep the app’s production-access gate enabled; do not replace it with an
   assumed plan allowance or bypass it to send sandbox mail.
2. Send one small campaign only to explicitly approved, controlled inboxes.
   Inspect actual From/Reply-To, unsubscribe headers, footer, personalization,
   MIME/rendering and DKIM/SPF/DMARC alignment. Record SES MessageId and the native
   recipient receipt. Never use the imported audience as test volume.
3. Use SES simulator addresses for delivery, hard bounce and complaint paths.
   Reconcile signed SNS receipts with the exact campaign/recipient, suppression,
   replay handling and report totals; verify that unrelated Connections retain
   their tags and subscription status.
4. Exercise a small scheduled campaign, pause/resume/cancel and observed provider
   pacing within the approved quota. Confirm no duplicate send after worker
   restart and that ambiguous responses remain unreplayed. Quota failure paths
   already have isolated coverage; do not manufacture load against real users.
5. Exercise observed open/click attribution, one-click unsubscribe and an approved
   test-address confirmation/resubscription. Reconcile native observations with
   SES delivery totals, acknowledging privacy/bot limitations. Record actual
   inbox/client observations and the remaining client differences.

Only after those results and the requested production release are recorded should
Stage 3 be marked fully qualified. SE01–SE15 remain `partial`, `approximate` and
`parity_qualified=no`; they do not represent a completion percentage. Public forms,
automation execution and later-stage product areas are outside this closeout.

## Stage 4 — native collection and local qualification, October 17, 2026

Current branch: `codex/mailchimp-cloning-continued`, based on release 2.5.25.
This work is running at localhost through the existing development mounts and
Stage 4 local images. It has not been released to s1. Native non-live behavior is
qualified; the stage is not certified as complete Mailchimp feature/pixel parity.
FO01–FO12, LP01–LP08 and SU01–SU07 remain `partial`, `approximate` and
`parity_qualified=no`, with concrete remaining differences in the feature matrix.

### Reference and interface

Read-only reference inspection covered Forms index/search/type/status/actions,
its three-choice creation dialog and settings, the current fullscreen hosted
signup editor, landing-page name/Continue-to-builder setup, six survey starter
categories, and an existing populated survey report with question tabs, charts,
filters, exports and response tagging. No reference record was created, saved,
published or sent. Continuing into a new landing-page builder would have created
reference data, so that workflow was not falsely recorded as inspected.

The local Forms page uses the inset table, compact filters with aligned 36px controls, two-column starter
cards in Popup/Basic subscribe/Embedded/Landing order, and the inspected chooser.
The fullscreen collection editor has the locally served reference vector rail,
property panels, canvas, desktop/mobile preview, Save draft, publish review,
Undo/Redo and an account-scoped Content Studio picker/upload. Icon provenance is
in `docs/audience-collection-icon-sources.json`. Reference artwork is represented
by native schematic previews. MOED branding, increased text readability, both
themes, roles and keyboard focus remain intentional adaptations.

Browser checks used the real authenticated local Forms, settings, landing-page
setup, Surveys and subscriber-preference routes without adding fabricated
records to the imported audience. Fictional renderings of the shared templates
covered populated editors, both themes, keyboard navigation, Undo/Redo and
responsive layout. A 390-pixel framed viewport had no horizontal page overflow;
its narrow preview expanded to its content. The desktop preview measured
743px high with equal root client/scroll heights and equal 721px client/scroll
widths. A fractional CSS pixel initially left a scrollbar despite rounded DOM
heights; sizing now rounds the actual root box upward. Width/font/image changes
also trigger fitting. Undo initialization now snapshots normalized control state
so restoration retains Redo and the visible field order. Outline selection and
creation-route sidebar highlights were checked after correction.

### Persisted collection behavior

- Six native kinds: hosted signup, embedded signup, popup, landing page, survey
  and subscriber preferences. Management enforces the existing account/audience
  context, editor/admin policy, CSRF and optimistic versions. Publish creates an
  immutable public revision; saved drafts stay private. Pause stops public
  collection. Replicate creates a private copy; remove is recoverable through
  Trash and restore returns to a private draft. Removed results remain available
  only to authorized account users.
- Typed audience fields, requiredness, groups, tags, translations, messages and
  brand styles use the existing audience data model. New forms use audience-wide
  defaults; already-published forms keep their saved revision until republished.
  The latest published hosted form supplies native unsubscribe/confirmation
  appearance while preserving those routes' security and one-click behavior.
- Signup requires explicit unchecked permission and records exact revision,
  text, additional choices, source and time. Existing profiles/interests are not
  overwritten anonymously. Existing opt-outs need confirmation; cleaned,
  deleted and archived records remain protected. Double opt-in reuses the
  native delivered/accepted, single-use challenge lifecycle. When mail readiness
  is unavailable it creates neither a pending subscription nor a mail job.
- Public collection uses account/resource/revision/recipient/visitor-bound
  sealed proofs, minimum dwell, expiry, honeypot, short-lived IP/email throttles
  and idempotent receipt nonces. GET requests do not subscribe or vote. Survey
  contact-info answers are neither permission nor verified identity. Responses
  are encrypted, included in application key rotation/retirement, and redacted
  when their associated Connection is deleted.
- Published preference centers require a native private recipient token and
  reuse `savePublicPreferences`; fields/groups outside the published form's
  scope and subscription opt-out state are preserved. Native preference links
  redirect to the latest published center.
- Manual embedded/popup scripts use exact HTTPS website-origin allowlists.
  Popup rules cover page prefixes, exclusions, device, delay/scroll/desktop exit,
  dismissal frequency, up to three stable variations and mini-quiz outcomes.
  Country rules accept only the existing trusted Cloudflare proxy chain and
  fail closed for unknown geography. No Cloudflare setting was changed.
  Installation timestamps are observed requests, not comprehensive install proof.
- Landing pages have three native starters, ordered typed sections, drag/drop
  plus keyboard move controls, safe image upload/library selection, metadata,
  favicon, opaque MOED-hosted URL, preview/publication and actual response/visit
  reports. Reports describe permission requests/responses rather than claiming
  confirmed subscriptions.
- Surveys have six native starter categories and typed radio, checkbox, 1–10
  range, text, email, structured contact information and content blocks. Results
  keep published revisions separate; counts and percentages use answered-question
  denominators. Date/revision/question/answer filters, response drilldown,
  verified-Connection-only tagging, formula-safe CSV, SVG chart and print outputs
  operate on actual data. Decryption is streamed in bounded batches.
- `SURVEY` and one-question `POLL` merge links are bound into campaign snapshots
  to the current audience's published survey and private recipient. GET only
  preselects an answer; POST records it. Duplicate verified recipients/browser
  visitors are controlled, and test-email links disable submissions.

### Qualification and local rollout

The disposable Audience suite passed **550 total HTTP checks**, including **77
new collection checks**, alongside Stage 1b storage, campaign completion,
Content/Brand, SES setup, signed-event, delivery and dispatch coverage. Tests
covered public draft isolation, permissions/CSRF, account isolation, publication,
pause/removal/restore, single/double opt-in, consent/opt-out preservation,
confirmation acceptance/replay, proof expiry/dwell/throttles/idempotence,
embedded origins, preference tokens, response attribution, survey/poll snapshots,
report filters/exports, Content Studio image wrappers and response redaction.
Restricted worker/provider controls and encryption-key rotation, account binding
and retirement passed. All email used disposable adapters; no external email was
sent. The helper removed only its labelled disposable resources.

Ingress HTTP fixtures passed the narrow collection-frame exception, protected
route frame denial, static-script CSP and visitor-cookie scoping. The global
Apache X-Frame-Options exception applies only to the exact public collection
endpoint. Ingress retains a `self`/HTTPS framing ceiling for that route, intersected
with the application's published origin allowlist; all other routes deny framing.
No broad security bypass or networking change was made.

Route PHPStan, PHP/JavaScript syntax and generated assets passed. The final
browser-only Undo/resize corrections and template/layout refinements were
followed by focused syntax, generated-asset, UI-label and actual-browser checks;
they do not represent additional HTTP checks beyond 550.

Local migration `20261017_add_marketing_collection.sql` ran only after local
writers were paused and a fresh encrypted recovery backup was restored and
verified. **All 122 pre-existing protected table counts/checksums were unchanged**;
persistent storage identities and every pre-existing Docker volume were retained.
Only local web/ingress were replaced, both became healthy, and writers resumed.
All 40 recorded network assignments were checked; no network configuration was
changed. Production was not touched. Release remains 2.5.25 for this unreleased
local development work.

Recovery receipt: `var/deployment/stage4-local-receipt.json`.
Backup: `var/backups/stage4-local/20261017T145713Z-30afc4f3/receipt.json`.
Encrypted backup SHA-256:
`15b2ae020e55a38c5f02508fb8c16f6558d0b41edfae76b5f03cc9c74afcb05a`.
Local evidence:
`/private/tmp/message-monkey-stage4-integration.log`,
`/private/tmp/message-monkey-stage4-phpstan.log`,
`/private/tmp/message-monkey-stage4-ingress-test.log`,
`/private/tmp/message-monkey-stage4-local-update.log` and
`/private/tmp/message-monkey-stage4-preview/`.
Temporary evidence is not a permanent release artifact.

### Remaining acceptance

Local completion now includes public-label/message translation, RTL language
support, safe hosted system-page HTML layouts, confirmed-subscription conversion
attribution, and the survey question/option/tagging workflow described below.
Exact pixel parity with every current popup/landing/survey editor is not certified.
Custom HTML retains the protected native form and messages; arbitrary scripts and
form destinations are excluded. Production host-site popup behavior and actual
Cloudflare country headers still need deployment-specific qualification, separate
from AWS approval.

Real confirmation/resubscription inbox delivery and survey-link email delivery
still require AWS production access and explicit approval of controlled test
recipients. Stage 3 live SES qualification remains open. A production release of
these changes requires the established separate release/deployment workflow;
this local rollout does not authorize publishing forms or sending to the imported
audience. The hourly AWS monitor remains active and quiet while unchanged.

### Brand Preview browser regression corrected

A local designer-asset rebuild had omitted `--bundle`, leaving an ES-module
import in a file loaded by `renderPageHead` as a classic script. The browser
rejected the entire asset, so Brand Preview remained an empty `about:blank`
frame. Rebuilding with the existing package build command
(`--bundle --minify --legal-comments=none`) and refreshing the asset manifest
restored the actual saved local logo, email content and footer. The existing
content-sized Brand Preview also gained a fractional-height safety margin and
explicit no-scroll iframe behavior. No brand settings or account data changed.

A new classic-browser-script parse regression test failed against the broken
asset and passed after rebuilding. All three workspace bundles and two existing
designer model tests pass. The new collection bundle is included in the normal
`build:js` command. Actual authenticated Brand Preview was inspected again;
its loaded frame grew to the rendered content and the inner document reported
equal client/scroll dimensions. Screenshot:
`/private/tmp/message-monkey-stage4-preview/local-brand-preview-restored.png`.

### Survey catalogue, terminology and scoped autocomplete follow-ups

The local survey starter gallery now follows the supplied Mailchimp reference:
two wide columns, blue category pills, 24px titles, 24px card spacing and a
divider beneath the section heading. Small screens use one column. The category
previously labelled “Marketing effectiveness” is now “Effectiveness”; shared
MessageMonkey interface copy uses subscription, audience email or campaign
wording as appropriate. Internal delivery lanes and historical consent evidence
retain their existing identifiers and values.

At the user's request, the built-in email catalogue omits Product spotlight,
Featured collection, Meet our services, Let’s talk and Account notice. Sell
products, Sell services and Transactional are absent from both category controls.
Both post-purchase survey starters are removed from the gallery and creation
chooser. Existing authored designs and published resources are preserved.

MessageMonkey disables generic browser form history, including its search
controls and dynamically created editor fields. List-search suggestions are
generated solely from authorized MessageMonkey records already loaded for the
current view: connections, campaigns, folders, templates, files, tags, segments,
plans and collection resources. They are escaped, deduplicated and capped at
100; an empty view supplies no suggestions. No suggestions are read from MOED
form history, generic query parameters or a shared cache. Global search retains
its existing account/user/audience-scoped MessageMonkey recent searches and
permission-scoped results. Explicit credential autocomplete hints are retained.

Focused validation covers the retained catalogue and usable survey starters,
suggestion isolation/escaping/bounds, email rendering, delivery/dispatch checks,
classic-script bundles, PHP syntax, generated assets and label punctuation.
Authenticated localhost checks confirm the four retained survey cards, the six
email category controls, the 15 retained email starters, and empty-survey versus
actual-template suggestions. These are local UI changes, not a production
deployment or a claim that all remaining Stage 4 acceptance gaps are closed.

### Local completion and template/photo follow-ups

The survey editor now separates Questions, Design and Details, following the
read-only inspection of an existing Mailchimp survey editor. Question options
have individual inputs, add/remove and Enter/Backspace controls. Dropdown,
requiredness, Other answers, duplicate/delete and answer tagging are supported.
Only a recipient identified by the private campaign capability can receive
answer-based tags; entering an email address in a survey is not proof of identity.
Details includes an optional UTC closing time enforced again on submission.

Translate it exposes public field labels and validation/system copy, with ISO
language codes and RTL layout. Hosted forms also have safe structural HTML source
for signup, thank-you, confirmation and unsubscribe pages. A native content slot,
or protected form and message slots, preserves consent, submission proof, native
destinations and disabled preview behavior. Active content is removed and authored
styles use nonce-authorized CSS. This is not unrestricted JavaScript editing.

Landing reports distinguish visits, submissions, distinct subscribers and confirmed
double opt-ins. A conversion is recorded only when subscription actually completes.
Double opt-in is bound to its challenge and exact resource/revision, with only
scoped attribution IDs retained in the consent event after the worker erases the
email payload. Pending requests, existing subscribers and replayed confirmations
do not inflate conversion counts. Date/revision and audience scope are retained.

The email catalogue uses cards capped at 300px. Title, category and action now
appear above the email and align across each row. Preview panes expand to their
full content and add white space beneath shorter emails. Cards have dark teal
perimeter borders and light gray headers, with white preview space. List view
retains compact thumbnails. The blank starter remains blank.

All fourteen designed starters now use different photos of people and places in
Israel, replacing the earlier generated artwork and general photos. Source,
creator, location, license and image hashes are recorded in
`src/assets/template-art/sources.json`. Photos are bundled locally and copied into
new designs, without runtime image requests to the source site. Per the user's
request, the same fourteen images were added privately to local Shalom in Messiah
Content → My files (IDs 1–14), using native validation and an atomic, idempotent
import; no production data was changed. All fourteen images were seen loading in
the authenticated local gallery.

Real photo payloads exposed MySQL filesort memory exhaustion in Content, campaign
and saved/recent template lists. Content and campaign lists now sort a bounded page
of IDs before loading large image documents. File-size ordering and saved templates
use lightweight PHP metadata comparisons. This preserves file/name/size ordering
without increasing database buffers. Qualification includes realistic photos under a
deliberately small sort buffer, as well as existing permissions and isolation.

Connections now displays three tags plus a profile overflow link when there are
more. Source is absent from the default columns but remains selectable; previous
default layouts upgrade while customized column order is retained. Pagination
labels, selectors, counts and navigation share a centered row on desktop, with a
responsive fallback. The actual authenticated localhost table and its three-tag
overflow were checked without exposing connection details in qualification receipts.

Final isolated qualification passed 92 collection checks and 568 total HTTP checks,
plus SNS durable receipt/confirmation/forgery/suppression, quota/worker privileges
and credential-rotation suites. No external email was sent. Routes PHPStan, PHP
syntax, 54 catalogue checks, 20 collection customization checks, 59 email-design
checks, 42 advanced-designer checks, five browser-bundle/model checks, asset-cache
identity, generated-asset integrity and label checks passed. Only the separately
labelled disposable test project's volumes/networks were cleaned up; existing
account databases and volumes were preserved.

Browser evidence includes the actual localhost card/gallery pages and fictional
editor fixtures for Questions/Design/Details, translation, Advanced, keyboard option
editing and undo. Static fixtures do not claim persistence or live public delivery.
The HTTP suite separately exercises draft preview and native submission paths.
Evidence is under `/private/tmp/message-monkey-stage4-completion-*.log`,
`/private/tmp/message-monkey-israel-template-cards.png` and
`/private/tmp/message-monkey-israel-my-files.png`. This remains unreleased local
development; Stage 3 live email qualification and deployment-specific checks remain
separate acceptance gates.


## Stage 5 — native automation flows, October 17, 2026

Based on shared release **2.6.0**, on `codex/mailchimp-cloning-continued2`.
This work is local development. Production rollout and real SES acceptance are
separate gates; authoring a flow never sends email.

The current reference All flows empty state, flow-template gallery and welcome
preview map were inspected read-only on October 17. Official source contracts
were rechecked against Mailchimp’s current create/edit, trigger, classic and
report guides. No reference flow was saved, activated or edited. The account had
no populated flow editor available for inspection, so exact editor pixel parity
is not claimed. Native triggers/actions use MessageMonkey data; connected stores,
external apps, SMS and webhooks remain excluded. Visible labels retain Connections
and avoid commerce-oriented terminology.

Implemented scope:

- All flows/classic tabs, scoped search/status filters, lifecycle/replication/trash,
  template topic/channel filters and preview prerequisites/maps.
- Connected visual map, step inspector, branch routing, drag insertion, zoom/pan,
  settings, autosave/recovery and undo/redo. Maps are bounded acyclic graphs with
  stable step keys; repeat entry is an explicit flow setting.
- Native subscribed-signup, profile-field, tag/group add/remove, date/birthday/
  signup-anniversary, observed non-bot email engagement, verified survey response
  and flow-completion triggers. Anonymous response addresses never identify a
  connection. Date scanning skips missing values and deduplicates local occurrences;
  February 29 anniversaries use February 28 in non-leap years.
- Scoped entry and exit condition trees, cooldown/repeat and maximum residence,
  recipient time-zone fallback, weekday/time windows, durable delays, wait-until,
  wait-for-event/timeout and conditional/deterministic percentage paths.
- Email actions freeze a native saved email’s content/sender/tracking into the
  activated revision. They enqueue one private recipient through the existing
  encrypted delivery/suppression/quota/signing pipeline. Later source-email edits
  cannot replace a frozen email. Real setup/production readiness still gates
  activation of email flows.
- Tag/untag, group/ungroup, typed audience-field updates, unsubscribe, archive
  and exit actions are transactional. No flow can silently grant email consent.
- Pause/resume and individual step pause preserve existing positions. Elapsed
  delays continue while paused; a resumed person keeps their original activated
  revision, while new entries use the latest revision. Removing a flow cancels
  waiting actions; restore creates a private draft.
- Per-step waiting/completion/skip/failure reports, scoped execution histories and
  formula-safe CSV export. Recorded effects are not replayed. Explicit retry is
  limited to failed data steps; email receipts, especially uncertain acceptance,
  must be reviewed separately.
- Classic compatibility is deliberate: native tag-triggered emails and a documented
  ordered native email/delay JSON format convert to reviewed drafts. Vendor queues,
  past execution history and connected actions are not fabricated or imported.

Schema: `20261017_add_marketing_flows.sql`. The existing mail worker gains only
native flow execution, audience data-action and queue rights; it cannot activate
flows, change their authoring documents, replace AWS credentials or replace a
connection’s email identity. Shared MOED business data stays outside these rights.

Follow-up shell preferences on this branch: monkey-logo click returns to Audience;
Control-click returns to the MOED dashboard, including macOS context-menu behavior.
Interface fonts are 7.5% smaller than their previous size, tooltip text is about
12px, and authored email typography is independent.

Qualification completed for the native non-live scope:

- **64 flow HTTP/execution checks; 661 total Audience HTTP checks** in a disposable
  database, followed by native SNS/SES, quota/ambiguity/no-replay, restricted-worker
  and encrypted credential rotation/binding checks. Fake transport only; no real
  message was sent. The disposable project removed only its own resources.
- **34 document/DAG/time-window/event checks**, full `composer test`, **316 JavaScript
  tests**, generated assets/classic bundle parsing, shared label punctuation and
  PHPStan routes passed. Environment-dependent standalone integration tests in
  Composer retain their documented skips; the Audience suite above ran against
  actual disposable HTTP/database services.
- Actual authenticated localhost browser: list/empty state, template filtering and
  full-page preview, create/rename, plus/palette insertion and inspector editing,
  keyboard save before field blur, saved map persistence on return, undo/redo,
  zoom/reset, report/connection-picker empty state and remove/restore. Browser
  errors were empty and desktop document width matched its viewport (1265px).
  A private qualification draft was returned to Trash; no connection was enrolled
  and no flow activated. Screenshots are retained under
  `var/deployment/evidence/stage5-{map,automations}-local.png`.
- Local migration installed after a fresh encrypted database/files backup passed
  restore verification. **126 existing application tables**, zero pre-existing
  delivery rows and persistent volume identities remained unchanged. Forty
  Docker networks were checked for collisions/reservations with no assignment
  changes. Local web/ingress/database/mail-dispatch returned healthy. A stale
  individual-file privilege-script mount was worked around with a private
  temporary copy of the qualified script, which was removed afterward; the
  database was not recreated or restarted. See the retained private receipt
  `var/deployment/stage5-local-receipt.json` for backup location/checksum.

Browser qualification found and corrected keyboard-save tracking while text
remained focused, save-title refresh, and Trash access after removing the final
active-list draft. No-op profile saves/data actions, tags and groups cannot create
spurious entry events. Frozen SES binding comparison normalizes JSON key order
while still enforcing the same account, Region and settings.

Production rollout is tracked separately by the release runner receipt. Controlled
live flow sending/receipts/inbox/client rendering remains gated by AWS approval.
Email authoring links to the existing native campaign designer and settings rather
than duplicating an inline vendor editor. The subsequent qualification below
closes mobile/dark layout acceptance; exact populated reference-editor/report
pixels remain unavailable for comparison and are not certified.

### Audience toolbar follow-up — October 17, 2026

Read-only inspection of the signed-in reference Contacts page covered quick
status/tag/source selections, saved-segment search, removable chips, count
shortcuts, the advanced editor and the overflow destinations. The reference keeps
checkbox menus open while updating results; values within each filter type use
OR, with AND between types. Tags use any-of membership. Edit filters receives
the full current criteria, including multi-value conditions. The reference source
picker groups acquisition types rather than displaying every raw integration name.

The native toolbar now keeps its existing popover, checkboxes, focus and search
text mounted while a read-only GET refreshes the result list and filter summary.
Superseded requests cannot replace newer results. Loading results are inert;
request/session errors are visible. Selection resets after a filter change, while
column preferences, page/all-match selection and export controls remain usable.
Three tag chips remain visible with an exact `+ N` profile link for the rest.

Quick filters, advanced editing, preview, saved segments and filtered campaign
drafts share one validated criteria tree. Save as segment retains the whole
selection; draft recipient counts independently enforce current subscription and
archive eligibility. Native name/email search remains available in combination
with filters, an intentional extension to the observed reference interaction.
Source categories derive from stored provenance; original source strings remain
unchanged. Legacy raw-source URLs still work, and the four original missing-source
fallback records appear under Unknown.

The unfiltered headline now excludes pending and cleaned records, with a separate
cleaned shortcut. The underlying list retains all records. The original local
snapshot remains **10,884 records: 7,203 subscribed, 1,854 unsubscribed, two
non-subscribed and 1,825 cleaned**, yielding a **9,059** non-cleaned/non-pending
headline. No connection or subscription record was altered. An explicitly
approved fresh Mailchimp export comparison found two later unsubscribes and two
absent formerly cleaned addresses. Its CSV yields 9,059 active contacts and 7,201
subscribers, while its refreshed header shows 9,058 and 7,200. That remaining
one-record vendor discrepancy is unresolved; the CSV was not imported. Only
aggregate comparison evidence is retained in the private qualification report.

Qualification:

- **21 additional toolbar HTTP/storage checks; 682 total Audience HTTP checks**,
  including previous flow, collection, delivery and account-isolation coverage.
  Fake transport only; the disposable environment removed only its own resources.
- **10 pure filter-composition checks**, full Composer suite, **316 JavaScript
  tests**, route analysis, generated assets, classic bundle parsing and shared
  label-punctuation checks passed. Optional unrelated standalone integration
  tests retain their documented environment skips.
- Authenticated localhost browser verified two checked statuses and two checked
  tags without closing the menus or losing the typed tag search, full criteria
  in the advanced editor and unchanged membership after Apply, live search/clear,
  columns, page/all-match selection, save-segment and campaign-draft dialogs,
  grouped sources and exact `+ 4` overflow. Dialogs were cancelled; no real local
  segment or campaign was created. Console errors were empty. Cropped non-PII
  screenshots are retained as
  `var/deployment/evidence/audience-multiple-statuses-local.png` and
  `var/deployment/evidence/audience-tag-overflow-local.png`.

These source/asset changes use the existing local bind mounts. No additional
migration, restart, deployment, subscription reconciliation or real sending was
performed. Exact vendor pixels, every vendor-only advanced predicate and
mobile/dark browser acceptance are not claimed by this focused follow-up.

### Stage 5 responsive and reference closeout — October 17, 2026

The final native browser matrix covered list, classic compatibility, gallery,
welcome preview, editor, settings and report at **320, 390, 768 and 1280 CSS-pixel
viewport widths**, in both light and dark modes: **56 combinations**. An editor
margin mismatch and tablet heading actions initially overflowed; the final fixes
match the workspace padding breakpoints and wrap heading controls within their
available space. Final layouts had no document-width overflow or PHP error text.
Canvas and wide tables retain their own intentional scroll regions. Native mobile
keyboard zoom/reset worked, and Escape closed the activation review and returned
focus to Continue. The incomplete email review kept Turn flow on disabled. Real
device touch input and every assistive-technology/browser combination are not
claimed by viewport-based browser qualification.

The signed-in Mailchimp flow home, creation details, gallery and welcome preview
were inspected read-only again. Preview breadcrumbs/back navigation now sit in the
native page header, and intro/map spacing and action-node accent follow the
observed preview more closely. Native names, one canonical audience, omitted
connected/commerce/SMS capabilities and MOED theme controls remain deliberate
adaptations. No reference flow was created, saved or activated. The reference
account still had no populated editor or report; the current official
[editing guide](https://mailchimp.com/help/edit-customer-journey-map/) and
[report guide](https://mailchimp.com/help/about-customer-journey-reports/) were used
for behavior comparison. Exact unavailable reference pixels are not certified.

Final isolated qualification passed **66 flow HTTP/execution checks and 684 total
Audience HTTP checks**, including two new regression assertions that a complete
email flow cannot activate without SES production access and creates no executable
revision on rejection. Native suppression, receipt ambiguity/no replay, worker
permissions and credential rotation/binding also passed. The initial rerun used a
stale cached ingress image and failed the existing frame-ancestor check; rebuilding
the disposable images from current source resolved it. Only the isolated test
project's resources were removed. Existing databases and volumes were retained.
The **34 pure flow checks**, **316 JavaScript tests**, route analysis, PHP syntax,
generated assets and label-punctuation checks passed. AI Coach source hashes match;
the Comprehensive Guide sources/PDF are unchanged from the deployed 2.6.0 base.

The private local responsive draft (ID 2) was returned to Trash without activation,
enrollment or email. Browser console errors were empty, the original light theme
and viewport override were restored, and temporary tabs were closed. Aggregate
browser evidence is retained at `var/deployment/stage5-responsive-qualification.json`;
screenshots include `evidence/stage5-mobile-dark-preview.png`,
`evidence/stage5-desktop-dark-preview.png` and `evidence/stage5-mobile-dark-review.png`
under that directory. The requested minor deployment advances 2.6.0 to 2.6.1 through
the protected release runner, verified backups and shared-account readiness gates.
It does not authorize flow activation or live sending qualification.

The first protected integration run exposed a fixture dependency: the group-trigger
check had relied on a group created by the standalone Stage 1b suite. It now creates
its own scoped group. The complete repository integration order then passed in a
fresh disposable environment, including all 66 flow and 684 Audience checks, before
retrying the same 2.6.1 release. No runtime behavior or release version changed for
that correction.

### Stage 6 native Inbox — October 17, 2026

Branch: `codex/mailchimp-cloning-continued3`, based on release 2.6.1. This is local
feature development, without a version bump or production deployment.

The standalone Inbox is available at `audience.php?view=inbox`. It follows the
observed Mailchimp Inbox shell: compact logo/Back header, a Sources/Labels sidebar,
a separate conversation list, search and To Do/Done/Trash/All tabs, and a detail
pane. MOED's Account, Viewing As and deployment notice stack is retained. Native
source, label, template, notification, recipient-picker and conversation screens
share this shell. The live reference was inspected without saving vendor settings,
connecting sources, creating vendor records or sending mail. A populated vendor
conversation was unavailable; native history/reply/error states are qualified
against the documented behavior rather than claimed pixel-identical.

Native implementation:

- Account/audience-scoped signed reply routes, authenticated receiving-gateway
  intake, RFC references, exact sender association, replay protection, unknown
  senders, automatic-reply filtering and safe text rendering. Route capabilities
  use lowercase hexadecimal in addresses because receiving parsers lowercase
  email addresses; the existing signed Account marker is reconstructed and checked.
  Mixed or forged Account routes and untrusted senders cannot auto-file messages.
- Email/flow snapshots can freeze an Inbox Reply-To address when connected. Native
  survey replies require an email address and an open-text answer. Hosted contact
  forms have expiring account-bound proofs, a honeypot, rate limits and idempotent
  receipts. Typed email addresses do not establish a Connection identity or consent.
- Search, source/label/status/unread filters, stable thread selection, bounded
  history with an earlier-message cursor, per-user read state, versioned status
  changes and recoverable Trash. Moving a conversation to Trash cancels unsent
  queue items under the same advisory lock used by the sending worker.
- Private per-user drafts and attachments, explicit recipient selection, safe rich
  text, native reply templates, supported merge tags, internal comment replies,
  author attribution, labels, reading/typing presence, per-user browser/email
  preferences and scoped notification links in the shared MessageMonkey bell.
- Sending freezes one encrypted service queue item per draft. The web request and
  restricted worker recheck role, identity, consent, suppression, source state,
  account/Region SES binding and provider readiness. Accepted and uncertain outcomes
  are distinct. Signed SNS receipts can resolve uncertainty without replay and
  record delivery, bounce and complaint outcomes; negative receipts follow the
  existing consent chronology and suppress the matching Connection safely.

Deliberate adaptations: Inbox access requires an editor or administrator. Only an
unrestricted administrator configures sources. Email notifications are personal
opt-ins, rather than one teammate choosing another teammate's preferences. Browser
alerts operate while Inbox is open. Compose/reply requires an active subscribed
Connection that passes the existing global suppression rules; anonymous messages
can be reviewed and explicitly linked to a matching existing Connection, but do
not grant subscription. A separate one-to-one opt-out leaves newsletter consent
unchanged; a subsequent authenticated inbound message can reopen one-to-one
contact. Attachments are downloadable PDF/JPG/PNG/GIF, up to 5 MiB each, 6 MiB total
and 10 files, with byte/MIME validation and the existing scanner when enabled.
These stricter limits keep the rendered SES message within its native envelope.
SMS, connected apps, external integration sources and vendor feedback are omitted.

The migration connects no sources and enables no notifications. An administrator
must validate the existing gateway's signed plus-address delivery and sender
assertions before enabling email replies. No DNS, credentials, AWS configuration,
subscription consent, existing volumes or production releases are changed by
Stage 6 development. AWS production approval, real inbound gateway delivery,
provider-backed live replies/attachments/alerts and live SNS round trips remain
separate qualification prerequisites; no test or campaign email was sent.

Qualification: 18 pure Inbox checks; the isolated Audience suite passes 737 HTTP
checks including 53 Inbox checks, native storage/route/attachment/public-form and
receipt scenarios, existing SNS/quota/ambiguity/no-replay checks, dedicated mail
worker authoring separation and encrypted-credential rotation. Existing SMTP,
Inbound helper and Flow document checks pass; 15 shell/deployment JavaScript checks
pass. Local schema backup and browser results are recorded in
`var/deployment/stage6-local-receipt.json` and Stage 6 evidence files.

Browser closeout: desktop light, 820px tablet dark, 390px phone dark and 320px
phone layouts passed without horizontal overflow or console errors. Account and
Viewing As banners stack above Inbox. Private drafts retain their recipient/body;
attachments work after an AJAX save updates the draft version. Native reply
templates create, edit and populate a private composer. Fictional test history is
in recoverable Trash, drafts are discarded and the test template is soft removed;
zero Inbox service-mail items were created locally. Sources remain disconnected.

Shared MessageMonkey follow-ups: the upper-right moon/sun control appears before
the notification bell and reuses MOED's shared theme preference. Inbox keeps the
outlined, un-underlined Back arrow left of the monochrome monkey and Inbox label.
Create focuses its heading on opening, rather than automatically outlining the
logo; keyboard navigation still shows the logo's focus indicator. Role, light theme
and viewport were restored. Local workers are healthy. The local runtime image
still declares 2.5.25 while workspace development is based on 2.6.1; no release or
image bump is implied by this bind-mounted preview.


### Stage 7 Home and Email dashboard — October 17, 2026

Branch `codex/mailchimp-cloning-continued3`; local bind-mounted sources based on
2.6.1. No version bump, production deployment, AWS configuration change or source
activation is included.

Reference inspection used the signed-in Mailchimp Home, Analytics dashboard,
Audience Analytics and Custom reports screens. No vendor records were saved.
Observed behavior:

- Home has quick actions, Create email, a 30-day snapshot, audience summary,
  recent campaigns with a draft switch and template destinations. Its rolling
  date window ends yesterday. The date popover has presets, separate calendars,
  Cancel and Apply; changing an uncommitted date must not change the report.
- Analytics is a separate dashboard. It has a 90-day default including today,
  Previous period/None comparison, a searchable multi-select message filter that
  stays open while metrics refresh, All/Regular/Automation tabs, exclusive metric
  cards and Campaign/Day/Week/Month plots. Engagement, funnel and delivery panels
  are distinct. Engagement and delivery selectors keep independent state.
- Home trend arrows are filled triangles beside relative percentage changes.
  Native comparisons now use `%` rather than percentage-point text. A zero or
  unavailable baseline displays a dash; fewer unsubscribes/bounces is favorable.
- The reference bot information distinguishes MPP and click filters. Native
  filtering only claims the known bot flags it actually retains. Privacy proxies
  do not establish a human read, and missing provider receipts do not prove failure.
- Audience Analytics additionally has contacts over time, source performance,
  source filtering and drilldowns, interval controls and source trends. Custom
  reports has favorites, classic/enhanced entry points and an unsaved builder.
  These advanced views remain Stage 7 requirements, not completed parity.

Native implementation:

- `audience_analytics.php` provides validated UTC windows, exact adjacent
  comparison periods, date presets and scoped aggregate queries. Unique message
  flags are aggregated before campaign/day rollups to prevent event fan-out.
  JSON boolean bot flags are compared as unquoted values; the previous numeric
  comparison counted bot activity. The correction is shared with existing
  delivery reports and timing calculations.
- Separate Home and Analytics templates share the workspace authorization,
  account/preview/deployment banners and theme. Home retains recent campaigns,
  draft switching and quick actions; Analytics has interactive metrics, blue
  cohort bars, previous-period averages and separate delivery/funnel sections.
  Audience and conversion entry points show available native data without
  inventing historical subscriber totals, sequential human conversions or sales.
- Search/type/message/bot/date controls are validated server-side. The message
  selector refreshes results without closing or clearing its search. Metrics and
  chart intervals remain URL-addressable. Aggregate CSV is editor/admin-only;
  recipient-level exports and saved custom reports remain later work.
- The date popover matches the observed layout and has a native calendar grid,
  month navigation, keyboard arrow navigation, preset changes and discard-on-Cancel.
- The initial Create dialog heading remains the announced focus target, with
  its noninteractive outline removed. Buttons/links retain keyboard focus.

The user's A/B exclusion supersedes previous stages: creation controls, advanced
configuration, content variants, winner release, lifecycle processing and worker
execution are blocked. Popup/form variants are rejected on save and public pages
use their canonical content. Historical schema/receipts are kept; replicating an
older email strips experiment settings. This does not remove ordinary preview
emails, deterministic resend or historical event attribution.

Local samples are installed only through an explicit development CLI opt-in.
Nine completed fictional campaigns cover current and two comparison periods;
twelve fictional contacts are archived and non-subscribed, and three clearly
labeled Inbox conversations are unverified and unlinked. No sample delivery or
service message is queued. The seeder is idempotent and changes no sending or
source setting. No sample data was installed in production.

Qualification: 768 isolated Audience HTTP/storage checks, including 36 Analytics
checks; 13 restricted receiving-gateway checks; 14 date/trend checks; 18 Inbox
validation checks; Bridge authentication and actual loopback IMAP wire-protocol
checks. Existing restricted-worker, SES quota/ambiguity/no-replay, SNS,
credential-rotation, flow and collection checks pass. PHP/JavaScript syntax,
asset generation and whitespace checks pass. Browser verification covers desktop
light/dark, date calendars and Cancel, multiple message selections staying open,
independent chart metrics, sample comparisons, and the corrected Create heading.
The new Stage 7 phone/tablet layouts have CSS support but are not newly certified
by this record. Earlier Stage 6 responsive qualification remains recorded above.

Receiving qualification distinction: the existing production worker and Bridge
were healthy, and IMAP PEEK authenticated seven of eight sampled messages. One
user-approved operational SMTP message was sent only to the existing receiving
mailbox using a signed Account-specific plus address. Proton returned a valid
`unverified` assertion for that self-send. Its address/body/attachment arrived;
it is not evidence of an automatically trusted sender or of production Inbox
filing. No authentication policy was relaxed. The native restricted ingestion
suite verifies positive signed intake, exact conversation association,
attachments, duplicate UID handling, unverified sender review and auto-reply
rejection. Positive live Inbox association still needs the Stage 6 production
release and a receiving-verified message; live replies and provider acceptance
remain separately gated by AWS approval and explicit qualification.

Private receipts and screenshots are in `var/deployment/stage7-local-receipt.json`
and `var/deployment/evidence/stage7-*`. Live sending and all Inbox sources remain
disconnected. This is a qualified initial Stage 7 slice, not full Mailchimp parity.

### Stage 7 remaining native reports — October 17, 2026

The remaining non-AWS Stage 7 scope is implemented and locally qualified on
`codex/mailchimp-cloning-continued3`. This supersedes the initial-slice backlog
above; it does not claim identical vendor data or complete pixel parity.

- Audience analytics: current counts reconcile with source totals, recorded join
  history and observed subscription gains/opt-outs/net changes, relative previous
  date buckets, source filters and connection drilldowns. Historical imported
  snapshots cannot reconstruct subscriber totals; incomplete old transitions are
  disclosed rather than guessed.
- Email reports: paginated recipient activity, unique-message link metrics and
  link-to-recipient navigation; immutable-content click maps use a sandbox,
  restrictive CSP, inactive links and image placeholders. Coarse country/client/
  device summaries preserve Unknown and explain proxy and category overlap.
  Browser tracking stores no raw IP or user-agent; country uses the existing
  trusted edge chain and URL labels omit queries/fragments.
- Custom reports: email, audience, forms and flows; metrics/dimensions, up to two
  plotted metrics, native message/current-segment/source filters, versioned
  save/rename/copy/favorite, recoverable removal and detailed CSV. Saved definitions
  use the existing locked audience settings row (maximum fifty retained reports),
  preserving unrelated settings with optimistic report versions. No migration or
  database privilege expansion was needed. Private reports are visible to owners
  and account administrators; explicit internal sharing requires normal account
  login. Export remains editor/admin. No public report links or messages were sent.
- Cross-resource analytics: form daily visitors, submissions and distinct confirmed
  subscriptions; automation start-date cohorts and current outcomes; prior-period
  comparisons, exports and links to detailed native resource/run reports. These
  metrics do not invent external commerce or sequential email-to-form attribution.
- Native benchmarks and content/timing insights: weighted historical comparisons,
  immutable subject-length groups and the same ninety-day unique-recipient click
  evidence used by optimized scheduling. Fewer than one hundred distinct non-bot
  click recipients retains the selected time. Opens do not determine send timing.
  These are observational insights, with no A/B testing or Stage 8 AI suggestions.

The Mailchimp custom-report builder was inspected again without saving vendor
changes: seven default email metrics, up to two visualized metrics, report type,
message/segment filters, name/description, favorite/copy, export and refresh. The
native implementation retains the previously inspected Home/Analytics distinction
and date/calendar workflow. It adapts access to MOED authorization and omits the
user-excluded channels and A/B controls.

Local sample data now includes three draft/unpublished forms, two paused sample
flows with completed fictional runs, current/prior growth evidence, two saved
sample reports and the existing nine unsent sample campaigns/twelve archived
non-subscribers. A private sample copy exercised the browser rename/save controls.
No sample recipient or run queues real mail. Existing sample event enrichment is
append-only; only explicitly marked fictional local delivery snapshots gain the
example.test links used in their maps. Production data was untouched.

Qualification: **831 isolated Audience HTTP/storage checks, including 99 analytics
checks**, plus 13 restricted receiving-path checks, 24 date/report/evidence checks
and 59 email-design checks. Existing worker-grant, SES quota/throttle/ambiguity/
no-replay, SNS, flow and credential-rotation checks pass. The suite exercised report
ownership/sharing/revocation, CSRF, account isolation, recipient pagination, repeat
observations, weighted metrics, export access, click-map safety and the exact
99-to-100 non-bot timing threshold. PHP/JavaScript lint, generated assets and
whitespace checks pass.

Browser qualification: desktop light/dark; 390×844 phone routes for Home and every
Analytics subview; 768×1024 report builder; calendar bounds/Cancel; copy/rename/save;
link-to-recipient counts and populated sample click map. Phone recipient tabs and
wide tables scroll within their panel without page overflow. Dynamic report bars
use SVG width attributes compatible with the existing CSP; a zero value is an
actual zero-width bar. Browser console errors: none. Viewport override was reset
and the original light theme restored.

The local SES enabled/production/sending flags and all four Inbox source flags
remain zero. There are no pending sample deliveries. Source totals and visible
connection count both equal 9,059. Source VERSION remains 2.6.1; the bind-mounted
local runtime declares 2.5.25. No bump, commit, push or production rollout occurred.
Real SES sending/provider-event qualification still awaits AWS approval and
explicit live qualification. The earlier Stage 6 verified-sender/deployed-gateway
prerequisite remains separate. Historical missing event metadata, external
industry benchmarks and privacy-proxy identity are data limitations, not invented
Stage 7 results.

### Stage 8 native AI qualification, October 17, 2026

AI01–AI04 now use MOED's existing private `qwen3:8b` service on shunbun through
its existing tunnel, request history, bounded queue and two workers. No schema,
credentials, network configuration or cloud AI provider was added. Local code
remains on `codex/mailchimp-cloning-continued4`; this qualification is not a
production deployment.

- Writing: new drafts, rewrite, shorter/longer copy, tone, subject ideas and review
  suggestions. A selected designer text/heading block supplies a copy of its text.
  Reviewed replacement checks the original block snapshot and uses normal
  undo/autosave. Standalone reviewed headline/body can create one idempotent native
  template, apply current brand styles and optionally use an existing My files
  banner. Source text can be refined again; no existing campaign is overwritten.
- Content review: word count, estimated reading time, subject length,
  personalization and competing-link checks are deterministic and separate from
  model suggestions. These checks make no delivery, accessibility, spam or
  performance guarantee. Model text still requires factual/editorial review.
- Analytics: administrators can ask about the exact native date/message/type/
  bot/segment scope. The server selects aggregate facts and daily counts; contact
  identities, Inbox messages and uploaded files are excluded. Source cards,
  comparison calculations and charts are application-owned. Model observations
  cite allowed facts; numeric claims must match their cited source. Advice cannot
  change filters, contacts, consent, schedules or deliveries.
- Access/reliability: editors/admins can write, analytics requires admin, reviewers
  are denied at routes and API. CSRF, account/actor/current-role ownership, purpose
  and nonce checks cover history, polling, cancellation and applying. Worker
  account/current-access checks, shared eight-job admission/two-worker limit,
  deadline, cancellation and late-result protection remain in effect. Recovery
  stores only request identifiers/times/mode in the browser, not source text.
  Existing MOED AI review/retention policies cover these requests.

Qualification: **868 isolated Audience HTTP/storage checks, including 37 new AI
checks; 32 pure AI/evidence checks; 320 JavaScript checks**. Existing Coach HTTP,
queue, feedback and real-worker recovery/navigation suites pass. Its pre-existing
CLI session-cleanup warnings remain in those fixtures; no application warning was
found in the native assistant routes. PHPStan, generated assets, UI label/title
checks, source-hash and whitespace checks pass. Real private-engine writing,
inline replacement/undo, native template creation, Analytics AI and own history
were exercised. Phone (390 × 844) dark/light and desktop checks show no page
horizontal overflow. Local evidence is under `var/deployment/evidence/stage8/`.

The first model analytics output repeated unverified numbers. The final contract
checks numeric statements against exact server evidence, including per-observation
source association; a qualified real response reconciles with the local sample
current/previous metrics. Malformed, unsupported, truncated and cancelled replies
are not offered for applying. This is native functional coverage, not a claim of
identical vendor AI output, generated photography or external commerce knowledge.
No test/campaign email, AWS change or production deployment occurred; live sending
remains gated. One explicitly named Stage 8 picnic template is available locally
for viewing/editing.

Next authorized work: Mailchimp historical analytics import, preserving dates and
source provenance. The real target dataset is **s1 / Shalom in Messiah**, not only
localhost. Imported history must never create delivery jobs or change consent.

### Mailchimp history qualification, October 18, 2026

> Retired by user request on October 18, 2026. The entry below records the former
> implementation; its importer and dashboard are removed on
> `codex/mailchimp-cloning-continued5`. Native analytics and Stage 8 AI remain.

The authorized all-reports ZIP contains 4,059 campaign summaries. The final import
scope retains **335 non-devotional campaigns**, excluding 3,724 daily devotionals
across the original audiences. Three holiday/event messages from the old devotional
audience remain included; original audience name alone is not the exclusion rule.
The retained original-audience counts are 184 master-list messages, 148 news alerts
and those three holiday/event messages.

The importer validates CSV identities, timestamps, counts and archive bounds before
writing. It defaults to dry run, requires the exact deployment account and an active
administrator actor, serializes each atomic import, and uses stable vendor IDs and
content hashes for idempotent updates. Original Chicago timezone (verified in the
provider account), UTC timestamps, audience, source identity, displayed percentages,
bundle checksum and import attribution remain available. Recipient activity, URLs,
commerce fields and consent are not imported. Historical records have no dispatch
or native recipient relationship.

A separate Mailchimp history dashboard supports date ranges, previous-period
comparisons, original-audience filtering, weighted metrics, campaign provenance,
daily CSV and aggregate Analytics AI. Lifetime summary observations are grouped by
original send date. The bulk export's bot-filter provenance is unknown and is kept
explicit: its opens can include bots or Apple MPP activity hidden by Mailchimp's
current filtered cards. Current segments, native message types and bot detection
cannot reconstruct those historical observations. Missing evidence remains unknown.

Qualification passes **892 isolated Audience checks, including 24 history checks;
37 pure history checks; 32 pure AI checks; 320 JavaScript checks**, PHPStan, migration
integrity and generated assets. A fresh encrypted, restore-verified local backup
preceded schema migration. Local history contains exactly 335 campaigns; reimport
reports 335 unchanged. Native delivery/recipient counts, consent and the live-send
gate remain unchanged. Actual shunbun history analysis reconciles with the selected
aggregate evidence. Desktop and phone dark/light layouts, date application and
original-audience filtering were exercised; private screenshots are under
`var/deployment/evidence/mailchimp-history/`.

The user authorized a minor bump deployment followed by the same 335-record import
on **s1 / shalom-in-messiah**. Release qualification does not authorize live sending.
The 2.6.3 release imported those 335 records into Shalom only. That import policy is
now withdrawn: do not reimport this dataset. The removal preserves the original
migration checksum and adds a forward migration that drops the history table and
removes saved/queued AI analyses derived from that source. Removed source URLs and
AI requests are rejected instead of being relabelled as native observations.
Shalom and localhost data deletion is backed up and verified independently of the
pending feature-removal release. Test Account data and live-sending gates remain
unchanged. Original source exports and encrypted recovery backups are retained.

Removal qualification: fresh encrypted, restore-verified backups preceded deleting
335 imported records from each of localhost and s1's Shalom account. One derived
AI request was removed locally; none existed on s1. Native campaign/delivery/
recipient records, consent checksums and sending gates are unchanged, and s1's
Test Account is unchanged. The obsolete s1 staging archive and both container
staging copies were removed. The forward migration was applied locally; production
continues on 2.6.3 with an empty history table until the next requested deployment.

Checks pass: **874 isolated Audience HTTP/storage checks (38 AI checks), 28 pure
analytics checks, 33 pure AI checks and 320 JavaScript checks**, PHPStan, migration
integrity, labels, source hashes and whitespace checks. Native Analytics and
Analytics AI render locally without the retired navigation. Old dashboard,
export and assistant source requests are rejected. Private operational receipts
and screenshot evidence are under `var/deployment/`.


## Reporting and engagement review follow-up — October 18, 2026

Branch: `codex/mailchimp-cloning-continued5`. The [functional review follow-up](message-monkey-review-2026-10-18.md#authorized-follow-up--local-qualification-october-18) records the implementation and remaining scope.

Campaign list metrics, contact Insights/timeline and campaign/link/date engagement segments now share consistent native reporting definitions. Click-only evidence counts as an open; recorded failed messages leave the engagement denominator once; pending receipts are provisional; known-bot filtering is explicitly limited. Home growth, additional custom metrics/dimensions, saved-report capacity, older campaign selection, selected-dataset AI evidence and GIF/PDF content support are locally implemented.

Qualification: 909 isolated HTTP checks (127 analytics and 38 AI), 320 JavaScript tests and the focused pure validation suites pass. Restricted worker, inbound gateway and provider receipt fixtures pass without external mail. Desktop/mobile/light/dark browser checks cover the changed primary surfaces; the profile overflow found during those checks was corrected. The additive marketing-files migration is applied only locally. No s1 deployment, live sending, external AI inference or real receiving-path claim is included.

The feature matrix keeps `parity_qualified=no`: local functional qualification is not full visual equivalence or live qualification. Optional flow expansion, audience notifications/defaults and remaining production receiving/client/site checks are documented separately, rather than counted as complete.


## Remaining review code findings — October 18, 2026

Audience defaults now include optional default subject and sender inheritance for new drafts. Administrator-only settings configure individual subscription/unsubscribe notifications and daily UTC summaries in the bell and by email to verified account staff. Cursor baselines, unchanged-preference generations, operation keys, expiry and dispatch-time staff/preference checks prevent historical replay, duplicate alerts and delivery after revocation. Encrypted notification mail remains on the existing SES path and sending gates.

Flows support three starting triggers and 200 nodes. Existing one-trigger documents and running revisions remain compatible; event enrollment, independent date cursors, repeat settings and source/account validation are retained. Routing splits do not implement A/B experiments. Native reports support up to 3,660 days, all matching click-map deliveries, selected-snapshot decryption and bounded AI period totals. Settings link directly to forms and preferences.

Qualification: **928 Audience HTTP checks** (129 analytics, 38 AI, 16 defaults/notifications); **39 flow, 32 analytics, 33 AI, 40 SES and 22 dispatch pure checks; 320 JavaScript tests**. Worker credential/configuration separation, alert-worker bounded privileges, signed inbound gateway, migration and syntax checks pass without external mail. The notification migration is applied locally; no s1 deployment or sending-gate change is included. Desktop/mobile/light/dark and keyboard browser checks cover the changed settings and populated local sample flow. The inactive localhost Shalom draft “Sample · Multiple entry points” contains no email step.

The [review closeout](message-monkey-review-2026-10-18.md#remaining-code-findings--local-closeout-october-18) records the remaining operational and vendor-specific differences. Real mailbox/client/site verification is separate from local functional acceptance; no new external self-test email has been sent.

## Automation catalog and overview refinement — October 19, 2026

The current mapping and qualification scope is recorded in
[the automation mapping](message-monkey-automation-mapping-2026-10-19.md).
This local pass replaces the simplified catalog/linear previews with 17 native
starters, requirements, integration/topic/channel filters, real connected graph
previews and shared vector artwork. The main Automations page includes a five-task
illustrated setup checklist, progress/dismissal controls, recommendations, native
learning resources, and filtered/sorted native and converted-classic lists.
Transactional is excluded at the user's request; its page and related code are
removed while MOED's existing confirmations/system emails remain unchanged.
Percentage testing stays excluded. Existing published runtime snapshots remain
readable, Mailchimp is unchanged, and live sending remains gated.

## Pre-approval newsletter qualification — October 20, 2026

Branch: `codex/message-monkey-presend-qualification`. The read-only production
preflight checked s1 / Shalom in Messiah / audience 1 on application 2.6.9, using
AWS account 446413910099 in Ohio (`us-east-2`). AWS credentials stayed inside the
existing mail worker's memory. Audience aggregates used the application connection
in a read-only transaction; worker/database permissions were not expanded.

The sender domain is verified; DKIM is enabled with successful status and all
three public DNS records matching. Custom MAIL FROM status, SPF/MX and DMARC pass.
The expected configuration set is enabled, its configured SNS destination covers
all required event types, and the confirmed subscription remains stored. These
checks establish configuration readiness, not delivery of a new SES message.

The all-subscriber eligibility query returns 7,203 active subscribed connections,
with none additionally excluded by the native opt-out/deleted-email suppression
tables. Unsubscribed, non-subscribed and cleaned records do not enter that target.
This aggregate is not approval of a newsletter's eventual recipient segment.
Production has no campaign drafts, deliveries, recipient jobs or service-mail
jobs, so a specific newsletter's content/links and final segment remain unreviewed.

The missing audience mailing address was saved using the postal address explicitly
provided by the user. Only that settings field and its version changed; a native
audit event was recorded, and a separate application read confirmed it is present.
All other settings were preserved. `bulk_approved` remains zero, both bulk budgets
remain zero, and AWS production access remains disabled. No email was queued or
sent, and this pass did not deploy an application release.

Qualification passed 277 focused pure checks and 961 disposable HTTP/storage
checks, plus the restricted SES/dispatch/flow/Inbox worker, signed gateway and
credential-rotation suites. Coverage includes MIME/unsubscribe headers, scanner
GET versus one-click POST behavior, confirmed consent, signed SNS validation and
replay rejection, bounce/complaint suppression, tracking/report attribution,
scheduling/pause/cancel, quota/throttle handling and uncertain-response no-replay.
The disposable environment used synthetic data and fake transports and was removed
afterward. Actual provider pacing, delivered headers/client rendering and a real
reply's Inbox association still need separately authorized live acceptance.

AWS's simulator supports sandbox accounts, but MessageMonkey deliberately retains
its production-access gate. This pass did not bypass that gate for simulator or
verified-inbox sends. After approval, refresh quotas/readiness, allocate the bulk
budget, qualify approved test recipients and simulator events through the deployed
path, review the actual newsletter, and obtain authorization before enabling its
first subscriber send. Private aggregate receipts and logs are retained in
`var/deployment/message-monkey-presend-2026-10-20.json` and referenced local files.

## SES approval and campaign-gate repair — October 21, 2026

AWS account `446413910099` has production access in Ohio (`us-east-2`). The
read-only SES API and October 20, 23:23:05 Chicago support correspondence agree:
sending enabled, 50,000 recipient emails per rolling 24 hours, and 14 per second.
The production-access monitor was removed after approval was verified.

The user authorized controlled qualification using exactly the four addresses in
the saved `test accounts` segment and AWS simulator addresses. This does not
authorize subscriber campaigns, changes to those addresses' subscription status,
or opening `bulk_approved`. A private recipient snapshot records the four exact
addresses and segment version; its contents are not release documentation.

Preflight verified the existing From identity, DKIM, DMARC, custom MAIL FROM
SPF/MX, configuration-set events and confirmed SNS subscription. Both campaign
and service delivery queues were empty. The native AWS readiness check ran in a
rolled-back database transaction, so it did not change production eligibility.

Qualification exposed an approval gap: the SES path checked AWS readiness but
did not enforce the local campaign approval and budget policy. The repair checks
that policy during campaign review, worker dispatch and SES transport. Explicit
test mail uses its existing separate lane and still requires all sender, identity,
quota and subscription prerequisites. Revoking approval after confirmation pauses
the campaign without invoking transport or starting a recipient delivery.

The disposable Audience suite passed 1,003 HTTP/storage checks and the actual
restricted-worker suites; pure SES (40), dispatch (22) and delivery-runtime (18)
checks passed. All used fictional recipients and fake transports. No live message
had been sent at this checkpoint. The user authorized release 2.6.13 for this
repair before continuing controlled qualification. Campaign approval remains off.
Actual delivered headers, client rendering, provider receipts and unsubscribe
acceptance must be recorded separately; these local tests do not certify them.

### Controlled delivery and SNS handoff follow-up

The user separately approved restricted raw-email sending permission for the four
frozen test inboxes and three SES simulator addresses, followed by Ohio-only
permission to disable SES open and click tracking. Native campaign tracking still
uses its own signed links and open pixel. Campaign approval and both sending
budgets remained zero throughout qualification.

SES accepted all seven original test messages. The user confirmed that all four
controlled inboxes received and displayed the message correctly. Fifteen actual
AWS-signed notifications were verified against the accepted message IDs, account,
Region, topic and destination: sends, controlled-inbox deliveries, simulator
delivery, permanent bounce and complaint. Private qualification receipts retain
the evidence; recipient addresses are not release documentation.

Applying those notifications on 2.6.13 exposed a type error at the SNS-to-Inbox
handoff: the verified AWS message object was passed to a helper expecting an
array. The focused repair passes the original verified envelope array. Regression
coverage exercises signed Inbox delivery, duplicate handling and an unmatched
test notification through the complete validator path. No schema, permissions or
campaign-gate changes are part of this repair.

The bounded production processing attempt rolled back on that error. Native
receipt processing awaits deployment of the repair. Campaign suppression,
recipient-bound unsubscribe/resubscription, actual authentication headers and
campaign engagement still require their own end-to-end qualification. The
controlled test lane deliberately does not create campaign tracking events.

## Inbox receiving and hosted signup qualification — October 20, 2026

Continued on `codex/message-monkey-presend-qualification`. On s1 / Shalom in
Messiah / audience 1, the administrator-authorized receiving address was prepared
without connecting sources or sending mail. The user then sent a real external
test message. The existing gateway recorded trusted `proton-dmarc` authentication
at 14:48:14 UTC, unlike the earlier unverified Proton self-send.

After that evidence was verified, native settings connected signed Inbox routing
and campaign replies. Survey/contact sources and their content were preserved.
The existing restricted `dnrmailingest` worker processed only the qualification
message: inbound 14 became Inbox thread 1 in the correct account, with a verified
sender and direct-message source. Reprocessing that same inbound message left
exactly one message in the thread. Sender-authentication requirements were not
relaxed, and no outbound email was sent. Actual replies to delivered newsletters
remain part of the later controlled SES acceptance.

The standalone hosted form was reviewed on localhost: email required, optional
first/last name, explicit newsletter permission, double opt-in and native
confirmation/unsubscribe screens. Its public page explains unavailable email
delivery and disables collection while SES readiness blocks confirmation. An
identical unpublished draft was prepared on s1 (resource 1); it was not published.
The user explicitly deferred the first newsletter's content and recipient review.

The review found an actual editor rendering defect: asynchronous preview responses
used a new CSS nonce, which the parent page's CSP rejected. Preview requests now
carry the containing editor's nonce; native and custom-layout styles use that
validated nonce. Public responses retain their own nonce and the security policy
is unchanged. The spam-trap element also has a native `hidden` attribute.

Shared public-form styling now provides a restrained accent card, clear typography,
comfortable controls, desktop first/last-name columns and a single mobile column.
The lower Save draft action has 28px top margin plus 24px padding and a separator.
These application changes are available on localhost; s1 still runs 2.6.9 and
requires an authorized qualified release to receive the code changes.

Verification passed 28 focused collection-rendering checks, 963 disposable
HTTP/storage checks (including the preview-nonce regression and existing Inbox,
consent, report and account-isolation coverage), restricted worker/gateway/rotation
suites and five classic-browser bundle checks. The disposable project was removed.
Live browser observations verified styled signup/confirmation/unsubscribe previews,
the hidden spam trap, disabled preview submission, lower-action spacing and a
390px public page with no horizontal overflow and disabled signup controls.

The final s1 readback retained `bulk_approved=0`, zero hourly/daily bulk budgets,
`production_access=0`, and empty delivery/service-mail queues. Private aggregate
receipts and screenshots are recorded at
`var/deployment/message-monkey-inbox-signup-2026-10-20.json`.
