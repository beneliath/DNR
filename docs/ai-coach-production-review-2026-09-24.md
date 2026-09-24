# Production feedback review — 2026-09-24

Read-only s1 intake returned eight signals from application 2.3.4. Seven signal
versions matched the prior production receipt; one new signal was investigated.
The clean review branch was reconciled with main 2.3.4 before changing guidance.
Baseline local guidance matched the production revision. Final read-only intake
confirmed unchanged source and signal versions.

The new failure confused documented calendar summaries with access to live record
totals. Source verification found that calendar counts cover the displayed month,
with overlapping events eligible in multiple months. The coach receives structural
UI context, not a record dataset or query tool. The Comprehensive Manual describes
the displayed month's summary; it does not establish an arbitrary date-range total.

An original-context local model replay reproduced the misleading guidance in
8.594 seconds. The new synthetic regression failed before the correction. The
shared immediate-answer path now explains the lack of live-record access for count
requests, across roles and pages, without inventing a count, action, or citation.
The model prompt also prohibits treating manual examples or summed monthly counts
as a unique live total. No manual, PDF, or index change was needed.

After the correction, the original-context replay returned the explicit limitation
in under one millisecond with no model call. A fresh reviewer model probe explained
the documented monthly scope in 6.686 seconds. A broader synthetic count phrasing
correctly declined to calculate a total in 5.243 seconds, but asked an unnecessary
date-range follow-up. That conversational limitation remains; these checks do not
establish correctness for every possible paraphrase.

Focused PHP checks, 123 existing regression cases, 28 JavaScript tests, and disposable
HTTP, queue, feedback, and real-worker integration suites passed. The integration
fixture emitted existing session-cleanup warnings after worker assertions passed;
they are retained in the private test log and are not a new application failure.
Both local workers were restarted after checking that no local requests were pending.

Previously deferred visibility reports have unchanged signal versions. Comparing
2.3.2 to 2.3.4 found no relevant changes to their task/calendar/coach controls; the
engagement change adds presentation headings. These signals still lack an authenticated
local visual reproduction and remain deferred. Historical production inference
availability likewise remains unverified; no production inference was run.

All changes and the receipt are local. No production state was written, no accounts
or business records were created, and no deployment was performed. Private packet
questions, answers, and annotations are excluded from tracked artifacts.
