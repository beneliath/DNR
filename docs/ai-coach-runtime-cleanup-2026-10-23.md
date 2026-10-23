# MOED runtime instruction cleanup — October 23, 2026

Implemented on `codex/moed-qwen-cleanup`, based on `bedfa7a61743b213a43291cd6ec211ce81651440`. The localhost cancellation fix remains on its separate branch. This cleanup was not deployed; persistent runtime configuration and business records were not changed.

## Behavior

Answer directly when the goal and evidence suffice. Otherwise, use one focused clarification containing supported choices or exactly what to provide, including an alternative when that information is unavailable. Keep it in `question`, without repeating it in `message`. After an incomplete reply, give supported conditional guidance or state the remaining limitation.

Prompt wording alone did not reliably achieve that behavior with Qwen. The implementation also:

- Removes question fields from replies classified as explanations, preserving their answers and citations.
- Prevents an identifiable built-in clarification from repeating within supplied recent history.
- Treats consecutive troubleshooting turns on the same form as one diagnostic context. After an assistant question, another model question returns a clear limitation; any guessed diagnosis accompanying it is discarded. An intervening limitation does not reset the budget.
- Leaves first clarification available. A different preceding intent or explicitly different form is a separate diagnostic context; the tested notes-to-task-diagnosis change still asks for the missing error.
- Preserves resolved notes choices, permissions, verified workflows, and high-confidence procedure selection. Low procedure confidence alone no longer manufactures a generic goal question.

Built-in questions now explain how to respond. Unspecified notes offers task Notes, event Chron Log, or a presentation's speaker-notes PDF, with the option to describe the purpose. An unidentified control asks for its label with an example and an alternative description if unlabelled. Diagnosis requests the exact error, any field label, or what happened when no error appears. Greeting, required-closeout, and attachment answers no longer append generic follow-ups.

The instructions distinguish the assistant's missing live dataset from the Calendar UI's real counts. Events and engagements are the same record; overlapping monthly counts cannot establish a distinct multi-month total. They preserve current-location precedence and distinguish prerequisites from current values, file selection from successful Save Changes, and completion from archival and deletion. Application-supported answers retain the existing allowance for an empty manual-citation list.

## Active path and boundaries

`src/ai_coach.php` applies immediate guards, revision-scoped approved guidance, and verified workflows before inference or queueing. `aiCoachGenerate()` uses the active `aiCoachPayload()` instructions and `aiCoachDecodeReply()` validation in `src/ai_coach_helpers.php`; workers use that same path. Built-in questions belong to `ai_coach_guidance_helpers.php` and the form rules in `ai_coach_intent_helpers.php` / `data/ai-coach-forms.json`. Approved guidance is revision-scoped and has an empty question field.

The historical `scripts/ai_help/coach-system.txt` pilot and unused runtime route prompt were not changed. Retrieval ranking, workflow definitions, authorization, schema constraints and property order, and inference limits were preserved. The reviewed guidance-helper dependency fingerprint was refreshed without changing the presentation-deletion procedure.

Defaults remain `qwen3:8b`, `DNR_AI_COACH_REASONING=0`, temperature 0, context 8,192, 320 output tokens, and the existing 20-second coaching deadline. Optional thinking behavior was neither removed nor enabled. No thinking/model matrix or DeepSeek comparison was repeated.

## Verification

[Retained raw responses and both PHP decoders](ai-coach-runtime-cleanup-results-2026-10-23.json) cover 16 original/cleaned pairs: the ten frozen benchmark questions and six clarification/diagnosis cases. Four deterministic guards cover ambiguous notes, reviewer permissions, required closeout, and actual Waiting-status failure. Each pair preserves the frozen question, evidence, history, role, user-context message, schema including property order, temperature, context, and token limits; only the system instructions differ between inference arms.

Fourteen matching original responses were reused from the earlier source-cleanup sample after checking model digest, frozen-fixture hash, system hash, context hash, and schema hash. Two new cases received fresh originals. Their raw responses and original timings are embedded in the artifact. The final diagnostic-state correction changed only the new-goal system prompt; that case alone was replayed after verifying the other 15 system prompts were byte-identical. Final history-budget guards were checked by decoding every retained raw response with final PHP source, without additional inference.

| Sample | Original valid | Cleaned valid | Original median | Cleaned median |
|---|---:|---:|---:|---:|
| Ten core cases | 10/10 | 10/10 | 6.38 s | 5.85 s |
| All sixteen cases | 16/16 | 16/16 | 4.37 s | 5.00 s |

All retained responses stopped normally; none reached the token cap (maximum 155 generated tokens). These are separate, small engineering samples with uncontrolled cache state and service load, not an interleaved latency experiment or accuracy estimate. No speed improvement is established. The supplied historical thinking-off/on samples remain separate and were not pooled with these measurements.

Raw core replies with nonempty question fields increased from 2/10 to 4/10; the application-displayed count decreased from 2/10 to 0/10. Across all cases, raw counts were 4/16 versus 8/16, while displayed counts were 3/16 versus 2/16. Both remaining cleaned questions are necessary first diagnostic clarifications. This improvement depends on application guards; prompt wording alone does not prevent repeated questions.

| Case | Before/after behavior |
|---|---|
| Unspecified notes | One question now offers the three supported meanings and explains how to respond; identifying the PDF starts the existing notes workflow without another question. |
| Missing error on first failure | Original invented an empty Waiting On cause. Cleaned says the cause is unknown and requests copied error text, any named field, or what happened if no message appeared. |
| Incomplete answer | Qwen still asks again in raw output. The application returns “I cannot establish the cause from the details available” with no question. |
| Differently worded second diagnostic question | The round guard returns a limitation and discards an accompanying guessed cause. |
| Error supplied | Explains the conditional Waiting On requirement and Save Changes directly, without another question or unrelated citation. |
| New diagnosis after a notes question | Necessary guided error clarification remains available. |
| Confirmed status / task due date | Direct explanation; no generic continuation offer or unrelated task-notes procedure. |
| Additional affiliation | Keeps one person and the additional organization's own role/title, without inventing primary-role choices. |

Nine focused PHP test files pass: helpers, clarification, interface references, regression, live counts, location, sources, presentation intents, and presentation deletion. Routing/retrieval checks pass 123/123, source-fingerprint tests 2/2, and coach JavaScript tests 20/20. Tests cover incomplete replies, differently worded diagnostic questions, intervening limitations, distinct contexts, supplied errors, permissions, and unchanged inference defaults. Worker source/configuration fingerprints and Qwen metadata were unchanged before/after synthetic inference. Tests did not enter business-data or request-history endpoints.

## Remaining limitations

Qwen still produces factual and citation defects despite valid JSON. Retained core replies invent a “Completed or Closed” list, prescribe PDF-first file ordering, omit or misname presentation entry steps, falsely deny automatic save confirmation, and overstate what an existing file link proves. The multi-month reply rejects adding overlapping counts but overstates the UI limitation instead of attributing it clearly to the assistant's missing dataset. “Confirmed event” remains loose terminology in the inquiry explanation. Some citations are only loosely relevant. These remain unresolved; retrieval, workflow, and semantic-validation redesign stayed outside this cleanup.

The round guard uses the existing intent classifier, form location, and bounded supplied history (six messages; inference receives four). It is not persistent semantic goal tracking: separate diagnoses on the same form may conservatively share a budget, and history loss or misclassification can limit enforcement. General action clarifications retain the prompt policy and identifiable-repeat guard. A hard guarantee across arbitrary long conversations would require explicit persistent per-goal state, outside this focused instruction cleanup. The diagnostic guard deliberately states a limitation instead of inventing a cause when one reply is insufficient.

Several shorter prompts and an intent-first field-order experiment were rejected after regressions; final schema order is unchanged. Earlier draft artifacts were archived locally in `/private/tmp/moed-qwen-previous-*`; the linked artifact contains the final comparison and superseded new-goal replay for transparency.

To reproduce a fresh comparison, extract the base `src` tree to a temporary directory and run `scripts/ai_help/compare_instructions.py` with `--original-src`, the supplied `prepared.json` through `--frozen`, an existing Qwen worker through `--worker`, and a new `--output` path. `--ids` restricts inference to targeted cases. The script refuses to overwrite results, forces thinking off only in local preparation, and verifies unchanged worker metadata; it does not deploy source or write application records.
