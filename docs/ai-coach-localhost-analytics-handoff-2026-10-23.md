# Localhost analytics cancellation handoff

Historical diagnosis retained with the release. The cancellation work described below is now implemented and qualified by PR #275; see [the completed verification notes](analytics-ai-cancellation-fix-2026-10-23.md). The earlier pending checks and local-only restriction below describe the diagnosis stage.

Branch: `codex/moed-qwen-cleanup` (base `bedfa7a61743b213a43291cd6ec211ce81651440`). Keep this separate from the runtime-instruction cleanup. No analytics code, running services, configuration, or business records were changed during diagnosis.

The screenshot from localhost:8080 shows the campaign analytics assistant, a separate path from MOED’s conversational help. A read-only inspection of request 232 confirmed that its prompt exactly matched the screenshot. It was created at 2026-10-23 20:45:11 UTC and recorded as cancelled at 20:46:24 UTC, approximately 73 seconds later. Its analytics deadline was 90 seconds. Its stored response had `reason=cancelled` and no `purpose` field. The worker also logged cancellation during query planning. This establishes cancellation; it does not establish who or what initiated it or why planning took that long.

`aiCoachCancelRequest()` in `src/ai_coach_job_helpers.php` stores a generic coach response, without `purpose=audience-ai`. `render()` in `src/assets/js/audience-ai.js` requires that purpose and displays “The AI service did not return a usable answer” when it is absent. This explains the visible message for the recorded request. Expiration uses another generic coach response and should also be checked. The relevant web and worker PHP source fingerprints matched despite different image tags; no code incompatibility was established.

Relevant files:

- `src/ai_coach_job_helpers.php`: cancellation and expiration response contracts.
- `src/audience_ai_api.php`: analytics cancellation/status endpoints.
- `src/assets/js/audience-ai.js`: rendering, polling, cancellation, and restoring history.
- `src/ai_coach_worker.php`, `src/audience_ai_dialogue.php`: query-planning cancellation and elapsed time.

Remaining checks: reproduce cancellation and history restoration with isolated synthetic jobs; preserve the assistant-specific response shape; verify ordinary coaching cancellation as well as analytics cancellation/expiration; investigate the cancellation trigger and planning delay separately. Do not run database fixtures against live data. The earlier instruction excludes deployment and persistent runtime changes, so a local runtime refresh requires explicit authorization. A fix should not change Qwen selection, enable thinking, or increase the MOED coaching timeout.
