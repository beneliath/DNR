# ai coach review — September 22, 2026

The feature is worth developing, but its current answers are not dependable enough to be a primary source of workflow guidance. The Mac mini has adequate performance for this pilot. The more urgent issue is correctness: application routing, incomplete page context, weak evidence selection, and model instruction-following all contribute. Neither switching to a smaller model nor turning on more reasoning will address those problems alone.

This was a review and benchmark, not an implementation change. Application code, business records, request history, and service settings were preserved. Production deployment to s1 remains stopped. Detailed inputs, outputs, stage timings, assessment notes, and code findings are in [the review evidence](ai-coach-review-2026-09-22.json).

## What was measured

The current development pipeline uses native Ollama on shunbun, Qwen3 8B, GPU inference, an 8,192-token context, two native request slots, and reasoning disabled. The model was already resident for the main measurements. The Comprehensive Manual index matches the actual PDF's SHA-256: 147 pages and 305 topics. This is retrieval from the manual at question time; the model has not been fine-tuned on MOED.

I ran 27 public synthetic questions through the actual generation function: four existing regression controls and 23 broader probes covering paraphrases, existing-record edits, negation, roles, conversation, unsupported capabilities, and follow-up steps. Six responses were application-generated without an LLM call. The other 21 had a median of **7.66 seconds**, sample nearest-rank p95 of **9.93 seconds**, and maximum of **10.35 seconds**. The user's functions-of-MOED question took **4.33 seconds**. These figures include wrong answers; response time is not a quality score.

Two simultaneous questions completed in **5.38–5.50 seconds**. A separate four-question batch completed in **5.97–10.18 seconds**. Reused prompts had much shorter prompt-evaluation times, consistent with cache reuse. These small warm batches do not establish worst-case capacity. Measurements exclude browser rendering, HTTP authentication, and request-log writes. Cold startup, sustained load, and network failures were not tested here, and the earlier 70-second wait was not reproduced or conclusively explained.

Most general questions use two sequential model calls. The routing call alone had a **3.58-second median**; the answer call had a **4.14-second median**. Prompt processing accounted for substantial time. Both calls explicitly disable streaming. The current 105-second generation deadline and roughly three-minute recovery window also permit waits far beyond the desired 15–20 seconds.

## Why the answers go wrong

**Some failures happen before the model runs.** “How do I add a second day to an existing event?” and “I don't want to create a new event…” both started New Engagement. The keyword fast path treats “add,” “new,” or “start” as evidence of creation without understanding the object or negation. The same model, with more training, would never get a chance to correct that decision. See [the matcher](../src/ai_coach_helpers.php#L189).

**The coach loses conversational state.** Submitting a new question ends the walkthrough and clears its current step before building the request. Ending the guide is the requested behavior; forgetting what the user was looking at is not necessary. A separate keyword filter excludes the PDF workflow for “Can you walk me through adding one?” even when recent conversation clearly identifies speaker notes. The server receives only limited page/step information; it does not receive a general description of the currently selected tab, visible controls, or validation state.

**The knowledge source is comprehensive, but retrieval is shallow.** A model picks up to two chapters, then a simple keyword counter returns four sections. Chapter selection can exclude the right material; a rewritten query can change the intended object. When nothing matches, the code can substitute the first four sections in the selected chapter. This explains plausible-looking but unrelated links. Only four interactive workflows exist: event creation, PPT upload, PDF notes, and Waiting status. A static scan of 63 PHP pages is a label inventory, not a verified map of all application workflows.

**A valid citation does not establish a valid instruction.** The assignment answer told the user to click the green check-mark icon to assign work. That icon completes a task; the supplied manual explicitly said so. A reviewer was told to select Edit Presentations despite read-only access. Another answer invented invoice-generation tools in Financials. The response validator checks JSON structure and whether cited IDs exist, not whether instructions follow those sources or are possible for the current role. Actual application permissions still block unauthorized changes; this is misleading guidance, not a demonstrated permission bypass.

**Conversational responses are forced into a document-answer shape.** “Thanks, that helped” became a service-unavailable fallback. Every generated answer must cite at least one section; acknowledgements, scope explanations, and missing-evidence responses need their own valid forms. Error handling also merges transport, decoding, and unsupported-answer failures into the same unavailable message, obscuring diagnosis.

## What the model comparison showed

With the correct existing manual sections manually selected, 8B answered the Waiting description well, but still confused assignment with completion and ignored the reviewer's role. The same diagnostic on installed 14B corrected assignment and answered Waiting, but still failed the role constraint. Answer-only 14B times were 12.14 seconds for the first call including model loading, then 5.42 and 7.00 seconds. This was a diagnostic of evidence use, not a deployed retrieval improvement.

Three further questions through the unchanged full 14B pipeline took 12.61, 5.54, and 15.04 seconds. Assignment improved; the reviewer question became an unhelpful fallback; the organization-navigation correction still produced an invented route. The small comparison does not establish overall model superiority. It does show that a larger model alone does not make the current architecture reliable. The default remains 8B, and it was loaded again after testing.

## Iterative improvement of the Comprehensive Manual

The proposed “lather, rinse, repeat” cycle should be central to this work. Failed coach answers can reveal implicit assumptions in the manual as well as missing sections. Use the observed response to identify a testable defect, then establish the correct behavior from application code and a verified interface walkthrough before changing documentation. The coach's own answer is evidence of its behavior, not evidence that the application works that way.

A concrete first repair is the event-creation entry point. Page 23 begins “Choose the organization.” That is a form-filling instruction, but it does not establish how to reach the form. A clearer workflow would state its starting point and role: “Editors and administrators: open Engagements, select + New Engagement, then choose Organization inside the New Engagement form.” It should also explain that Organization Details does not provide a new-event action. This revision is supported by the actual application and would improve the manual for people as well as the coach. It has not been applied during this review.

For each iteration:

1. Run the fixed benchmark and inspect a sample of real requests. Capture the question, role, page/state, retrieved passages, actual response, and expected next action.
2. Classify the failure. Missing or ambiguous manual content calls for documentation; an existing section that was not retrieved calls for indexing/search; a retrieved section that was contradicted calls for model/validation changes; a wrong scripted workflow calls for routing code. More prose will not reliably fix every class.
3. Verify the expected answer against source and the UI using isolated fixture data. Produce a small proposed change to the manual's authoring sources and matching workflow definitions. Each procedural section should state the goal, entry path, role, prerequisites, exact controls, branching conditions, save confirmation, and common mistakes.
4. Rebuild and visually verify the Comprehensive PDF using the existing manual build process. Rebuild the coach index and check that the exact new passage and its PDF page citation are retrievable. Preserve list and table meaning: today's PDF index collapses whitespace, and the role matrix becomes a dense sequence of labels and Yes/No values.
5. Rerun the original failing question, several unseen paraphrases, an opposite-intent question, and role/state variations. Also rerun the broader regression suite. Compare correctness, next-step usefulness, unsupported claims, and latency. A nicer answer to one memorized question is not sufficient acceptance.
6. Keep the change only if it improves the targeted behavior without regressions. Version the manual, index, workflow catalog, prompt, model, and test results together. After a bounded number of unsuccessful iterations, change the hypothesis rather than endlessly expanding the manual.

This loop can be automated as a development task with application verification and test gates. It should generate proposed corrections and supporting evidence without requiring users to explain how MOED works. Keep an untouched held-out test set, including developer-authored and real user phrasing, so the model does not simply grade revisions against its own examples. Use explicit checks for labels, roles, routes, and outcomes; an LLM reviewer can help triage prose but should not be the sole judge.

The current build already has [manual authoring and verification instructions](user-manual/README.md) and a [PDF indexing script](../scripts/ai_help/index_comprehensive_manual.py). Improve those shared sources and regenerate the artifacts; avoid separate contradictory “AI-only” instructions. A longer-term structured workflow source can publish both human-readable manual sections and machine-readable steps with the same version and PDF provenance. Updating retrieved knowledge can affect the next answer without retraining model weights.

## Recommended implementation order

1. **Fix wrong instructions and lost context first.** Narrow creation fast paths, preserve the last visible step when ending a guide, resolve references from conversation, and apply server-owned role/capability rules before suggesting actions. Repair Back/Forward recovery and include the coach HTTP suite in required CI. Add the reproduced failures as regression cases. These changes have a clearer benefit than a model switch.

2. **Build a verified workflow catalog from MOED itself.** Each workflow should describe its entry points, supported roles, prerequisites, exact control labels/IDs, transitions, required fields, and observable completion conditions. Start with approximately 20 frequent tasks, including editing dates, assigning work, restoring records, calendar subscriptions, presentation files, and inquiry conversion. Generate candidates from source, templates, and the Comprehensive Manual; verify them in an isolated browser environment with fixture records and different roles/tabs. Rebuild and test when UI or permissions change. Crawling is useful for discovery, but observing one screen cannot prove a workflow works in every state. This maintenance belongs in development and release checks, not in the user's chat interaction.

3. **Let verified steps supply the actions and the model supply the explanation.** For a known task, render the next valid instruction immediately; offer “Show me” and a short explanation of why the step matters. Keep page/tab state, selected workflow, last visible step, and allowed capabilities in a compact context object. Send structural state rather than private field contents. Revalidate visibility before highlighting. Never infer a successful save solely from navigation, and keep actual edits and saves with the user. A new question ends the walkthrough while retaining enough history to answer naturally.

4. **Improve retrieval and reduce unnecessary inference.** Search the workflow catalog and Comprehensive Manual with both exact terms and semantic similarity, then rank and deduplicate a small set of evidence. Search across chapters instead of treating the first chapter choice as a hard boundary. Preserve the user's action/object and reject unsupported capability claims. Budget the total prompt by tokens. Prefer a single answer call after retrieval; reserve an extra interpretation call for genuine ambiguity. A local embedding service is feasible, but benchmark it: the current Ollama limit permits one loaded model, so adding an embedding model to the same process without changing capacity planning could cause model swapping and worsen latency. A small separate embedding runtime or carefully tested residency arrangement is preferable. [Ollama embeddings](https://docs.ollama.com/capabilities/embeddings), [Ollama concurrency and memory guidance](https://docs.ollama.com/faq).

5. **Make progress and recovery visible without tying up page requests.** Accept questions as durable jobs and return a request ID promptly. Use a bounded worker queue with the existing two inference slots, clear queued/generating states, and cancellation that stops server work. Align deadlines and client recovery to a single documented budget. Keep navigation and page workers available while inference runs. Stream suitable explanatory text, with action metadata kept separately validated; raw JSON fragments or unvalidated action instructions should not become clickable controls. Streaming improves time to first useful text but does not make the full answer finish sooner. Cache only suitable approved/versioned explanations or shared evidence, keyed by role and context where relevant. Preserve model residency, while measuring cold starts separately. [Ollama chat streaming and timing fields](https://docs.ollama.com/api/chat).

6. **Create an improvement loop that does not depend on the user teaching the coach.** Keep request logging, but add routing choice, retrieved topic IDs/scores, workflow and step, queue delay, prompt/load/generation duration, token counts, first useful text, final latency, and distinct failure reasons. Record a simple helpful/not-helpful signal and “I can't see that control” events. Reviewed corrections should become approved knowledge and regression cases; saving a correction in today's log does not retrain the model. Retain a redacted approved evaluation set separately from the operational log so clearing the log does not erase improvements. Start with 100–200 held-out cases across common workflows, paraphrases, permissions, partial progress, negations, and failures. Evaluate factual correctness and next-step usability, not just topic selection. Revisit model choice after these changes; consider fine-tuning only for persistent measured behavior gaps in a curated dataset, not as the mechanism for remembering changing UI details.

## Proposed release gates

These are targets, not achieved guarantees: immediate local acknowledgement; a verified next step in under one second; first useful generated text ideally within three seconds; and browser-measured p95 full-answer time below 15 seconds under two active users. Set a 20-second answer budget with a useful status/recovery path when the service cannot meet it. Test four-user bursts, fresh prompts, cold models, and tunnel failures explicitly. Do not present a 20-second absolute maximum as guaranteed on a shared machine.

For quality, target at least 95% fully usable guidance on a held-out set of common supported tasks, with zero known invented controls, permission-incompatible actions, or destructive-action confusion. Require correct tab entry, preserved follow-up context, and explicit uncertainty when a capability is not supported. Audit actual user task completion alongside answer ratings.

## Code review and verification

The requested Bugbot-style specialist review used one general review subagent because a dedicated Bugbot integration was unavailable. It found five actionable defects: incorrect event-creation matching (P1), step context loss (P2), Back/Forward recovery (P2), blocked pronoun follow-ups (P2), and skipped coach HTTP coverage in normal CI (P2). Exact locations, reproductions, and fixes are recorded in the evidence file and interactive review.

The PHP coach helper suite, 17 JavaScript tests across coach/manual behavior, and minified-asset consistency checks passed. The specialist independently reproduced context and lifecycle issues with an in-memory DOM harness. This review did not rerun the full disposable HTTP suite or perform a complete live-browser workflow crawl. Passing the existing tests therefore does not resolve these findings.

Keep the useful foundation: local inference behind the SSH tunnel, server-owned navigation controls, escaped text, authenticated request access, role checks on real actions, persistent request IDs, and a chronological teaching panel. The next investment should make the coach reliably know the available action and current step, then make its explanations fast and conversational.

## Follow-up work and availability check

After the review, the requested UI and upload changes were implemented separately: the request list now shows its user, role, timestamp, outcome, timing, and page in a clearer four-column layout; walkthrough buttons use lowercase labels; PowerPoint uploads allow 500 MB while PDFs retain their 100 MB limit. The Comprehensive Manual and its retrieval index were rebuilt. The five coaching defects above remain recommendations, not applied fixes.

During the follow-up work, MOED's SSH tunnel logged timeouts to shunbun and a real user request returned unavailable in 0.2 seconds. Connectivity recovered without restarting Ollama. A generation check through MOED's configured connection then answered the application-purpose question in 3.97 seconds. The service already has an active `caffeinate -i` sleep-prevention assertion, so the Mac's one-minute idle-sleep setting does not establish the cause. The connection failure's root cause remains unconfirmed.

The local Docker disk had only about 1.1 GB free, which interrupted the large-upload test while saving a second large file. No unrelated Docker data was deleted. The complete PPT/PPTX HTTP suite passed using an isolated temporary upload volume, including exact 500 MB uploads, oversized-file rejection, checksum verification, and ranged downloads. Persistent-file backup/restore, PDF short-link HTTP checks, coach HTTP checks, JavaScript checks, applicable PHP static analysis, and manual/asset checks also passed. Production deployment remains stopped.


## Implemented after approval

The five reproduced coaching defects are now fixed, including positive event creation
matching, preserved follow-up step context, role-aware guidance, Back/Forward and late
answer recovery, and required coach HTTP coverage. The runtime searches across manual
chapters and normally makes one model call. Four interactive workflows are supplemented
by sixteen source-verified procedures. Background work uses two dedicated workers with
eight-job admission and a 20-second total budget. The request log now records feedback
and stage diagnostics; a separate improvement screen retains approved, redacted cases
with versioned reuse and evaluation export. The PDF event-creation entry was corrected
and its index rebuilt.

See [the implementation and improvement-loop guide](ai-coach-integration.md) and
[measured implementation results](ai-coach-improvement-results.json). Warm reused
regression questions improved substantially; these measurements do not establish expert
accuracy, a held-out pass rate, or a browser latency SLA. Embeddings, streaming, and
fine-tuning remain deferred. The newly verified procedures supply numbered actions;
only the existing four workflows have interactive highlighting. s1 remains undeployed.
