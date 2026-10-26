# MessageMonkey security review — October 26, 2026

Reviewed the current implementation on `codex/messagemonkey-function-performance`, including the preceding function/performance changes. The defensive follow-up addressed two additional resource-consumption weaknesses and strengthened the SNS queue protections. Three moderate-severity findings are now remediated; no critical or high-severity vulnerability was confirmed within this review's scope.

## Confirmed finding and fix

**P2 — Unauthenticated SNS envelopes could amplify permanent certificate-fetch failures into repeated worker activity. Fixed.**

The public SES receiver intentionally queues structurally valid envelopes for signature verification by the network-enabled mail worker. A caller who knows the configured SNS topic ARN could submit an envelope containing arbitrary signature bytes and a syntactically permitted but nonexistent certificate path on the configured AWS SNS host. No valid AWS signature was needed to reach the certificate fetch.

Previously, HTTP errors such as 403 and 404 raised a Guzzle exception. The worker treated that exception as a temporary failure, retained the encrypted envelope, and scheduled another attempt. Each such receipt could occupy the queue and consume worker fetches for up to 20 attempts. The shared 10,000-pending-receipt cap bounds count but does not prevent those retries from competing with genuine delivery, bounce, and complaint notifications. The finding concerns availability; forged signatures did not pass verification or change recipient suppression state.

The fix in [audience_ses_notifications.php](../src/audience_ses_notifications.php) explicitly classifies certificate HTTP responses before reading their bodies:

| Result | New handling |
| --- | --- |
| HTTP 200 and a bounded certificate | Continue normal signature verification |
| Redirects and permanent client errors, including 403/404/410 | Reject the receipt on its first attempt and clear its encrypted payload |
| HTTP 408/425/429, server errors, or connection failure | Preserve the existing bounded retry/backoff behavior |
| Certificate body larger than 64 KiB | Reject permanently |

Redirect following remains disabled. The regional AWS host/path restrictions, network timeouts, proxy restrictions, signature checks, account binding, and topic binding remain in force. No database migration or deployment configuration change is required.

The new unit test failed against the previous HTTP-error handling and passes with the fix. The disposable worker tests additionally confirm the durable state transitions and payload retirement under the real `dnrmaildispatch` grants, rather than only checking exception types. All certificate responses in these tests are mocked.

The follow-up adds two defenses to this same trust boundary. Before enqueueing, the application now validates field types and lengths, absolute timestamps, required confirmation fields, confirmation tokens, and duplicate URL aliases. Malformed requests return 403 and create no queue rows. The worker also caches permanent certificate-fetch failures for the current bounded batch: three forged receipts sharing a missing certificate now trigger one fetch, and all three are rejected with their payloads cleared. Temporary failures retain their existing retry behavior.

**P2 — Public form views allowed unrestricted growth of visitor analytics rows. Fixed.**

The visitor identifier is controlled by the browser. Previously, each anonymous GET with a different identifier could insert another `marketing_collection_visits` row, without the submission limits applying. A small isolated HTTP reproduction confirmed that rotating identifiers continued adding rows despite a preseeded exhausted analytics budget.

[audience_collection.php](../src/audience_collection.php) now permits at most 300 analytics-recording attempts per trusted client IP, per account, per UTC hour. The budget is shared across forms, so changing a visitor ID or form key does not reset it. The insert also checks the resource's account, public key, published state, and deletion state. [audience_collect_public.php](../src/audience_collect_public.php) uses this path instead of inserting visits directly.

Exhausting the analytics budget does **not** block viewing or submitting a form. HTTP tests verify continued access, a successful signup, resistance to forged forwarding headers, and the shared budget across forms. Analytics may undercount high-volume shared-IP traffic after the threshold; consent and signup operations keep their separate existing limits. This bounds per-IP storage growth, not arbitrary distributed traffic volume.

**P2 — Dense CSV input bypassed the intended column limit and amplified memory use. Fixed.**

The parser checked the header's column count but accepted data rows with arbitrarily many columns within the 20 MiB byte limit. A synthetic 600,012-byte input produced 600,001 cells and used 56,655,872 bytes of peak memory in a standalone parser process. Larger permitted inputs therefore presented avoidable memory-exhaustion risk to the authenticated import path; a production crash was not attempted.

[audience_import.php](../src/audience_import.php) now enforces 200 columns on every data row and a conservative 500,000-character budget for comma, tab, and semicolon separators before native delimiter detection or row parsing allocates arrays. Quoted separators count toward this complexity budget. Oversized imports receive a validation message asking the user to split the file. The same reproduction is rejected at 2,097,152 bytes of peak memory. Ordinary 50,000-row imports, the 200-column boundary, quoted delimiters, Unicode, and embedded newlines remain covered by passing tests.

## Other investigated risks

The suspected certificate-alias fetch bypass was **ruled out**. Although the application and SDK support different URL-key spellings, the installed AWS message class rejects duplicate spellings before any fetch. The follow-up moves that structural check to queue admission as well. Regression cases verify rejection of cross-region, alternate-port, and query-bearing conflicting aliases; legitimate signatures using either single spelling still work.

The following areas received source inspection and relevant automated verification. These results describe the reviewed controls, not a guarantee that every possible attack has been excluded.

| Area | Controls and paths examined |
| --- | --- |
| Authentication and authorization | Session/auth-version revalidation, administrator/editor/reviewer gates, direct action routing, CSRF, demo send/configuration restrictions, Admin Unlock for protected deletion |
| Account and actor isolation | Account/database pairing, audience-scoped queries and IDs, private compose drafts, AI request ownership, cross-audience fixtures, attachment access |
| Contacts, imports, exports, search | Bound SQL parameters, allowlisted filters/sort fields, import size/row/column limits, XLSX external relationships/entities, contact-export formula handling, job permissions |
| Campaigns, plans, flows | Version checks, destructive-action permissions, draft snapshots, immutable recipient identity, send-time opt-out/deletion/suppression checks, interrupted-send and ambiguous-provider-response handling |
| Designer, templates, content and brand | HTML/rich-text/SVG/CSS sanitization, inert previews, escaped merge values, archive path/expansion checks, image type/pixel limits, attachment disposition and MIME validation |
| Public signup, surveys and preferences | Published-resource scope, authenticated submission challenges, expiry/replay checks, rate limits, honeypots, recipient-token binding, renewed consent and protection of existing profiles |
| Unsubscribe, tracking and archives | GET versus mutation behavior, one-click unsubscribe tokens, signed redirect URLs, current recipient scope, published/sent visibility, image access tokens |
| Inbox and inbound mail | Signed account routes, trusted gateway authentication, sender/thread matching, automatic-reply rejection, safe attachment handling, draft ownership and opt-out checks |
| SES and mail workers | Encrypted credentials, restricted database roles, webhook signatures, certificate URL restrictions, provider/account/recipient correlation, quota handling and no automatic replay after uncertain delivery |
| AI and analytics | Per-user/per-role request ownership, read-only allowlisted query tools, bound arguments, account scope, query bounds, report/export permissions |
| Browser boundaries | Preview sandboxing, embed origin/source checks, generated JSON escaping, contenteditable sanitation and scoped recovery/storage keys |
| Dependencies | Current public advisory checks for the locked Composer and npm dependency sets |

## Verification

- **36 local PHP suites passed**, including 58 defensive input checks, the 26-check SNS security suite, and the existing signature/sanitizer/permission suites. Four additional database-role suites intentionally skip outside a disposable environment and passed in Docker.
- **16 isolated integration suite entries passed.** The principal HTTP suite completed 1,339 checks, including malformed SNS requests rejected before storage.
- **21 restricted-worker security checks passed:** permanent-response retirement, transient backoff, no immediate re-fetch, payload cleanup, recovery, forged-signature rejection, and one fetch for repeated permanently failing certificate URLs.
- **12 targeted public HTTP security checks passed** on the final code, including cross-form analytics limits, continued form access, and a successful signup after analytics recording is capped.
- Existing worker tests passed for signed confirmation, legitimate receipt suppression, credential isolation, no replay, inbound authentication, attachment routing, and key rotation.
- **All four PHPStan configurations passed.** `git diff --check` passed.
- The earlier same-day Composer audit reported **zero advisories and zero abandoned packages**; npm reported **zero vulnerabilities**. Neither lockfile changed during the follow-up. These are advisory-database results, not proof that dependencies contain no vulnerabilities.
- Verification exposed a null service-mail ID deprecation in the preceding Inbox batching change. The lookup now uses an integer key. A fresh isolated run passed **386 performance checks and 156 HTTP checks across 25 workspaces**, without that warning.

Initial qualification results are retained in [qualification.json](review-assets/message-monkey-security-2026-10-26/qualification.json), with the current follow-up results in [defensive-qualification.json](review-assets/message-monkey-security-2026-10-26/defensive-qualification.json). Regression cases are in [audience_defensive_input_test.php](../tests/audience_defensive_input_test.php), [audience_public_security_http_integration_test.php](../tests/audience_public_security_http_integration_test.php), [audience_sns_security_test.php](../tests/audience_sns_security_test.php), and [audience_sns_worker_security.php](../tests/audience_sns_worker_security.php). The worker cases run from the existing mail-role privilege suite. The integration run also reported an existing, nonfatal PHP 8.5 `imagedestroy()` deprecation in the template-transfer test fixture.

To repeat the principal checks:

```sh
php tests/audience_sns_security_test.php
php tests/audience_ses_test.php
php tests/audience_defensive_input_test.php
python3 scripts/integration_environment.py audience-security
python3 scripts/integration_environment.py audience
composer analyse
```

## Scope limits and delivery state

This was a local source and isolated-runtime review, not a production penetration test. No real mail was sent, no AWS resources were modified, and the disposable Docker projects were removed. Live ingress/WAF policies, sustained adversarial traffic, operating-system/container-image advisories, deployed configuration drift, and real email-client behavior were not qualified.

The SNS receiver still accepts bounded, structurally valid unauthenticated envelopes for deferred signature verification by design. The changes reject malformed envelopes earlier and remove repeated permanent-failure fetches within a batch and across retries; they do not establish resistance to arbitrary-volume flooding or temporary network-failure attacks. Production traffic admission remains a separate operational control.

Changes remain local and uncommitted on the current branch; nothing was pushed or deployed. The earlier function/performance work is preserved.
