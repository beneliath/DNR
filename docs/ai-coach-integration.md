# AI Coach local integration

AI Coach is an optional teaching panel on signed-in MOED pages, with a prominent
entry in `help.php`. The local preview uses native Ollama on shunbun
(`192.168.1.31`). The 2.3.0 release supports the same native service from s1 through a separately authorized production SSH identity.

## Interaction and reliability

The label is `ai coach`. On desktop, the panel reserves a right gutter and
collapses to an edge button. On narrow screens it becomes a dialog. Enter sends
a question; Shift+Enter adds a newline. Messages are chronological. Repeated follow-up questions are suppressed in both new answers and restored tab history. Each walkthrough
is embedded in the assistant reply that introduced it. A new question ends the
active walkthrough immediately; its old card stays in place with a Resume action.
Resuming introduces a fresh exchange at the end. A new exchange is brought into view; Show
focuses/highlights the application control without moving the conversation.

Eight verified interactive workflows cover creating an engagement, attaching a
PowerPoint, attaching speaker-notes PDF, setting a task to Waiting, adding a task,
assigning a task, changing event dates, and subscribing to a personal calendar.
Common phrases use immediate application-owned guidance. Nineteen source-checked
procedures also provide numbered instructions for task completion/archive/restore,
record linking and task notes, inquiry conversion, record restoration, file removal,
new organizations/contacts, and event Chron entries. Four of these procedures supply
the newer interactive guides from the same shared definitions. Event creation opens New Engagement (`index.php`) before asking
for Organization. Attachment workflows require the Presentations tab before
Edit Presentations. Local DOM state determines each next step: required fields,
selected tabs, file selection, validation errors, and task status. The coach
never selects business values, submits a form, or treats a selected file as saved.
Other tasks receive conversational explanations from documented evidence;
these do not yet all have interactive highlighting.

Qwen3:8b has **not been fine-tuned on MOED**. The earlier sentence-selection
implementation was too restrictive and depended on weak keyword matches. The
current pipeline:

1. Extracts text and numbered walkthroughs from the actual 149-page Comprehensive
   Manual PDF into 315 indexed topics, with PDF page citations and a content hash.
   It does not use the abbreviated `help.php` text as its knowledge source.
2. Resolves brief follow-ups using the previous step/conversation, then searches across
   chapters with BM25-style ranking, title weighting, and domain aliases. It does not make
   a separate routing-model call or substitute arbitrary sources for an empty match.
   Broad purpose/features questions use introductory PDF sections. Editors/reviewers do
   not receive operator-appendix topics. New questions take precedence over old topics.
3. Provides the selected full topic text and source-derived application labels
   for a natural, conversational answer. The static application inventory covers
   64 page files; it is evidence about labels, not proof of runtime visibility.
   Verified navigation rules address transitions missing from manual excerpts.
4. Keeps normal coaching concise, with native thinking disabled, an 8,192-token
   context, bounded output and a 30-minute model keep-alive. Extra native thinking
   can be explicitly enabled with `DNR_AI_COACH_REASONING=1`; it substantially
   increases latency. A walkthrough requires a positively identified action and object;
   adding a task to an event cannot start new-event creation. The model cannot supply tool
   calls, actionable selectors, or arbitrary links; source links and walkthrough
   controls come from the application. Its prose can still be wrong, so regression
   evaluation and browser checks remain necessary.

This is retrieval plus application workflow logic, not automatic training or a
claim of expert accuracy across every task. Maintainers should develop coverage
from the manual, source, and tests; users do not need to maintain grounding.

Rebuild the indexes when their sources change (the PDF script requires pypdf):

```sh
python3 scripts/ai_help/index_comprehensive_manual.py
python3 scripts/ai_help/index_application.py
```

The server rejects a PDF index whose hash differs from the installed PDF. It
omits static page labels when their source hash is stale. Helper tests verify
PDF freshness, numbered workflow text, citations, and important page controls.

## Request lifetime and review history

The same-origin `ai_coach.php` endpoint requires normal login/MFA, the feature
flag, POST, and CSRF validation. The authenticated role overrides client claims.
Questions, history, page and step are bounded and allowlisted. Field contents,
record IDs, filenames and uploads are not collected for model requests; users
can still type sensitive information into a question themselves.

A browser-generated request UUID is recorded before inference. The browser
preserves the pending request across navigation, and owner/role-scoped GET
status requests recover the completed answer on the destination page. A unique
(user, UUID) key prevents repeated inference when a navigation retry reaches the
server. The PHP session lock is released before model work; navigation remains
available. `keepalive` protects a POST during page unload. Abandoned requests are
resolved against a 20-second server budget, including queue time. Stop answer cancels
queued work and aborts the worker HTTP request for running inference. Completed answers
remain recoverable after long navigation or Back/Forward restoration. A 25-second client
recovery window bounds repeated polling when the server cannot be reached; each status
fetch has a five-second timeout. This is not an absolute wall-clock guarantee during
network or browser suspension. Logout or a changed session/role
clears the tab's coach state.

The application admits up to eight unfinished durable jobs. Two dedicated PHP workers
claim them with row locks and release those locks before inference; no page-serving
process stays occupied generating a queued answer. Duplicate UUIDs cannot start extra
inference. Claim tokens and completion guards prevent cancelled/expired jobs from
overwriting their final result. Worker failures leave jobs to expire within their budget.
The reviewed system-service configuration uses two parallel request slots, one
loaded model, and a maximum native queue of eight. Additional demand can still
hit capacity/time limits. The coach browser accepts one pending question per
conversation; separate users/tabs can submit concurrently.

The `ai_coach_requests` table records accepted questions (including starter-button
requests), bounded conversation context, original answer, outcome, duration,
model/version and a guidance revision hash. Rate-limited requests are also
recorded. Invalid/unauthenticated requests are rejected before logging. Earlier
browser-only conversations are not retroactively imported.

Guide-catalog questions are answered from the maintained workflow definitions
without a model call. Failure replies also include the available walkthrough choices.
Known service-failure messages are excluded from model history, and a model that
copies one into a new answer is rejected. This prevents an earlier outage from
becoming a misleading answer to a later question.

New generation failures record a bounded code (`connection`, `timeout`,
`service_error`, or `invalid_response`), the failing stage (`answer`, `worker`, or `queue-or-answer`),
and applicable HTTP/cURL status codes. Diagnostics contain no raw prompt or model
response. The administrator list displays these causes separately. Historical
responses that copied the old failure message are displayed as **Invalid answer**;
their original stored response and outcome are preserved.

Administrators can read and annotate history at `ai_coach_requests.php`, mark
answers Useful or Needs work, and record corrected guidance. CSRF and optimistic
version checks protect review writes. Corrections never alter the original answer
and are not automatically used as training data or runtime instructions. History
is retained until an administrator uses **Clear request log**. This action first
shows a confirmation with the count and scope, then permanently removes those
requests, answers, and reviews across all users and filters. CSRF and an expiring,
single-use, session-bound confirmation protect deletion. A timestamp/ID boundary
preserves requests arriving or completing after confirmation opens. Active
requests remain available for navigation recovery; abandoned pending entries older
than 25 seconds can be cleared. IDs are never reset, so late workers cannot update
a newly created row. Cancel invalidates confirmation. Open browser conversations
and the model are unchanged. Reviewed improvement cases are retained separately; there
is no automatic operational-log export or deletion schedule. Apply all three 20260922
coach migrations through the normal migration runner. Its grants include job CRUD
and SELECT/INSERT/UPDATE on the retained improvement table.

## Local connection

`docker-compose.ai-coach.yaml` enables the feature and adds an SSH sidecar. The
web container reaches the sidecar only on an internal Docker network. The
sidecar forwards to shunbun's loopback-only Ollama service at port 11437; no
model port is published to the browser, host, or LAN. Ollama requests use
`Host: localhost`, matching the remote loopback service.

The overlay expects these local files, which must remain untracked:

- `secrets/ai_coach_ssh_key`: dedicated SSH key, mode 0600.
- `secrets/ai_coach_known_hosts`: independently verified shunbun host key.

The public key installed for the local preview denies command execution and
restricts local-forward destinations to `127.0.0.1:11437`. It is separate from the
operator's personal SSH key. The sidecar copies its read-only mounted key to a
private tmpfs directory before dropping to UID/GID 10001 and starting SSH. It
uses strict host-key verification and reconnects through its container restart
policy. Five-second SSH probes with two missed replies detect a broken connection
in about ten seconds, instead of the previous 45-second wait. The transport subnet defaults to `10.253.31.0/28` to avoid Docker's
previous automatic allocation overlapping the physical LAN; override
`DNR_AI_COACH_TRANSPORT_SUBNET` if that subnet is already in use.

The September 22 local disconnects were traced to disposable integration-test
networks. Their backend had an explicit subnet, but their ingress used Docker's
automatic allocation, which had reached `192.168.0.0/20`. That range includes
shunbun's physical LAN address, `192.168.1.31`. Docker Desktop added that route
at 16:52:35 and 17:03:25 CDT; the SSH connection timed out immediately afterward.
Removing each test network removed the conflicting route and restored access.
The native Ollama service and its idle-sleep assertion stayed running.

`scripts/integration_environment.py` now assigns all test networks (backend,
ingress, egress, and default) explicit `/24` subnets from `10.252.0.0/16`. It checks
real CIDR overlaps against existing Docker networks, including larger and smaller
subnets, and fails if that pool is exhausted rather than using automatic allocation.
The test pool must also remain separate from any physical LAN or VPN used by the
test host. Do not create ad-hoc test networks using Docker's automatic pool on this
host: other projects can still allocate a conflicting `192.168.x.x` subnet.

When investigating connectivity, correlate SSH timeouts with Docker Desktop's
`com.docker.backend.gvisor` route additions/removals and inspect **all** Docker
bridge subnets, not just the tunnel's network. Increasing the model timeout or
restarting Ollama cannot fix a route that diverts traffic away from the Mac mini.

Verification after the allocator fix: two complete disposable coach HTTP-test
lifecycles passed while 90 live MOED-to-Ollama version checks over 183 seconds
had zero failures. The existing SSH tunnel did not restart. Real model answers
completed in 4.95 seconds (application overview) and 7.09 seconds (task/event
relationship, including model routing). Allocator regression tests cover every
test bridge, overlapping CIDR ranges, Docker's subnet-less host network, and pool
exhaustion. This validates the previously failing local-test lifecycle; eventual
s1 connectivity and reboot/unlock recovery still require their own checks.

Use the same base Compose files and application image as the existing local
preview, appending the coach overlay. For the current development setup:

```sh
DNR_APP_IMAGE=dnr-app:local docker compose -p dnr \
  -f docker-compose.yaml -f docker-compose.dev.yaml \
  -f var/deployment/ppt-local.compose.yaml -f docker-compose.ai-coach.yaml \
  up -d --no-deps web ai-coach-tunnel ai-coach-worker
```

For source-bind development, restart `ai-coach-worker` after changing PHP guidance;
long-running workers retain loaded PHP functions. Image-based deployments recreate
workers with the new application image.

The feature defaults off without the overlay. The provenance wrapper automatically adds the overlay and coach profile when both SSH secret files are provisioned; deployments use the qualified application image for the tunnel as well as its two workers. For direct Compose use, build the current application image first so it includes the tunnel entrypoint. To disable it, remove the opt-in key from the configured location and recreate only the
web service using its original Compose files and image, then stop/remove the
coach workers and sidecar. Do not remove database volumes. The remote system LaunchDaemon
can remain available for other authorized testing.

## Startup and validation

Shunbun's `com.moed.ollama` LaunchDaemon runs under `_moedollama`. A real reboot
and GPU inference with no desktop user logged in were verified on September 22,
2026. FileVault remains enabled; the user accepted remote disk unlock as the
required restart step. Automatic recovery from a locked disk without human
intervention is not promised. Testing the eventual s1-to-shunbun connection and
its recovery remains part of any future production rollout.

Validation for the local integration includes PHP helper tests, JavaScript
workflow tests, minified-asset checks, and the disposable HTTP integration suite:

```sh
php tests/ai_coach_helpers_test.php
node --test tests/js/ai-coach.test.js tests/js/user-manual.test.js
php tests/minified_assets_test.php
python3 scripts/integration_environment.py coach
```

The HTTP suite creates its own temporary database and containers. It checks
login, CSRF, limits, role refresh, shared page availability, offline help,
request recording, administrator review and confirmed log clearing, recovery of pending/completed requests,
idempotent replay, and cross-owner/current-role access protection. It does not
modify the preview database. Browser checks cover exact form targets, tab order,
chronological messages, scroll preservation, Enter submission, request recovery
after immediate navigation, light/dark styling, and the mobile dialog. Test drafts
are canceled without creating events or uploading attachments.

Run `scripts/ai_help/evaluate_coach.php --live` inside the configured web container
(with `DNR_COACH_SOURCE_DIR=/var/www/html` when piping the script) for a small
read-only model evaluation. It records final answers and timing, never private
reasoning text. Route checks are a regression sample, not a full accuracy score;
review the actual answers as well. Native parallelism is checked by observing
both streamed answers begin before either finishes. Production rollout, realistic
multi-user load, and comprehensive screen-reader testing remain separate work.

## Updating native concurrency

`scripts/ai_help/enable-shunbun-parallel.sh` changes only the existing MOED
LaunchDaemon's parallel count and reloads it. It requires administrator
execution on shunbun. It validates the service identity/binding, saves a backup,
waits for asynchronous launchd removal, retries bootstrap, and checks loopback
health. It attempts to restore the previous configuration if startup fails.
The first version retried bootstrap before launchd finished removing the job;
the corrected script recovered the service. FileVault, SSH policy, service
identity and the separate user Ollama instance are preserved.

## Response-time pilot (September 22, 2026)

The preview now uses Qwen3:8b. With focused chapter retrieval, bounded answers,
ordinary thinking disabled, and the model kept warm, seven public regression
questions completed in 2.4–9.5 seconds. The same 14B sample ranged up to 23.4
seconds. The user’s functions-of-MOED question took 7.7 seconds in the 8B sample,
including model loading. All seven 8B workflow/citation checks passed after
adding workflow guards; this is not an accuracy score. See
[the public regression record](ai-coach-evaluation.json) for answers and limits.
The 15–20-second response goal is not a hard bound under queuing or outages.


## Improvement loop and regression gates

The feedback intake and daily development review are now implemented. See
[the review procedure](ai-coach-feedback-review.md) and
[the latest review and test results](ai-coach-feedback-review-results-2026-09-22.md).
Apply `20260922_add_ai_coach_feedback_review.sql` after the preceding coach
migrations. This adds feedback versions and retained investigation receipts;
the migration runner updates the application's restricted grants.

In Request History, open an answer and choose **Create improvement case**. The
request detail now includes user feedback, sanitized visible-control/tab state,
retrieved topic IDs and scores, model call count, queue delay, and Ollama load,
prompt-evaluation, generation, and token metrics. Feedback buttons and missing-control
reports are owner/role scoped and CSRF protected.

`ai_coach_improvements.php` retains draft and approved cases independently of the
operational log. Verify behavior against the application and Comprehensive Manual,
remove private record details, set expected sources and required/forbidden phrases,
and approve. Required phrases must also occur in the reference answer. Concurrent
edits are version checked. An approved exact-match explanation can be reused for the
same question, role, page, and step, only while its guidance revision is current.
Server-owned permission and critical-action guards run first. Manual, prompt, source
procedure, application-map, and client guidance changes invalidate approved reuse.
Revalidate a case explicitly after a change; do not approve the model's own assertions
as application truth.

Export approved cases for repeatable evaluation. The export excludes operational
request links, usernames, and conversation history; the case question and reference
answer are included, so the approval redaction check matters. Clearing Request History
preserves these cases and nulls their deleted source-request references.

The first documentation iteration corrected PDF page 23: open Engagements, choose
+ New Engagement, and select Organization inside the form. The installed PDF and
retrieval index were rebuilt and visually checked. Maintainers own this work; end users
are not expected to supply MOED's rules to the model.

```sh
php tests/ai_coach_helpers_test.php
php tests/ai_coach_regression_test.php
node --test tests/js/ai-coach.test.js tests/js/ai-coach-lifecycle.test.js
php scripts/ai_help/regression.php
# Against the configured local Ollama connection, using a reviewed export:
php scripts/ai_help/regression.php --live --cases=/path/to/coach-reviewed-cases.json
```

The built-in 108 cases are developer regression cases, including role and context
variants, not an untouched held-out accuracy benchmark. Offline runs check routing;
live runs additionally check answers, expected evidence/phrases, failures, and elapsed
time. These checks do not establish full factual correctness. Inspect actual next-step
usefulness and unsupported claims. The normal disposable integration runner now enables
coach HTTP tests, queue/cancellation checks, and real worker processes instead of
silently skipping coach coverage. Do not run database test fixtures against live data.

The 19 procedural definitions in `src/data/ai-coach-procedures.json` include manual
sources and source-file fingerprints. A changed control file disables the corresponding
procedure until it is reverified and the fingerprint is updated. Tests fail on missing or
stale procedures. This is a source-verified catalog; it is not a claim that all 20 tasks
have been exercised through every browser, role, and record state.

The implementation benchmark and its limits are recorded in
[ai-coach-improvement-results.json](ai-coach-improvement-results.json). Qwen3:8b remains
the selected model. Streaming and an embedding model have not been introduced: ordinary
answers use one model call, and verified procedures use none. A separate held-out quality
review and cold-start/browser latency measurements remain necessary before a production
release or an expert-accuracy claim.


## Intent and form evidence refresh (September 22)

The answer call classifies explanation, action, troubleshooting, or ambiguity and
can select a permission-filtered procedure. Clear requests still use a zero-model
path. Compound requests bypass that shortcut so their sequence is not reduced to
one matching operation. Low-confidence procedure selections ask for clarification.
The configured model remains Qwen3:8b; no extra routing call is required.

`src/data/ai-coach-forms.json` records reviewed entry points, field labels, visibility
conditions, and source hashes. It supplies both model evidence and the browser's
highlight targets. Stale form contracts are excluded. The browser sends control
identifiers and visibility, never field contents or private calendar links. Missing
controls produce an explicit mismatch rather than an instruction to click them.

The shared procedure catalog now also defines interactive guides for task creation,
assignment, event-date changes, and personal calendar subscriptions. Their browser
rules follow selected tabs, required fields, validation messages, review acknowledgements,
and post-submit checks. Guides never submit forms or infer successful persistence
from navigation. Reviewers can use their own calendar guide but cannot edit records.


Source-checked form guidance also handles Waiting status diagnostics directly:
Waiting on is required only when the submitted status is Waiting. Leaving Waiting
clears it. Diagnosis asks for the displayed error instead of claiming that an unseen
field caused the failure. This guard was added after the live model repeatedly
misapplied that condition despite having the relevant prose.
