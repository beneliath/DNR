# Audience: Mailchimp interface and feature parity

Original reference branch: `codex/mailchimp-clone`

Current Stage 3 closeout branch: `codex/ses-endpoint-layout`

Reference review: October 16, 2026

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
| 7 | Full analytics, A/B and multivariate campaigns, comparison reports, conversion attribution and optimization | Reproducible metric calculations, event provenance, experiment assignment and bot filtering |
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

The native management UI follows the observed layout and labels. Known differences remain: Arial/Helvetica fallback rather than the proprietary reference font; MOED branding and single audience; regular/plain-text creation only; no multivariate creation, public browser preview, team draft-sharing, holiday overlays, date presets or Campaign Manager plans yet. Calendar dates are UTC. The full visual designer/templates, design revision history, tracking settings, personalization, multi-target/exclusion controls, and preview/test-email tools remain subsequent Stage 2 work. Delivery verification, scheduling and sending remain Stage 3. Email templates and Send are explicitly unavailable, rather than pretending to complete those operations.

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
Campaign Manager plans; A/B/multivariate drafts; date presets and calendar holiday
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
A/B/multivariate creation; up to three A/B/eight multivariate combinations; separate
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
Tags, Email Marketing, Source, Date Added**, with Date Added descending. Last
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
- A/B/multivariate dispatch freezes combinations and deterministic, disjoint test
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
