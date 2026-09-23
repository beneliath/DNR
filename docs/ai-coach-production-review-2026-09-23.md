# Production feedback test run — 2026-09-23

Read seven signals from s1 (`192.168.1.150`, `moed-web-1`, application 2.3.1)
through a read-only database transaction. No production review state was changed.
Production IDs are separate from the earlier localhost review. Private questions,
answers and annotations were kept in private temporary files, not this repository.

## Results

- Two citation complaints: verified that the existing local unnamed-interface
  clarification fixes the reproduced path. Local answers ask for a label, include
  no invented citation and make no inference call. This fix is not deployed to s1.
- One helpful clock signal: verified the configured local-date response against
  the application clock implementation; a vote alone was not considered proof.
- Three missing-control reports: deferred. Reviewed calendar, engagement-date
  and task controls, their selectors and role gates against the manual. These
  application files, form/procedure catalogs and the PDF have identical production
  and local hashes. Reported structural snapshots include the named controls, but
  that does not invalidate the user's report or establish visual visibility.
  The local browser redirects to sign-in, blocking authenticated visual reproduction.
  No accounts, credentials, subscriptions or business records were created.
- One earlier availability failure: deferred as a production reliability issue.
  Its transport code 6 means hostname resolution failed. Production model-host DNS
  resolves now, but no production inference was run; current DNS and a local
  clarification do not prove that production inference reliability is fixed.

## Verification

Replayed all seven original contexts in memory against the local coach, plus the
three reported failure contexts. No records were imported. Calendar replay took
0.100 seconds on its first call; other deterministic replies rounded to 0.000
seconds. These measurements are not an SLA or model-generation measurements.
A separate synthetic calendar-content question invoked the local model once,
completed in 6.646 seconds, and correctly described selected categories, private
URLs and excluded internal details with relevant manual citations.

Expanded synthetic interface-reference regressions to Users and Coach Request
History across all three roles, with stale history and guided steps. The original
regression failed before the earlier clarification fix. This run passed the
expanded test, coach helpers, 123 intent/permission cases, database retries,
minified assets and 21 JavaScript tests. Runtime source hashes match those used by
the passing disposable integration run earlier in this task; that suite was not
rerun for this test-only expansion. No additional runtime code, manual/PDF/index
or worker restart was needed in this run.

The existing broader reviewer-explanation and off-topic conversation findings
remain unresolved. This batch adds no evidence that they are fixed.

The accompanying production receipt records per-signal versions/dispositions,
production and local guidance separately, tested source hashes and next actions.
It is local only; none of these production IDs was completed in either database.
