# MessageMonkey automation mapping

Reference inspected read-only on October 19, 2026. This feature pass is local
development on `codex/message-monkey-automation-mapping`; it is not deployed.
Mailchimp flows, drafts, integrations, billing and sending settings were not changed.
The user subsequently excluded the Transactional feature entirely. Its native
page, routing, navigation, search entry, styles and queue-query code are removed.
Existing MOED confirmations, system emails and their delivery mechanisms remain
unchanged.

## What the reference actually provides

Mailchimp's Flow Templates is a discovery and planning interface, not merely a
list of example sequences. Its catalog has a visual introduction, recommendations,
search, channel/topic/integration filters, sorting and grouped template cards.
Opening a template shows its purpose, requirements, starting points and a connected
map, followed by related templates. The inspected welcome, audience cleanup and
engagement follow-up previews demonstrate single triggers, converging triggers,
waits, real branches and explicit exits. Templates become editable drafts through
“Use this flow.”

## Catalog and visual mapping implemented

| Reference function or graphic | Native MessageMonkey mapping |
| --- | --- |
| Introduction with a gold flow illustration | Original local SVG illustration of signup, email and completion; opens existing MOED forms |
| Recommended cards | Three curated native starters, labelled Recommended; no invented popularity or performance claims |
| Full catalog grouped by objective | Welcome, nurture, re-engage, and support/manage sections; 17 starters |
| Search and independent filters | Name/description/topic/integration search; Email or audience-actions-only; native topics; audience/forms integrations |
| Sort and result state | Recommended or name; unique result count, hidden empty sections, clear controls and an explicit empty state |
| Integration/channel coins | Local vector email, audience and MOED forms icons; no inactive third-party logos |
| Larger recommended / compact general cards | Three-column recommendations and four-column catalog, adapting to one column on phones |
| Purpose and requirements preview | Actual trigger labels, summary, setup requirements, entry/exit qualifications and objective |
| Connected template map | Derived from the same normalized document that creates the draft; not a hand-written illustration |
| Multiple starting points | Each trigger has its own node and connector, including in the live builder |
| Branches, timing and exits | Yes/No and trigger/timeout edges, actual delay lengths, and separate exit paths |
| Graphical node vocabulary | Amber starting points, teal actions, purple logic/waits, gray exits, shared local SVG icons |
| Canvas navigation | Scrollable map, keyboard-accessible viewport, zoom percentage and reset; full node text in SVG titles |
| Continue from template | Existing CSRF/versioned draft creation, editable settings, validation and activation gates |
| Back to catalog | Filters and search preserved in the URL and preview back links |
| Related templates | Native starters below the preview, reusing the same card presentation |

## Native starter semantics

| Starter | Created graph / source of truth |
| --- | --- |
| Welcome new connections | Subscribed signup → welcome email |
| Welcome series | Signup → email → 1 day → email → 3 days → email |
| Email tagged connections | Newly added selected tag → email |
| Celebrate a birthday | Selected birthday/date field and local time → email; annual reentry enabled |
| Celebrate signup anniversaries | Creation date in this MessageMonkey audience → annual email; does not invent a historic vendor signup date |
| Thank connections for answering a survey | Verified recipient response → 1 day → email |
| Identify connections who click | Observed click, recognized bots excluded → selected tag |
| Guide connections with If/Else conditions | Selected tag → configured audience rules → separate Yes/No emails → exit; only one branch runs |
| Welcome new group members | Joins selected audience group → email |
| Organize new subscribers | Subscribed signup → selected tag → email |
| Follow up when an audience field changes | Actual profile change, not a no-op save → email |
| Remind connections about a date | Selected audience date field → email one day before at the chosen local time |
| Follow up when a welcome email gets no click | Signup → welcome email → click wait up to 3 days; click exits, timeout sends one reminder |
| Ask why a connection left a group | Leaves selected group → email only while subscription permission remains valid |
| Continue after another flow | Selected native flow completes → 1 day → email |
| Identify connections who open | Observed open → selected tag; privacy-proxy limitations disclosed |
| Archive unsubscribed or cleaned connections | Two status-change triggers converge on archive; current-status entry/exit filters preserve later reconfirmations |

The click-wait must reference the welcome email selected in the first step.
An immediately following open/click wait on that email retains observations from
when it was queued, so fast engagement between acceptance and worker advancement
is not lost. Other wait triggers retain their existing observation semantics.
The native follow-up is not an autonomous “has not engaged” starting trigger for
an arbitrary past campaign. That broader negative-engagement entry source remains
unimplemented.

All email steps retain subscribed-recipient checks, suppression, immutable
published snapshots and the existing delivery qualification. Opening a preview
or creating a draft never activates a flow. Data-only cleanup requires explicit
activation and neither sends email nor changes consent.

## Upper-level Automations page

The Mailchimp Automation flows empty state and its populated Classic Automations
list were inspected read-only. Its main overview includes an introduction/video
tile, a five-task onboarding panel, recommendations and three learning cards.
The native page now maps those functions as follows:

| Reference element | MessageMonkey implementation |
| --- | --- |
| Automation flows / Classic Automations tabs | Separate native and converted-classic lists, with the selected-tab underline/background |
| Main header actions | Build from scratch and Choose flow template, preserving draft-only creation |
| Introduction and preview tile | Two-column introduction with original vector artwork that opens the actual welcome-template preview; no pretend video player |
| Get started with automation flows | Five expanding tasks: welcome draft, email readiness, hosted signup, audience organization and template discovery |
| Setup progress | Account-record-derived completed states, progress bar, completed/dismissed counts, focused next task, Show all/Show fewer, dismiss and restore controls |
| Task illustrations | Distinct local SVG scenes for the welcome graph, delivery, hosted form, interest tags and template grid |
| Setup actions | Working links to native editors/previews; non-admin delivery guidance does not link to protected configuration |
| Dismissal preferences | Browser-local state scoped by account, user and audience; no checklist action enables a flow or changes sending policy |
| Recommendations | Three native templates with channel/integration coins, descriptions and a route to the full catalog |
| Getting-started cards | Three linked native guide sections covering first-flow creation, logic/timing and pre-activation review |
| Populated list | Search, native status filters, Clear all, Last created/Last updated/Oldest sorting, status badges, dates, draft/actual run summaries and row actions |
| Flow actions | Edit/view, report, replicate, pause/edit, contextual removal review and restore as draft, using existing guarded backend operations |
| Pagination and empty states | Result ranges plus first/previous/next/last controls, preserved filters and useful empty/Trash states |

The large introduction/recommendations appear when there are no native flows.
Accounts with flows keep the management list prominent and can expand the same
setup checklist below it. Classic conversion guidance is a disclosure rather
than an unrelated default panel. Converted classic flows use the same native
engine and remain identified honestly; vendor runtime queues are not imported.

Published signup state, welcome-flow existence, audience tags/groups and stored
email readiness determine account-backed task completion. Template discovery and
dismissals are browser preferences. A dismissed delivery task still cannot bypass
SES approval, live-sending qualification, permission checks or suppression.

## Explicit limits and exclusions

| Mailchimp feature | Native decision |
| --- | --- |
| Store onboarding, orders/carts/purchases | Not implied by MOED event/date fields; no native commerce event source exists |
| External app connectors and SMS | Omitted rather than shown as available templates |
| A/B or percentage testing | Excluded by the user; no new palette action and activation rejects legacy percentage steps |
| Transactional page or developer sending API / SMTP gateway | Excluded by user request; no native page or service |
| Mandrill merge-template management | Not imported or faked; system content remains owned by its MOED feature; marketing templates remain separate |
| Mandrill API logs, metadata/webhook management and billing | Not claimed or represented with decorative controls |
| Sender authentication / SES readiness | Links to existing admin delivery settings; stored snapshot only, no AWS call or readiness mutation here |
| Actual SES delivery, bounce/complaint and inbox qualification | Still requires the existing separately authorized live qualification; live sending stays gated |

## Qualification

The updated qualification includes 128 unit checks, 956 HTTP/storage checks and
the restricted worker/SES suites. Browser checks cover 44 layouts per engine
across WebKit, Chromium and Firefox.
No external message was sent; all integration fixtures were disposable.

The unit checks cover every native starter's normalized graph, edge endpoints,
branch separation, timeout routing, both cleanup status triggers, no-op rejection,
typed overview status/sort input and the five setup-task contracts. The disposable HTTP/storage
suite checks authorized overview access, independent Classic/native filtering,
oldest-first sorting, draft-only creation, cleanup consent preservation/reconfirmation, early-click retention,
percentage-step activation refusal and unchanged live-sending policy.

Browser fixtures render the actual PHP templates and built assets with synthetic
data, without account authentication or an email transport. They cover catalog
filter/search/empty states, branching and converging maps, zoom/reset, the live
builder, empty/populated/filtered/classic/Trash overviews, and checklist
expand/dismiss/restore/guide interactions at 390px/1440px in light/dark themes. WebKit and Chromium
run in isolated local profiles; Firefox runs in the official Playwright Linux
container with networking disabled because native Firefox automation on macOS 27
has a known profile-access problem. This does not certify native macOS Firefox
or actual email-client rendering.

## Primary references

- [Mailchimp Flow Templates catalog](https://us3.admin.mailchimp.com/customer-journey/explore/) — authenticated, read-only visual reference.
