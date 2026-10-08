# Account branch review fixes — October 8, 2026

Implemented on `codex/account-isolation`. **No s1 deployment or production data
change was performed.** These changes address the nine initial findings and the
seven follow-up findings below. The subsequent encrypted production-copy database
migration/rollback rehearsal passed. Whole-deployment qualification remains a
release gate; passing local checks does not guarantee a production transition.

| Finding | Implemented fix | Verification |
| --- | --- | --- |
| 1. Platform audit entries exposed to ordinary Admins | One visibility predicate covers audit entries, full-text search, pagination/counts and retention statistics. Platform events and Account entities are excluded for ordinary Admins. Primary shared-log pruning is SuperAdmin-only, enforced on POST as well as in the UI; member private-log pruning remains available. | HTTP checks prove ordinary Admin search does not disclose synthetic foreign-Account entries, SuperAdmin can see them, and forged prune requests are denied. |
| 2. Directory updates committed before the private user transaction | Private transactional outbox, durable central reservation and a single unique username-claim namespace. The old route remains until a locking receipt proves commit. Rollback releases only the unused reservation; network failures/timeouts retain it. A reconciliation worker and post-commit attempt finish interrupted changes. Delete follows the same protocol. | Real HTTP/DB checks cover rollback, committed-but-unpublished changes, repeated reconciliation, deletion rollback, a still-open transaction, and concurrent primary/member claims for the same username. |
| 3. Notes-cache worker rejected Account URLs | Accept canonical HTTPS `/a/<label>` bases, purge scoped URLs, and additionally purge legacy root URLs for the primary. All batches must succeed before acknowledging a job. Member workers receive their Account mode and do not purge primary root URLs. | Cache policy tests cover canonical and legacy URLs, other Account paths, query variants, unsafe bases, and primary/member separation. |
| 4. No production Account control workflow | Added an operator-only production worker, reviewed primary routing overlay generator, immutable image/proxy/configuration checks, private member Compose generation, durable provisioning and archive/restore/delete execution, identity probes, deletion recovery receipts and the normal release lock. Default invocation is a plan; the service template is inert until separately installed. | Seven production-planner tests and real Compose configuration parsing. No production worker was applied. Local Account creation/lifecycle infrastructure remains separate. See `account-production.md` for the unexecuted rollout and restore rehearsal. |
| 5. Coach source contracts invalidated | Reviewed the six affected workflows and two form contracts; refreshed their hashes. Updated reviewer-email location and reviewed page metadata, including the new recovery username field. | All five formerly failing Coach suites pass; source-contract preflight passes. Built `dnr-account-review:local` from this branch and ran the affected suites with no application source or foreign-worktree mounts and networking disabled. |
| 6. Common recovery searched only the primary | Common recovery accepts username plus verified email and queues a generic asynchronous request. The correct private Account issues the recovery token. A transactional receipt prevents duplicate emails after retries/lost responses. Requests expire after 30 minutes; pending username changes defer recovery until resolved. | HTTP recovery reaches only the member Account; nonexistent usernames receive the same public result; repeated delivery creates one token. All test mail stayed local/log-only. |
| 7. CI failures and missing Account HTTP coverage | Corrected DeploymentConfig PHPDoc, registered Account mail symbols for analysis, repaired the JS DOM fixture, refreshed remaining Coach page contracts, and fixed heading capitalization. Added a dedicated disposable multi-Account CI job running isolation, creation, consistency, mail and lifecycle suites. | All four PHPStan configurations pass; the 227-file PHP runner completes (environment-dependent integration tests explicitly skip); all 247 JavaScript tests pass; Python reports 80 tests, OK with 5 skips. The new GitHub job is wired but has not been executed on GitHub in this task. |
| 8. Delivery cap smaller than importer | Shared service envelope limit supports the importer’s maximum 16 MiB message plus worst-case JSON escaping and headers. Non-mail operations remain capped at 16 KiB. Sender and receiver use the same bounded limit. | Real signed member HTTP delivery of a 12 MiB quoted body succeeds; the primary Inbox stays empty for that synthetic message. |
| 9. Member rename left central name stale | Reconciliation reads the signed, committed private profile, checks its Account identity and publishes only newer profile versions. Saving settings attempts synchronization after the local update; worker retries survive outages. | Synthetic rename converges after replayed reconciliation; an attempted cross-Account rename operation is rejected. Original local Account name restored after testing. |

## Initial fixes: database and local verification

Two **new additive migrations** introduce coordination/recovery tables and the
unique username namespace. Previously applied migrations were not edited. Before
applying them to the three localhost previews, fresh encrypted database/files
backups were created and restored for verification. Normal migrations/grants then
completed on each preview. Existing production databases were not contacted.

The latest localhost isolation, creation, consistency and mail-routing HTTP
suites passed. They used nonce-tagged users and messages and cleaned their test
records; user-requested sample review messages were not reseeded or removed.
The localhost provisioning/reconciliation/mail worker was restarted after the
suites. No real SMTP or IMAP test traffic was sent.

Validation outputs are retained locally under `/tmp/moed-fixes-*`. Initial
harness failures (missing clean-image test fixtures and a full-text fixture
containing a hyphen) were corrected before the passing checks. The complete PHP
runner followed the heading fix; it does not turn skipped integration suites into
passes. The production Compose parser check generated synthetic configuration
only and started no services.

## Seven follow-up findings: fixes and verification

These fixes address the review recorded in
`var/deployment/account-review-20261008/review.txt`. This implementation pass made
no connections to s1, changed no migrations and did not apply the production
overlay to any deployment.

| Finding | Implemented fix | Verification |
| --- | --- | --- |
| 1. Primary HTTPS trust lost the immediate ingress address | Production configuration requires the primary backend subnet and ingress IP. The overlay preserves that IP and includes it alongside Traefik in the application's trusted proxy list. | HTTPS recognition regression and real primary Compose parsing verify the two-hop trust configuration. |
| 2. Private web/backup services could not reach Account control endpoints | Each deployment has a restricted CONNECT relay to the configured HTTPS origin, pinned to Traefik. Only its own backend subnet can connect. Web/backup retain private networks; PHP verifies the origin certificate and hostname through the tunnel. | Disposable Docker networks and a synthetic TLS origin prove an internal-only PHP client succeeds through the relay, rejects an untrusted CA, and cannot reach the origin directly. Wrong hosts, ports, plaintext requests and shared-edge peers are denied. |
| 3. Normal releases omitted the primary Account overlay | `--prepare-primary` saves durable configuration under `var/deployment/account-production`. Every production Compose invocation includes it; the release transaction fails closed when an enabled deployment lacks it, preserves the previous configuration in its recovery record and refreshes the qualified manifest within the writer pause. The worker reloads the saved configuration each cycle. | Two release-configuration tests exercise successive release invocations, overlay ordering and missing-configuration failure. Fourteen release-workflow tests pass. Production/member Compose configuration parses with the real Compose implementation. |
| 4. Existing root API/health routes disappeared | The primary public allowlist explicitly retains `/api/v1/mattermost.php` and `/health.php`. Authenticated Account pages still require their canonical path. | Route contract assertions pass; generated primary configuration includes both existing endpoints. No live Mattermost operation was sent. |
| 5. Archived restore mixed new migrations with old images | Provisioning freezes resolved member Compose configuration, image provenance and the active service list, with a checksum. Lifecycle operations use that snapshot. Restore starts only saved database/services with `--no-deps`; no schema/file migrators run. Missing or modified snapshots fail closed. | A mocked archive/upgrade/restore contract verifies saved images, configuration and services despite a newer primary release. The frozen document also passes real Compose parsing. This is not a production lifecycle rehearsal. |
| 6. Full-body batches exhausted PHP memory | Queue lists fetch bounded metadata; delivery batches fetch IDs/sizes, load one body at a time and apply a byte budget. One accepted oversized message may progress, then remaining work waits for the next cycle. | A read-only SQL fixture with twenty 9.75 MiB quoted bodies now uses **4 MiB peak PHP memory** for list previews under a 64 MiB limit; the prior full-body query exhausted 512 MiB. HTTP tests prove a one-byte batch budget still delivers one message and preserves the remaining queue, followed by normal delivery. |
| 7. Legacy root invitations lost MFA continuation | Invitation forms post to the canonical Account path. Both canonical and already-open legacy root forms redirect to Account MFA while retaining token/CSRF/pending authentication. Accounts-disabled behavior remains unchanged. | Synthetic old root GET/POST invitations complete both new TOTP enrollment and existing-MFA verification, then reach the authenticated Account dashboard. Fixture users are removed afterward. |

Ten production-planner tests, the Account PHP isolation checks, Coach helpers and
all four PHPStan configurations pass. The Python discovery run covered 85 tests
with five explicit skips; two existing socket tests hit the sandbox's loopback
restriction and passed when rerun with local network permission. There were no
remaining source/test failures. The invitation, mail-routing, isolation and
consistency HTTP suites passed on localhost with synthetic fixtures and cleanup.
The local worker was restored after the coordinated suites. No real SMTP/IMAP
traffic was sent, and existing sample review messages were preserved.

The new relay, Compose, mail-capacity and invitation checks are wired into the
disposable Account CI runner. That GitHub job has not been executed on GitHub in
this task. Logs from this implementation pass are under `/tmp/moed-seven-*`.
Production configuration and recovery instructions are in `account-production.md`.

## Five evaluation findings: fixes and verification

These fixes address `var/deployment/branch-evaluation-20261008/review.txt`.
No s1 connection, production deployment, schema change or production data copy
was made during this fix pass.

| Finding | Implemented fix | Verification |
| --- | --- | --- |
| 1. Member service credentials could assert a human SuperAdmin identity | Primary-issued 15-minute sign-in proof is bound to member, identity and authentication version. Every identity API operation checks it and current eligibility. The member ticket API is removed; switches use target-specific forms submitted to the signed-in primary browser session. Creation/export still require fresh password and MFA. | Local HTTP tests reject absent, forged, expired, wrong-member, wrong-user and wrong-version proofs, reject ticket replay and service-issued switches, and verify primary-session switching and immediate authentication-version revocation. Existing creation and isolation HTTP suites pass. |
| 2. Runtime snapshot omitted profiled active services | Render all profiles into the snapshot, validate active services before saving, and start/retry from the saved runtime. | The actual production Compose generator now passes saved lifecycle validation with receipt-previews present. A missing-service regression fails before saving; restore continues to use saved services without migrations. |
| 3. Production backup and workers lacked Account environment | Explicitly apply the Account environment and control credentials to every relevant service without replacing its database principal. | Actual generated production configuration is checked for every service. Local encrypted exports pass for a member SuperAdmin and ordinary Account Admin; wrong Account, absent proof, wrong password, reused MFA and revoked identity are denied. Temporary encrypted test exports are removed. |
| 4. Mail parser allocated all markers before enforcing the limit | Incremental scanning uses a shared 64-marker budget and a 128-byte candidate window for current and legacy markers; incomplete/oversized tokens fail closed. | 16 MiB bodies with repeated valid, invalid and incomplete markers pass under a 64 MiB limit at 23,085,056 bytes peak heap (about 22 MiB). Mixed legacy/current boundary tests and the full local mail-routing HTTP suite pass. |
| 5. Saved runtime still referenced mutable checkout configuration | Copy static configuration and scripts into each member runtime; record content/tree/permission hashes, remove build instructions, validate before lifecycle/recovery operations. Only named read-only operational inputs remain live; secrets stay separately managed. | Generated production binds reference saved copies. Changing a synthetic checkout leaves the saved configuration unchanged; modifying a saved copy fails validation. Recovery archives already include the complete member directory, including these copies. |

Verification: all four PHPStan configurations pass; the full PHP runner and Daily
Digest check complete (environment-dependent integrations retain explicit skips;
an existing DOM/SVG fixture emits warnings). Fourteen Account/release-configuration
unit tests and fourteen mocked release-workflow tests pass. Production Compose
parsing starts no services. Local identity/export, creation, isolation and mail
HTTP suites use disposable fixtures and exact cleanup, preserving user sample
messages. The new identity/export regression is wired into the disposable Account
CI runner; the GitHub job and a complete production-shaped lifecycle rehearsal
have not been executed here. Logs are under `/tmp/moed-five-*`.

Existing member SuperAdmin sessions need to reopen their Account to obtain the
new proof; that proof expires after 15 minutes. Ordinary Account users are
unaffected. This compatibility change intentionally fails closed.

## Three subsequent findings: path-based protections and recovery

These changes address `var/deployment/branch-evaluation-20261008-round2/review.txt`.
The existing domain and `/a/<account-label>` URLs are retained as requested.
No s1 connection, deployment, DNS change or production data operation occurred.

| Finding | Implemented fix | Verification |
| --- | --- | --- |
| 1. Shared-origin root session cookies reached member PHP | Trusted ingress filters incoming and outgoing cookies; members use path-scoped sessions. The ingress serves qualified static assets itself and enforces an additional restrictive script policy. Private browser state uses an immutable namespace derived from the actual Account URL. | Disposable real Apache fixtures verify cookie ordering/filtering, response-cookie isolation, logout expiry, preference cookies, trusted assets, CSP intersection and primary/common/member paths. A browser fixture confirms trusted scripts execute while inline and backend-supplied scripts are blocked. Local sign-in/MFA, switching, backups, roles, creation and legacy invitation suites pass. |
| 2. Interrupted provisioning could not resume after a primary release change | Qualify the current primary independently, then use the member's saved images, migrator, configuration, active services and URL. Pre-runtime retries require zero existing project resources and preserve secrets. | New mocked release A/B retry tests cover saved and interrupted-checksum snapshots; a pre-runtime retry succeeds only without resources. Fifteen production-planner tests and real Compose parsing pass. Primary qualification also rejects a missing, writable, changed or unqualified ingress policy. This is not a full production lifecycle rehearsal. |
| 3. Control clients buffered oversized replies before rejecting them | Both directions use one streaming cURL callback that aborts above 128 KiB before JSON decoding. | Finite loopback fixtures exercise both clients with Content-Length, chunked and connection-close framing, exact-limit valid JSON, malformed JSON and non-200 replies. Oversized 16 MiB responses abort early with less than 4 MiB additional peak PHP heap. |

**Scope of the path-based mitigation:** Account paths are still one browser
origin. This does not fully contain arbitrary JavaScript execution in trusted
frontend assets; those assets and the ingress remain platform trust boundaries.
Old browser-local drafts/preferences are retained under their old keys but are
not imported across Account namespaces. Saved database records/files are unchanged.
See `account-production.md` for these operational constraints.

Verification also includes all four PHPStan configurations, the complete normal
PHP runner and Daily Digest check, JavaScript syntax and 248 JavaScript tests,
and Coach source-reference preflight. Environment-dependent PHP integration checks
retain their explicit skips; existing DOM/SVG fixture warnings remain. Disposable
HTTP checks preserve existing local sample messages and remove their own fixtures.
The new ingress check is wired into Account CI; the response tests run in Python
discovery. GitHub CI and the production-shaped Traefik rehearsal have not run here.
The ingress image was built locally and the local provisioner was restored. Implementation logs are under `/tmp/dnr-fixes-*`.

## Still required before any s1 rollout

The approved isolated production-copy database rehearsal already verified the
six additive migrations, preserved business-table counts/checksums and credential/
MFA fields, and reproduced the original database fingerprint on rollback. A
separate read-only check verified all 108 stored files. See the review report for
the intentional role/status/session changes and the exact scope of that evidence.

Use an isolated **fresh production copy**, the exact qualified release images,
and a new restore-verified production backup for a complete deployment rehearsal.
Verify record/file counts and hashes, real legacy public links, MFA/recovery,
Traefik/Cloudflare path handling and cache invalidation, worker progress, Account
lifecycle recovery and the operator service configuration. Execute the new CI job
on GitHub. Deployment requires a separate explicit authorization; this task did
not provide or assume it.
