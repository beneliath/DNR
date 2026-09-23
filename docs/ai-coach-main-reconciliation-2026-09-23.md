# Reconcile local feedback work with deployed 2.3.1

Merged `origin/main` at `50252ea8b9515a3b78d77d3c025b5066d9fb0cd1` into
`codex/moed-local-help-chatbot` in merge commit `1f97f61`. The merge tree matched
main exactly before restoring pending review work. Squashed release history
caused conflicts; the main versions of the deployed files were retained. All
pending files were restored, and the pre-merge stash remains as a backup.

The initial combined test run caught a behavioral overlap: the earlier local
clarification intercepted generic page questions covered by main's page-aware
retrieval. The clarification now defers to known-page explanations. Unnamed
buttons, fields and controls still request a label; unknown-page references can
also clarify. Current-page retrieval, historical page/tab metadata, application
grounding without forced citations, and manual-panel closing behavior are intact.

This supersedes the earlier local/production review's proposed clarification
behavior for generic known-page questions. Those historical receipts describe the
source hashes tested at that time and were not rewritten to imply current proof.
The deployed 2.3.1 page-aware path supplies the known-page correction; the local
addition is the narrower unidentified-control response.

Validation:

- `for test_file in tests/ai_coach*_test.php; do php "$test_file"; done` passed
  applicable local tests, including location, citation, 123 intent/permission
  cases and the expanded clarification test. Database/service-only tests were
  explicitly skipped in this invocation and exercised separately below.
- `php tests/minified_assets_test.php` passed.
- `node --test tests/js/ai-coach.test.js tests/js/ai-coach-lifecycle.test.js`
  passed all 28 tests, including current-location and manual-panel behavior.
- `DNR_TEST_APP_IMAGE=dnr-review-hardening:test DNR_TEST_INGRESS_IMAGE=dnr-ingress:review-hardening DNR_TEST_DATABASE_IMAGE=dnr-database:local python3 scripts/integration_environment.py coach`
  passed isolated HTTP, queue, feedback and real-worker suites, then cleaned up.
  The worker test still emits its existing session/header cleanup warnings.
- Fresh local model calls with stale Dashboard history described Users in 6.277
  seconds with the Users manual citation, and Calendar in 5.672 seconds using
  current application context. An unnamed-button request clarified without
  inference. The Calendar sample appended an unnecessary copy of the question in
  its follow-up field; that remaining conversational quality issue is not claimed
  fixed. Timings describe these samples only.

Both local workers were restarted after checking that no local requests were
pending. Browser JavaScript uses main's existing matching minified bundle and
asset manifest. No manual/PDF/index content changed. No s1 deployment, remote
push, production writes, credential changes or account creation occurred.
