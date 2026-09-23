# Coach improvement cycle and review — September 22, 2026

The local preview now has a working feedback-to-development loop and a daily
9:00 a.m. local-time Codex heartbeat attached to this task. The heartbeat is active
as `improve-moed-coach-from-feedback`. It works on the existing local branch;
production deployment remains separate. The host and preview must be available.

The queue automatically collects ratings, missing controls, failures, answers over
15 seconds, and detected question echoes. It groups related evidence, preserves
feedback-time page/step/target and administrator notes, and records versioned
investigation receipts. A changed rating or admin assessment reopens a request.
Helpful votes do not certify correctness. The model cannot publish its own answer
as training truth. See [the operating procedure](ai-coach-feedback-review.md).

## Defects corrected in this pass

The independent Bugbot review found five defects; each was addressed:

- Independent keywords sent task-note edits, contact affiliations, and completed
  tasks to the wrong operation. Action/object matching is now more conservative;
  competing procedures and unrelated record-field edits fall through for interpretation.
- Leaving Waiting could start a Waiting walkthrough. Stop/finish intent now takes
  precedence, while work blocked until a reply starts the Waiting guidance.
- Missing-control feedback lost the current failure page, walkthrough step, and
  target after navigation. Validated feedback-time context is retained separately.
- The development handoff omitted administrator corrections. They are now included
  as untrusted proposals to verify against source, rather than accepted facts.
- A stalled initial POST could leave the composer busy indefinitely. Submission
  now has a five-second transport timeout and resumes status polling with the same
  durable request ID, without cancelling server work or duplicating the question.

Additional live questions exposed incorrect event/engagement terminology,
rescheduling instructions, file-size responses, password-change navigation, and
incomplete walkthrough discovery. These paths were corrected. Account Security's
Change Password controls were verified in application source, documented on PDF
page 87, indexed, and added to the source-checked procedure catalog. Speaking-notes
phrasing now starts the actual PDF walkthrough. Missing controls no longer result
in an invented archive/restore prerequisite. A plain question echo is detected,
replaced with an honest scope response, and queued for quality review.

A repeated worker integration test exposed an InnoDB deadlock during expiry and
concurrent job claims. Idempotent database operations now retry that specific
rolled-back error at most twice; inference is never replayed by the retry wrapper.
Other database errors are still surfaced. A restricted-grant issue in initial
receipt insertion was also fixed without widening UPDATE privileges.

## Timing and answer-quality evidence

Twenty-four fresh synthetic conversational probes exercised broad explanations,
terminology, event creation and edits, attachments, roles, missing controls,
history switches, ambiguity, harmless off-topic conversation, and an instruction
to contradict the manual. The original sample's median was 2.192 seconds, p95
4.550 seconds, and maximum 5.320 seconds. The post-workflow/manual sample used nine
model calls rather than fifteen; its maximum was 3.149 seconds and p95 3.041 seconds.
Fifteen responses came directly from verified guidance. Sub-millisecond helper
times do not represent full browser response times.

Four concurrent model requests, with a feedback replay also using inference,
completed in 7.845–18.782 seconds measured by their Docker clients, without a
transport failure. The competing feedback answer took 15.643 seconds. The burst
approached the requested 20-second ceiling: there is limited headroom under this
load. No cold-start, long soak, or production s1 latency claim is made.

Valid response JSON and citations did not establish factual accuracy. Manual
review found serious errors despite all 24 initial transport checks passing. The
post-change sample corrected the identified core task errors, but some replies
remained less helpful than desired: a checkmark concept question gave unnecessary
assignment steps; vague questions got a generic clarification; off-topic responses
varied; and a concurrent task-linking explanation referred to a generic record
dropdown instead of the documented related-record search. These are reasons to
continue improving relevance, not to label the coach an expert system yet.

The [synthetic evaluation record](ai-coach-feedback-evaluation.json) retains exact
answers, timings, and test conditions. No private request packet is committed.

## Verification and limits

- 123 intent/permission/context regressions; actual required/forbidden phrase and
  procedure checks run where expectations are specified.
- 17 JavaScript tests, including stalled submission, Back/Forward restoration,
  late completion, Enter behavior, walkthrough progression, chronological state,
  and stopping a walkthrough when another question is asked.
- Disposable HTTP tests: authentication, CSRF, role changes, feedback, populated
  admin review queue, version conflicts, export, and log clearing.
- Disposable queue and worker tests: bounded admission, cancellation races,
  expiration, concurrent processing, and authenticated asynchronous recovery.
- Feedback tests: repeated votes, changed votes, original/failure contexts,
  administrator evidence, source/test gates, atomic stale-batch rejection,
  automatic failure/latency/quality intake, and retained review receipts.
- PHP static analysis, asset consistency, and seven network allocator checks.
- PDF verifier and visual inspection of revised pages 87–88; 147-page manual
  and 305-topic retrieval index remain consistent.

The fresh Firefox session required sign-in, so live visual/interactive browser
verification remains pending user authentication. Authenticated HTTP and simulated
DOM lifecycle tests passed; they do not replace a full browser walkthrough or
screen-reader assessment. Test fixtures used disposable databases, not preview
business records. Source fingerprints prove a procedure matches reviewed source,
not that every record state has been exercised in a browser.

## Recommended next improvements, in priority order

1. **Improve intent interpretation before changing models.** Separate explanation,
   navigation, edit, creation, troubleshooting, and follow-up requests. Let the
   model choose only among known workflows with a confidence/clarification path;
   keep permission checks and exact control steps owned by the application.
   Test nearby intentions such as adding a note versus adding a task. The current
   keyword rules still have limited language coverage.
2. **Retrieve the actual task and form evidence together.** Pair the relevant PDF
   section with the target form's verified control labels, entry point, permission
   rules, and conditional visibility. Add a small semantic retrieval/reranking
   experiment and compare it against the current lexical baseline before adopting
   it. Avoid a second model call on the normal answer path.
3. **Broaden interactive guidance from real demand.** There are four interactive
   walkthroughs plus seventeen source-checked text procedures. Extend the most
   requested procedures using a shared workflow definition that drives the coach,
   documentation, and UI tests. Preserve user control over every edit/save.
4. **Strengthen evaluation beyond positive feedback and phrase checks.** Keep a
   separate held-out paraphrase set, sample unrated answers, and measure whether
   the suggested next action is actually available for the role/page/state. Track
   unsupported controls, wrong-record edits, unnecessary clarifications, and task
   completion separately from transport success. Use feedback to add regression
   cases, not to turn model guesses into manual text.
5. **Measure browser p95 and cold/concurrent behavior before scaling.** Record
   queue time, first visible feedback, and complete-answer time separately. Stream
   validated answer text if useful, while recognizing that streaming does not make
   generation complete sooner. Maintain bounded queues and explicit cancellation.
   The current 8B model is fast enough for a pilot; a smaller model is not supported
   by these results as the next fix. Compare a stronger model on the same held-out
   tasks and 20-second budget before changing the serving model.
