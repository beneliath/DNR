# MessageMonkey automation and transactional mapping

Reference inspected read-only on October 19, 2026. This feature pass is local
development on `codex/message-monkey-automation-mapping`; it is not deployed.
Mailchimp flows, drafts, integrations, billing and sending settings were not changed.
The user selected MOED system emails and delivery logs as the Transactional scope.

## What the reference actually provides

Mailchimp's Flow Templates is a discovery and planning interface, not merely a
list of example sequences. Its catalog has a visual introduction, recommendations,
search, channel/topic/integration filters, sorting and grouped template cards.
Opening a template shows its purpose, requirements, starting points and a connected
map, followed by related templates. The inspected welcome, audience cleanup and
engagement follow-up previews demonstrate single triggers, converging triggers,
waits, real branches and explicit exits. Templates become editable drafts through
“Use this flow.”

The account's Transactional page currently shows a developer-product introduction
with plan/trial calls to action. Those actions were not taken, so an enabled
Mandrill operations dashboard was not inspected. The official developer contract
describes a separate API/SMTP sending service; that service must not be confused
with an audience automation flow or a campaign delivery dashboard.

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

## Transactional workspace implemented

The native route is `audience.php?view=transactional`, under Automations. It is
administrator-only both in navigation/search and on direct requests. It is a
read-only view of existing account-isolated queues, with a lifecycle graphic,
stored readiness snapshot, source/status/time filters, totals and paginated
activity. Its POST requests are rejected; it has no send, retry or enable action.

| Native source | Existing queue | Transport |
| --- | --- | --- |
| Signup confirmations | `marketing_service_mail`, confirmation kind | Account SES |
| Inbox replies and alerts | `marketing_service_mail`, inbox/inbox-alert kinds | Account SES |
| Audience notifications | `marketing_service_mail`, audience-alert kind | Account SES |
| Account invitation / verification / recovery | `email_outbox` | Existing MOED SMTP |
| Daily task digests | `notification_outbox` | Existing MOED SMTP |
| Engagement correspondence | `engagement_email_deliveries` | Existing MOED SMTP |
| Reimbursement submissions | `reimbursement_email_deliveries` | Existing MOED SMTP |

Test-email jobs and campaign recipients are excluded. Account and digest tables
retain their existing restricted web grants: their unavailable attempt counts
are shown as unavailable, not zero. The implementation reads no message payload,
recipient address, bearer link, credential, SMTP error or provider message ID.
Missing tables are reported as unavailable and excluded from totals. No schema,
grant, credential, infrastructure or production configuration change is needed.

The shared status vocabulary deliberately distinguishes transport acceptance
from delivery: pending/retry map to Queued; SMTP sent maps to Transport accepted;
delivery_uncertain maps to Acceptance uncertain. Acceptance is never presented
as proof of inbox delivery. Existing feature-specific recovery workflows retain
their retry/ambiguity rules.

## Explicit limits and exclusions

| Mailchimp feature | Native decision |
| --- | --- |
| Store onboarding, orders/carts/purchases | Not implied by MOED event/date fields; no native commerce event source exists |
| External app connectors and SMS | Omitted rather than shown as available templates |
| A/B or percentage testing | Excluded by the user; no new palette action and activation rejects legacy percentage steps |
| Public developer sending API / SMTP gateway | Outside the selected system-email/log scope |
| Mandrill merge-template management | Not imported or faked; system content remains owned by its MOED feature; marketing templates remain separate |
| Mandrill API logs, metadata/webhook management and billing | Not claimed or represented with decorative controls |
| Sender authentication / SES readiness | Links to existing admin delivery settings; stored snapshot only, no AWS call or readiness mutation here |
| Actual SES delivery, bounce/complaint and inbox qualification | Still requires the existing separately authorized live qualification; live sending stays gated |

## Qualification

The final run passed 125 unit checks, 956 HTTP/storage checks, the restricted
worker/SES suites, and 84 browser layouts across WebKit, Chromium and Firefox.
No external message was sent; all integration fixtures were disposable.

The unit checks cover every native starter's normalized graph, edge endpoints,
branch separation, timeout routing, both cleanup status triggers, no-op rejection,
invalid source/status input and bounded filters. The disposable HTTP/storage
suite checks real web grants, admin-only access, mutation rejection, draft-only
creation, cleanup consent preservation/reconfirmation, early-click retention,
percentage-step activation refusal and unchanged live-sending policy.

Browser fixtures render the actual PHP templates and built assets with synthetic
data, without account authentication or an email transport. They cover catalog
filter/search/empty states, branching and converging maps, zoom/reset, the live
builder and Transactional at 390px/1440px in light/dark themes. WebKit and Chromium
run in isolated local profiles; Firefox runs in the official Playwright Linux
container with networking disabled because native Firefox automation on macOS 27
has a known profile-access problem. This does not certify native macOS Firefox
or actual email-client rendering.

## Primary references

- [Mailchimp Flow Templates catalog](https://us3.admin.mailchimp.com/customer-journey/explore/) — authenticated, read-only visual reference.
- [Mailchimp Transactional fundamentals](https://mailchimp.com/developer/transactional/docs/fundamentals/) — service boundary and application-driven recipients/events.
- [Transactional outbound email](https://mailchimp.com/developer/transactional/docs/outbound-email/) — sending and message operations.
- [Authentication and delivery](https://mailchimp.com/developer/transactional/docs/authentication-delivery/) — authentication and provider delivery responsibilities.
- [Tags and metadata](https://mailchimp.com/developer/transactional/docs/tags-metadata/) — vendor-specific metadata capabilities, not claimed as native parity.
