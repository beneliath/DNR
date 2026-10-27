# MessageMonkey function and performance review — October 26, 2026

Local functional qualification passed on `codex/messagemonkey-function-performance`, based on release 2.8.0 / `40e6c72`. The review found and fixed three database query bottlenecks and gaps in the focused test runner. All executed regressions passed after correcting stale browser assertions. This is a local feature review with repeatable evidence, not certification of every production workload or execution path.

## Changes and reproduced findings

1. **Connections fetched tags and groups separately for every displayed contact.** A 100-contact page issued 200 membership SELECTs. `AudienceStore::contactListRelations()` now retrieves the same labels and ordering in two queries. The route uses this helper, with audience checks on both contacts and definitions.
2. **Conversation messages fetched attachments and delivery events separately for every message.** The 100-message fixture needed 202 SELECTs. `inboxMessages()` now needs five, preserving chronological display, page/cursor boundaries, attachment metadata, and event ordering. Attachment contents are not loaded into the conversation list.
3. **The group catalog fetched options once per category.** Twenty categories needed 21 SELECTs. The catalog now uses two, retaining empty categories, option ordering, and counts that exclude archived/deleted/foreign connections.
4. **The focused Audience integration command omitted newer tests.** It now discovers every `audience*_integration_test.php`, including campaign/form/flow lifecycle, AI terminal handling, planner, template transfer, storage, and performance. Existing restricted delivery/ingestion/credential suites still run. The general dispatcher runs the new authenticated HTTP benchmark as `www-data`, as required by the shared session fixtures.
5. **Three browser suites had stale assertions.** Updated campaign action colors, SVG pagination, planner action order/Manage label/dark tooltip colors, and the campaign exit control. These follow the existing approved interface; no visual redesign was made. Added a runner for the standalone rich-text, color, drag/drop, and image-upload fixtures in Chromium and WebKit.

The query regression was first run against the original implementation and failed its query budget after recording the old counts. It now passes 386 checks, including comparison with the original contact query results, scope checks, counts, empty results, pagination, and metadata association. No schema migration or database grant change was needed.

## Feature qualification matrix

Each row identifies exercised functionality and its principal evidence. HTTP suites use a disposable database, synthetic users and recipients, and local provider adapters. Source review focused on route permissions, validation, scope, concurrency versions, bounded retrieval, and worker behavior. This matrix does not claim line or branch coverage of every helper.

| Feature area | Functions exercised | Principal evidence / result |
|---|---|---|
| Workspace and navigation | Navigation, search categories/actions, desktop/mobile shell, theme controls, keyboard dismissal, page-size persistence | Navigation, shell, pagination browser suites; search HTTP; passed |
| Home | Growth/engagement windows, comparisons, campaign shortcuts, empty/populated data | Analytics PHP/HTTP and Home browser assertions; passed |
| Connections | Create/edit profiles, custom fields, notes, consent history, status changes, archive/restore/delete, duplicates, scoped counts | Main HTTP, storage, Stage 1b, protected-action suites; passed |
| Tags, groups, fields | Creation/editing, memberships, ordering, counts, deletion authorization, Admin Unlock, stale versions | Stage 1b, protected-action and management browser suites; passed |
| Segmentation | Nested all/any rules, typed fields, groups/tags, engagement filters, saved segments, exclusions, preview | Filters, engagement, dispatch, search and segment browser tests; passed |
| Imports | CSV/TXT/TSV/XLSX parsing, mappings, duplicate/blank-field handling, consent and opt-out protection, resumable jobs and undo | Stage 1b and main HTTP import wizard/worker; passed |
| Exports | Selection snapshots, CSV formula protection, Mailchimp archive, error downloads, export permissions/history | Export PHP/integration and main HTTP; passed |
| Campaign library | Create, edit, replicate, folders, search/filter/sort, selection, archive/restore/delete and permissions | Main HTTP plus 46 lifecycle checks and 36 browser layouts; passed |
| Campaign checklist | Sender/subject/target/content, recipient preview, personalization, exclusions, tracking defaults and resend controls | Completion, dispatch and checklist/recipient-preview browser suites; passed |
| Designer | Blocks, rich text, links, merge tags, colors, image positioning/wrapping, previews, history/revisions and comments | Design/advanced PHP, HTTP, designer-model JS, real-browser editor and preview fixtures; passed |
| Templates | Starters, folders, bulk moves, previews/switching, import/export round trip, permissions and archive lifecycle | Template HTTP/transfer and browser suites; passed |
| Planner | Plans, agenda/calendar, touchpoints, linked emails, archive/restore/delete, permissions | 53 HTTP lifecycle/security checks and 290 browser checks; passed |
| Content Studio | Uploads, folders, original-image preservation, resizing, transparent PNGs, animation preservation/rejection, crop/zoom, library previews | Content HTTP, image PHP/JS and browser fixtures; passed |
| Brand Kit | Logos, secondary-logo dimensions, fonts/fallbacks, colors, previews and save indicators | Brand/logo PHP and browser suites; passed |
| Forms and landing pages | Create/edit, settings, preview, publish/pause, results, revision scope and archive lifecycle | Collection HTTP, 56 lifecycle checks, customization/embedded-form tests; passed |
| Surveys and preferences | Device preview, preferences, image picker, responses, private-link tagging and exports | Collection HTTP and survey/image browser fixtures; passed |
| Embedded/pop-up forms | Origin/path/device rules, delay/scroll/exit triggers, dismissal/frequency, storage failures, embedding/resizing | Popup/embed JavaScript and embedded-form PHP tests; passed in fixtures |
| Automations | Triggers, graph validation, waits/delays, branches, audience actions, email nodes, re-entry, execution, pause and lifecycle | Flow PHP (128 checks), HTTP execution, lifecycle/unlock and worker-role tests; passed |
| Inbox | Sources, conversation filters/labels, explicit selection, read/unread, Trash, private drafts, templates, compose/reply and attachments | 80 main Inbox checks, pagination, gateway and restricted worker suites; passed |
| Notifications | Audience settings, branded HTML/plain mail, unread/poll behavior, worker permissions | Settings HTTP, notification JS, Inbox HTML and worker tests; passed |
| Scheduling and dispatch | DST gaps/repeats, timezone boundaries, snapshots, suppression, quotas, throttling, provider errors, ambiguity/no replay | Scheduling PHP, 125 dispatch HTTP checks and restricted SES worker suite; passed with adapters |
| Delivery setup and events | Connection validation, readiness freshness, signed SNS events, receipt status, permission separation and credential rotation | Setup, signed-event, worker and key-rotation suites; passed with local adapters |
| Public mail actions | Confirmation, preferences, unsubscribe, tracking, suppression, token validation and idempotency | Delivery completion, collection, dispatch and signed-event HTTP suites; passed |
| Analytics and reports | Windows, comparisons, delivered/open/click/failure semantics, bot filtering, drilldowns, saved/custom reports, CSV exports | Analytics PHP and 129 HTTP/storage checks; passed |
| AI assistance | Evidence/query scope, validation, permissions, clarification, cancellation, expiration, late answers and terminal UI recovery | AI PHP, 128 main HTTP checks, separate terminal integration and lifecycle JS; passed with fixtures |
| Manuals | Online guide sections, role-sensitive links, comprehensive-guide references | 428 manual checks and HTTP rendering; passed |
| Cross-cutting controls | CSRF, admin/editor/reviewer behavior, audience isolation, optimistic concurrency, protected deletion and restricted worker identities | Main and supplemental HTTP suites plus worker tests; passed |

## Performance evidence

| Query path | Original SELECTs | Revised SELECTs |
|---|---:|---:|
| Memberships for 10 contacts | 20 | 2 |
| Memberships for 100 contacts | 200 | 2 |
| Group catalog with 20 categories | 21 | 2 |
| Conversation with 10 messages | 23 | 5 |
| Conversation with 100 messages | 202 | 5 |

The conversation fixture has no linked contact; a linked contact adds one fixed lookup, within the six-query budget. Tests enforce query counts and correct output, not unstable wall-clock thresholds.

The separate authenticated HTTP benchmark added **5,000 non-subscribed synthetic contacts**, warmed each route once, and made five measured requests to each of **25 workspaces**. All **156 checks** passed, including exactly 100 rendered contact rows per requested page. Local median responses ranged from **3.23 to 26.25 ms**; the slowest measured request was **27.33 ms**. Representative medians: Connections 21.95 ms, Home 23.14 ms, Templates 26.25 ms, Brand Kit 24.40 ms, Analytics 10.56 ms. These are sequential loopback server-response measurements on a shared development machine. Other workspaces in this benchmark have their fresh-database content, so these results do not establish large campaign/event/mailbox performance, browser paint time, network latency, or concurrent throughput.

The continuous Inbox conversation list still retrieves all matching threads by design. Existing tests exercise 70 threads and preserve the requested continuous-list behavior. Very large mailboxes remain an explicit scaling limit to measure before claiming production capacity; changing that interaction was outside this correction.

## Verification and reproducibility

- 35 local PHP unit/feature suites passed; Stage 1b also completed its storage path in Docker. Four separately guarded worker suites ran in the disposable environment, not counted as local passes.
- Main HTTP suite: **1,332 checks**, plus campaign/form/flow lifecycle, terminal AI, pagination, protected actions, export, planner, template transfer, storage and worker suites.
- Performance: **386 query/output checks** plus **156 workspace HTTP checks**.
- **92 JavaScript tests**, **24 browser suite entry points**, and all **four PHPStan configurations** passed. The new editor runner passed 169 assertions in each of Chromium and WebKit (338 total). Browser suites include desktop/mobile, light/dark and role cases where specified.
- Python integration-environment tests (7), CI workflow contract, changed PHP syntax, Python/shell syntax and diff whitespace passed.
- Used existing local runtime images with current checkout source/tests/migrations mounted read-only. This is source qualification, not an immutable packaged-release-image check. Disposable project containers/volumes were removed by the runner.

Run the full MessageMonkey database suite:

```sh
python3 scripts/integration_environment.py audience
```

Run just the query regression and 5,000-contact workspace benchmark:

```sh
python3 scripts/integration_environment.py audience-performance
```

The runner builds isolated images by default. Existing compatible local images can be selected with its established `DNR_TEST_APP_IMAGE`, `DNR_TEST_INGRESS_IMAGE` and `DNR_TEST_DATABASE_IMAGE` variables. The scripts allocate marked disposable projects and fresh secrets; do not redirect them to development or production databases.

Browser entry points use `DNR_PLAYWRIGHT_MODULE` and `PLAYWRIGHT_BROWSERS_PATH` when Playwright is installed outside this repository. Run `node tests/browser/audience-editor-fixtures.test.cjs` for the new cross-engine fixture runner, and `node --test tests/js/audience*.test.js` for JavaScript behavior checks.

Detailed per-suite results, query measurements and every workspace timing are saved in [qualification.json](review-assets/message-monkey-2026-10-26/qualification.json).

## Live qualification limits

No production deployment, production data change, external email, live AI-provider inference, or external website installation occurred. Live SES acceptance/inbox placement, an actual inbound reply, email-client rendering, real provider throttles/outages, external embedded forms, concurrent load, long-running automation/soak behavior and an actual DST transition remain outside this run. Prior reports contain older live evidence; it was not treated as a fresh test of this branch. Release-time image qualification and account parity still apply before deployment.
