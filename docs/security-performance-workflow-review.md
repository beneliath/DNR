# DNR security, performance, and release workflow review

Reviewed 2026-09-05 at commit `fdfd0bd`, application version `1.11.6`. Working branch: `rollingImprovements001`.

This records the original repository-wide review of the PHP application, JavaScript assets, Mattermost plugin, SQL migrations, containers, CI, and deployment scripts, followed by its implementation status. The findings below describe the reviewed base commit; their original line references may move as fixes are applied. This is not a claim that every possible defect has been excluded. No production system or real mailbox was exercised.

## Implementation status — 2026-09-05

All fourteen findings have corresponding changes merged from `rollingImprovements001`. Release operations and the standing backup requirement are documented in [Release and recovery workflow](release-workflow.md) and [.cursor/rules/backup-before-database-upgrade.mdc](../.cursor/rules/backup-before-database-upgrade.mdc). These changes, the deployment timestamp correction, and the mail-worker heartbeat imports are prepared for application `1.11.9` and plugin `0.4.7`. Deployment status is recorded by the guarded release workflow; this review does not by itself assert a production rollout.

- **S1:** MFA enrollment is bound to the authentication version and updates the factor only through an active-account/version compare-and-set. Stale sessions cannot refresh their revoked authentication state.
- **S2:** Recovery-email changes require the current password and fresh TOTP when enabled. Pending verification preserves the current recovery address, queues an old-address notice, and atomically promotes the new address while revoking sessions. Password resets and deactivation cancel pending changes.
- **S3:** Database and migrator share a MySQL 8.4 LTS image. The build removes unused MySQL Shell libraries, patches OS packages and rebuilds gosu with the supported pinned Go toolchain. Runtime-support review is checked quarterly. Upgrade and restore were rehearsed on synthetic data.
- **S4:** The inbound worker can read only the account columns required for routing and notifications; negative tests exercise the actual worker credentials.
- **P1:** Raw/decoded headers are limited to 32 KiB and participants to 100. Indexed normalized-email lookups use three bounded batch queries. Ineligible automatic mail skips those queries.
- **P2:** Organization and contact cursors use explicit lexicographic comparisons. Tests cover duplicate contact names, all contact sorts, both directions and archive views. The improved organization range scan was confirmed on MySQL 8.4. The joined organization-name contact sort is not claimed to have the same range-scan behavior.
- **P3:** Browser backup capacity, PHP concurrency, tmpfs and container budgets are coordinated. Backup serialization avoids large base64 copies; downloads use 1 MiB chunks from a consistent snapshot. The maintenance page warns about estimated capacity. Native deployment backups cover databases beyond the browser export ceiling. Private object storage was evaluated and remains a future option, with its required recovery/authorization design documented.
- **P4:** Read-only dashboard and list work releases the session after flash/CSRF changes. Dashboard/navigation reuse request-local reminder counts. A blocked list request no longer holds up another authenticated request in the same session.
- **P5:** Fingerprinted asset URLs no longer change merely because VERSION changes; unhashed resources retain version fallback.
- **W1:** One persistent MySQL connection owns the migration lock through ledger checks, DDL and grant configuration. Connection loss stops migration; interrupted ledger states require review.
- **W2:** Final-main CI builds or reuses application, ingress, database and Bridge candidates, scans them, tests the application image and produces a manifest of digests, migration hashes, version and plugin checksum. Production promotion disables rebuilding.
- **W3:** Deployment holds a host lock, verifies the exact requested SHA before switching, pauses writers, creates a fresh encrypted backup and restore-verifies it before advancing the checkout, database or schema. Failures retain the recovery record. Worker health requires successful processing progress.
- **W4:** `scripts/prepare_release` implements the project’s minor/major/super vocabulary, validates both app and plugin transitions before writing, and supports read-only checks. The plugin manifest is the single version source for build and User-Agent metadata.
- **W5:** Merge and mirror helpers preserve the existing protected-main gate, bind actions to observed commits, and verify both remotes and the release tag with retryable non-force pushes. Optional auto-merge still requires the owner to enable that repository setting; no branch-protection settings were changed.

The requested dashboard owner links, shared light/dark Open/Closed pills and engagements rows-per-page/pagination controls are also retained on this branch.

### Implementation validation

- PHP syntax and all four PHPStan configurations passed. The non-integration PHP runner and 22 JavaScript tests passed; database/socket skips are counted separately.
- All 57 migrations applied on a disposable database. MySQL 8.0.46 → 8.4.11 upgrade, table upgrade checks, application connectivity, grants, triggers/routines and full-text/integration behavior passed. Twenty database suites passed on 8.4; the destructive backup suite passed separately.
- Account-security tests cover revoked/competing factor replacement, deactivation/reactivation, password/TOTP requirements, token expiry/replay, recovery revocation and competing email claims. Actual inbound-worker credentials cannot read password hashes, factor material or pending recovery addresses.
- Migration concurrency tests passed for a waiting second migrator, lock timeout, and killed lock holder with safe refusal on retry. Deployment failure tests prove failed backup prevents checkout/database upgrade and failed migration retains the verified recovery record.
- The large browser backup restored two 100 MiB PDFs plus three 5 MiB QR BLOBs per PDF. Serialized size was 321,731,676 bytes; peak PHP allocation was 486,555,648 bytes (about 464 MiB), below the 512 MiB limit. This is a bounded capacity test, not an unlimited-storage guarantee.
- The native deployment backup restored into a network-isolated database, matched all 35 table checksums/counts and migration/routine/trigger inventories, and passed encryption/decryption checksum verification. Fresh initialization and a restore containing a 100 MiB BLOB also passed with the patched MySQL image. Temporary plaintext and the verification database were removed.
- Two simultaneous 100 MiB authenticated downloads returned correct hashes in 3.20 and 3.44 seconds locally; the web container’s observed memory peak was about 43 MiB. An independent same-session read took 12 ms while an organizations query was blocked for five seconds. These are synthetic local observations, not production latency promises.
- The 20,000-row MySQL 8.4 organizations plan read 21 index rows after the cursor rewrite versus 18,021 for the old predicate. All nineteen Compose configurations validate; HTTP smoke and authenticated workflows passed against the baked application image. The new profile section was visually inspected in light and dark themes.
- Locally built application, ingress, database and Bridge images have no fixable HIGH/CRITICAL findings in the pinned Trivy scan. Reports retain other/unfixed findings; scanner coverage does not prove absence of vulnerabilities in every bundled executable. Plugin race/vet checks passed; updated Go dependencies have no reachable vulnerabilities or affected imported packages (an advisory remains in unused OpenPGP module code).

Production-sized restored data, concurrent maximum-size uploads plus exports, live mail-provider behavior and a real GitHub-to-s1 promotion still need validation in their actual environments. No production credentials, database, branch protections or registry publication were changed. The release job must succeed on the final merged commit before deployment.

## Original findings and recommended order


First fix the session-revocation bypass during authenticator replacement (S1), protect recovery-email changes with reauthentication (S2), and repair migration locking (W1). Schedule the MySQL upgrade (S3) promptly. Then address inbound-mail query amplification, pagination, and backup capacity before adding broader caching or scaling infrastructure.

For releases, retain the existing protected-main CI gate. Add one version-preparation command, create releases from the final merged commit, build and test an immutable image once, verify both Git remotes, and serialize deployment of that image on s1.

Priorities: **P1** = address promptly; **P2** = meaningful next improvements; **P3** = lower-risk efficiency and maintainability improvements. Evidence is labeled as reproduced, source-verified, or a design recommendation. These are project priorities rather than CVSS scores.

## Security

### S1 — P1: A revoked session can complete authenticator replacement and become valid again

**Evidence: reproduced against the unchanged route and a disposable database.** [setup_2fa.php](/Users/dgilmore/DNR/src/setup_2fa.php:14) accepts a fully logged-in session through `isLoggedIn()` without the database-version check performed by `requireLogin()`. The version check at line 25 applies to pending logins. The enrollment record expires after ten minutes but is not bound to `auth_version`. Confirmation [updates the factor and refreshes the session version](/Users/dgilmore/DNR/src/setup_2fa.php:148).

A user began factor replacement, then an administrator reset the user's password and incremented the database authentication version from 1 to 2. The old session submitted its pending factor's valid TOTP. The route installed that factor, incremented the database version to 3, refreshed the old session to version 3, and returned ten recovery codes.

This requires an already authorized enrollment and access to the old session within its enrollment window; it is not an anonymous bypass. Nevertheless, it defeats the intended session revocation and can preserve account access after a password reset.

**Change:** validate the full authenticated session at entry, bind pending enrollment to the authentication version, and atomically recheck account activity and the expected version while updating the factor. A check only at the start of the request still permits a race with an administrator reset. Invalidate pending enrollment when authentication state changes. Require fresh authorization when a stale enrollment is rejected.

**Acceptance:** after a password reset, deactivation/reactivation, or competing factor replacement, an old enrollment cannot change the factor, receive recovery codes, or refresh its session version. Include concurrent confirmation/reset coverage.

### S2 — P1: Recovery-email changes need password and MFA reauthentication

**Evidence: source-verified account-recovery path.** [profile.php](/Users/dgilmore/DNR/src/profile.php:90) changes the recovery address using only the active session and CSRF token. It immediately replaces the address, clears verification, consumes old email tokens, and queues verification to the new address. It does not request the current password or a fresh MFA challenge, notify the old address, or increment `auth_version`.

An attacker holding an active session for an account without MFA can set an address they control, verify it, and use the password-recovery flow to gain persistent access. Existing MFA remains a barrier on accounts that have it enabled; email replacement does not itself remove the factor.

**Change:** separate recovery-email changes from ordinary profile edits. Require the current password and fresh MFA when enabled; store a pending address until verified; notify the previous address; promote the new address atomically and apply the intended session-revocation policy. This follows [OWASP's guidance for changing registered email addresses](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#changing-a-users-registered-email-address).

**Acceptance:** session plus CSRF alone cannot change the recovery destination. Test verification races, expiry, address collisions, old-token invalidation, and MFA-enabled accounts.

### S3 — P1: Move the database off the MySQL 8.0 release line

**Evidence: source configuration plus current vendor documentation.** Both [database](/Users/dgilmore/DNR/docker-compose.yaml:196) and [migrator](/Users/dgilmore/DNR/docker-compose.yaml:240) use a pinned MySQL 8.0 image. Oracle states that MySQL 8.0 reached end of life in April 2026 with 8.0.46 and recommends migration to a supported release, including 8.4 LTS. [MySQL release notes](https://dev.mysql.com/doc/relnotes/mysql/8.0/en/).

**Change:** rehearse an upgrade to a supported LTS on a disposable restored database, then update both image pins together. Validate migrations, grants, stored routines/triggers, full-text behavior, PHP connectivity, and backup/restore. Keep a verified pre-upgrade database backup for recovery; do not treat changing the image tag back as a supported database downgrade. Add container-image scanning and an explicit runtime-support review to complement Composer/npm audits.

This is a maintenance and support exposure, not a finding of a particular exploitable database CVE.

### S4 — P2: Restrict the inbound-mail worker's access to account data

**Evidence: source-verified grants.** [configure_database_privileges.sh](/Users/dgilmore/DNR/scripts/configure_database_privileges.sh:182) grants the inbound-mail identity unrestricted `SELECT` on `users`, including password hashes and encrypted factor material. Its [address lookup](/Users/dgilmore/DNR/src/inbound_email_helpers.php:448) requires a much smaller set of columns. The outbound worker already demonstrates column-level grants.

**Change:** inventory all user fields needed by inbound routing and notifications, then grant only those columns. The parser handles externally supplied MIME, so reducing database privileges limits the consequences of a future parser or worker compromise. The encrypted factor values are not plaintext factors, and the inbound worker does not receive the factor-decryption key.

**Acceptance:** real worker credentials can process supported routing cases but cannot select password or factor fields. Keep these negative permission checks in integration coverage.

## Performance and capacity

### P1 — P2: Bound inbound-mail participant fanout before database routing

**Evidence: reproduced query amplification.** [Header parsing](/Users/dgilmore/DNR/src/inbound_email_helpers.php:68) accepts the participant lists without a count ceiling. [Routing](/Users/dgilmore/DNR/src/inbound_email_helpers.php:934) performs user, contact, and organization lookups for each address. [Processing](/Users/dgilmore/DNR/src/inbound_email_helpers.php:1164) holds a message-row lock while computing routing, before rejecting ineligible automatic mail.

A synthetic 28,019-byte message containing 1,000 `To` recipients produced 1,001 participants, **3,006 prepared-statement executions**, and a 365.4 ms routing run in the isolated environment. Even mail without successful automatic-routing eligibility incurs this work. This is a mailbox-ingestion exposure; the upstream mailbox provider may impose additional limits.

**Change:** cap decoded header bytes and participant count before expensive routing; quarantine oversized inputs; perform cheap eligibility checks early where they can conclusively reject a message. Batch address matching into bounded indexed queries, reuse the sender lookup, and shorten the transaction where consistency allows.

**Acceptance:** test the maximum and maximum-plus-one boundaries, duplicate addresses, folded headers, and invalid authentication. Assert a bounded number of queries rather than a machine-specific timing target.

### P2 — P2: Rewrite cursor comparisons to obtain actual index range scans

**Evidence: reproduced with `EXPLAIN ANALYZE`.** [organizations.php](/Users/dgilmore/DNR/src/organizations.php:121) uses `(organization_name, id) > (?, ?)` alongside `is_deleted`. The existing `(is_deleted, organization_name, id)` index does not deliver the intended seek behavior for that predicate in the tested MySQL 8.0 plan.

On a 20,000-row synthetic organizations table with the production table definition, fetching 21 rows after position 18,000 read **18,021 index rows** with the current predicate. Expanding it to `organization_name > ? OR (organization_name = ? AND id > ?)` read **21 rows**. Observed execution times were 7.79 ms and 0.065 ms respectively in a single local run; they are not production latency estimates. MySQL documents this class of [row-constructor optimization limitation](https://dev.mysql.com/doc/refman/8.0/en/row-constructor-optimization.html).

**Change:** expand lexicographic inequalities, preserving collation and ascending/descending behavior. Review the analogous [contacts comparisons](/Users/dgilmore/DNR/src/contacts.php:151); the organization-name join sort needs its own plan analysis and may require a different strategy. The organizations measurement should not be extrapolated to every contacts sort.

**Acceptance:** verify duplicate names, both directions, archive and search filters, no omitted/repeated rows, and bounded rows examined for deep cursors at realistic cardinality. Repeat plans on the intended MySQL upgrade target.

### P3 — P2: Align attachment, PHP memory, and backup limits

**Evidence: source-verified limits and size arithmetic.** [Slide decks may be 100 MiB](/Users/dgilmore/DNR/src/presentation_asset_helpers.php:7), uploads [read the whole file](/Users/dgilmore/DNR/src/presentation_asset_helpers.php:199), and [non-range downloads select the whole BLOB](/Users/dgilmore/DNR/src/presentation_asset.php:104). The backup format base64-encodes values, while its [default serialized size ceiling is 256 MiB](/Users/dgilmore/DNR/src/database_backup_helpers.php:11).

Two legal 100 MiB attachments require approximately **266.7 MiB** of base64 data alone, exceeding the default backup ceiling before other rows and metadata. Thus normal supported uploads can make the built-in backup refuse to export the database. Large simultaneous reads also multiply PHP-worker memory demand; an out-of-memory failure was not reproduced. The backup implementation already streams encrypted output, so the problem is not that the entire backup is buffered.

**Change:** immediately coordinate the configured limits and expose an estimated backup-size warning. Longer term, evaluate private file/object storage with authorization-preserving streamed downloads and a consistent database-plus-assets backup manifest. Set measured Apache concurrency and container memory/CPU/PID budgets; avoid increasing every limit independently.

**Acceptance:** restore a representative large backup, test the size boundary, and measure peak resident memory during concurrent maximum-size uploads/downloads and backup creation.

### P4 — P2: Release session locks before read-only list and dashboard work

**Evidence: source-verified inconsistent lock lifetime; latency impact not benchmarked.** [dashboard.php](/Users/dgilmore/DNR/src/dashboard.php:10), organizations, engagements, and tasks retain the PHP session while doing database work. Other routes already use [releaseSessionLock()](/Users/dgilmore/DNR/src/functions.php:187).

**Change:** consume flash messages, establish CSRF tokens, and finish intended session mutations, then close the session before read-only queries. Audit rendering helpers for session writes before moving the boundary. Reuse request-local task/closeout counts shared by the dashboard and navigation. Profile the aggregate queries before adding shared caches with invalidation complexity.

**Acceptance:** with one deliberately slow list request, an unrelated read from the same browser session completes independently. Verify flash messages, administrator preview state, and CSRF behavior remain correct.

### P5 — P3: Let unchanged static assets survive an application version bump

**Evidence: source-verified cache identity.** [assetUrl()](/Users/dgilmore/DNR/src/functions.php:19) adds both application version and content fingerprint. A version-only release therefore changes URLs for every bundle even if its content hash is unchanged. The current map bundle is 1,050,643 bytes raw / 283,250 bytes gzip; modern CSS is 101,505 / 17,049 bytes. These are file-size measurements, not observed network savings.

**Change:** use the content fingerprint as the cache identity for manifest-backed immutable assets, retaining an appropriate version fallback for other assets. Keep missing-manifest development behavior intentional. Consider content-hashed filenames when introducing immutable build artifacts.

**Acceptance:** a version-only release preserves unchanged asset URLs; a content change always changes the URL. Verify existing query-string handling.

## Version, merge, and deployment workflow

### W1 — P1: Keep the migration advisory-lock connection alive

**Evidence: reproduced against MySQL.** [migrate.sh](/Users/dgilmore/DNR/scripts/migrate.sh:36) obtains `GET_LOCK()` through command substitution. That short-lived MySQL client exits immediately. The advisory lock belongs to the database connection and disappears when it closes, before any migration executes. The cleanup `RELEASE_LOCK()` runs through a different connection.

The first short-lived client returned `1` for lock acquisition; the next client immediately observed no holder and acquired the same lock. This reproduces the missing exclusion, not a destructive concurrent-DDL test. Two deployments can therefore race on DDL and the migration ledger despite the script's intended locking. See [MySQL lock lifetime](https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html).

**Change:** run the migration sequence through a persistent connection that holds the advisory lock throughout ledger checks, DDL, and grant configuration. If a separate holder process is used, detect holder loss and stop safely. A host deployment lock is useful as a second layer but does not replace database-level exclusion across hosts.

**Acceptance:** start two migrators against a fixture; prove the second cannot enter the migration body while the first holds the lock. Cover timeout, holder failure, interrupted migration, and ledger recovery.

### W2 — P2: Deploy the exact image tested by CI

**Evidence: source-verified release provenance gap.** [deploy_s1.sh](/Users/dgilmore/DNR/scripts/deploy_s1.sh:138) performs `up -d --build` on s1 after checking the commit and CI state. A matching source SHA does not ensure an identical image: package repositories and other build inputs can change between builds. Current CI does not publish an immutable production image for promotion.

**Change:** build once from the final merged SHA in CI, test that image, publish it by digest, and deploy with rebuilding disabled. Record application version, Git SHA, image digest, migration manifest, and plugin checksum in a release manifest. Give web and workers the same application-image identity. Scan the produced image and retain its software inventory. Keep GitHub Actions permissions scoped to the publishing job and use the least-privilege supported registry authentication.

**Acceptance:** CI, s1, and the release record agree on the digest; a re-run promotes the same artifact; an image cannot be deployed merely because a different build from its source SHA passed.

### W3 — P2: Serialize deployment and define recovery before schema changes

**Evidence: source-verified operational gaps.** The s1 script has no host lock. It [fast-forwards the checkout before confirming the requested SHA](/Users/dgilmore/DNR/scripts/deploy_s1.sh:120), so advancement of `origin/main` between preflight and deployment can alter the checkout before refusal. Existing application/worker processes may still be running during schema migration; Compose startup dependencies are not a maintenance barrier for already running services.

**Change:** hold one host lock from preflight through final health verification. Verify the immutable release input before switching the active checkout/release directory. Classify migrations as compatible with the previous application or requiring a controlled writer pause; use expand/contract changes where practical. Record a recent verified backup and previous image/schema before migration. Define image rollback for compatible changes and restore/forward-fix procedures for incompatible changes.

The existing commit/version and public-readiness checks are useful. Extend worker validation beyond `State.Running` to processing heartbeats, queue age, or a safe canary, because a stuck loop can remain running.

**Acceptance:** two simultaneous deploy requests serialize; a moved main branch cannot silently change the requested release; failed migration or readiness checks leave a clear recovery record. Rehearse recovery on disposable infrastructure without assuming DDL rollback.

### W4 — P3: Introduce one deterministic release-preparation command

**Evidence: workflow recommendation based on current manual version surfaces.** `VERSION` is already the application's single source, but release preparation also involves generated content such as the daily-digest preview. Plugin version `0.4.6` is duplicated in [plugin.json](/Users/dgilmore/DNR/mattermost-plugin/plugin.json:8), [Makefile](/Users/dgilmore/DNR/mattermost-plugin/Makefile:3), and [the client User-Agent](/Users/dgilmore/DNR/mattermost-plugin/server/client.go:83), plus documentation.

**Change:** add a proposed `scripts/prepare_release` command that reads the latest target-main version, computes the requested bump, refreshes only required generated content, validates all release metadata, and presents one reviewable release diff. This command does not exist today. Perform the bump near release preparation rather than independently on every feature branch, avoiding duplicate-version merges. It can support one-feature releases or batches.

Preserve the project's explicit vocabulary: **minor** means `x.y.(z+1)`; **major** means `x.(y+1).0`; **super** means `(x+1).0.0`. Do not silently substitute conventional SemVer terminology. Derive plugin build metadata from one authoritative version when the plugin itself changes.

Tag the final merged commit, not an earlier feature-branch commit. A proposed `check-release` CI step should reject stale generated previews, inconsistent plugin metadata, and invalid version transitions.

**Acceptance:** from version `1.11.6`, a requested minor preparation produces `1.11.7` once; rerunning validation is read-only and clean; concurrent release branches cannot both publish the same version. Version-only changes should not force unrelated asset URL changes after P5.

### W5 — P3: Automate merge completion and remote verification around existing protection

**Evidence: live read-only GitHub inspection on the review date.** Classic protection already requires six checks: `dependency-audit`, `frontend-quality`, `integration`, `mattermost-plugin`, `quality (8.4)`, and `quality (8.5)`. It requires an up-to-date branch and applies to administrators. Force pushes and deletion are disabled. Required approving reviews are zero. Auto-merge and automatic branch deletion are disabled; all three merge methods are allowed.

**Change:** retain those checks. Enable auto-merge if desired so an authorized PR can finish after its checks pass, and choose a consistent merge strategy. Add reviewer requirements only when a second reviewer is reliably available. A merge queue may help if concurrent PR volume grows; it is not the first improvement this project needs.

The documented convention pushes to both `origin` and `gitlab`, while [deployment verifies only origin/main](/Users/dgilmore/DNR/scripts/deploy_s1.sh:46). Make branch/tag verification on both remotes an explicit, retryable release stage, preserving already completed pushes when one remote is unavailable. Store both verified SHAs in the release record. Never repair mirror disagreement with an automatic force push.

**Acceptance:** every deployment can be traced to the protected merged SHA, its release tag, both remote checks, and the tested image digest. Retrying after a mirror outage resumes the incomplete stage without allocating another version.

### Proposed release sequence

1. **Prepare:** update from target main, compute the requested version once, generate/check metadata, and open a reviewable release PR.
2. **Merge:** satisfy protected-branch checks and merge using the selected policy. Require successful CI for the final merged SHA.
3. **Identify and mirror:** tag that SHA and verify branch/tag identity on both remotes. Treat the tag as release identity; mark the release deployable only after its artifact passes validation.
4. **Build and qualify:** build the production image once for required platforms, run checks against it, scan it, and publish the immutable digest and release manifest. Build the plugin artifact only when needed.
5. **Deploy and record:** acquire the s1 lock, validate the manifest and recovery prerequisites, migrate with the persistent database lock, switch to the image digest, verify readiness and worker progress, and record the outcome and previous release.

Steps 3 and 4 can run independently after the merged commit is fixed; both must succeed before deployment. This design preserves existing safeguards while removing repeated manual bookkeeping and host-side build variance.

## Existing controls worth preserving

The project already has parameterized database access across the reviewed sensitive paths, role checks and CSRF protection, mandatory administrator MFA, authentication-version revocation, audit logging, hardened download handling, image normalization, DNS-pinned geocoding requests, separate worker identities, encrypted outbound queues, `SKIP LOCKED` work claiming, hashed calendar tokens, dependency lockfiles, pinned container/action references, and a substantial test suite. The main application also uses manifest-backed static assets and closes sessions early on several expensive routes.

The highest-value work is to make these controls consistent at state transitions and operational boundaries. A framework rewrite, wholesale microservice split, or blanket shared cache is not justified by the evidence collected.

## Validation performed and limits

- PHP's main non-integration test runner passed. Its database and socket suites were explicitly skipped in that run, rather than counted as passing.
- JavaScript syntax checks passed; all **22 JavaScript tests** passed.
- After the requested dashboard-link adjustment, PHP syntax validation and the existing dashboard and booking-inquiry feature checks passed; `git diff --check` was clean.
- All **four PHPStan configurations** passed with debug execution to avoid sandbox process/socket restrictions.
- A disposable internal Docker network and MySQL instance applied the project's **55 SQL migrations** and configured worker/application grants. **Eight database integration suites** passed: follow-up tasks, booking inquiry, inbound email, user lifecycle, financial tracking, engagement contacts, engagement lifecycle, and archive/calendar.
- IMAP and SMTP protocol suites passed when rerun with the local socket access they require.
- Mattermost plugin `go test -race ./...` and `go vet ./...` passed.
- Locked Composer and npm audits reported no advisories. `govulncheck` reported **no reachable vulnerabilities and no affected imported packages**. It also reported three advisories in an uncalled part of the `golang.org/x/crypto` module: [GO-2026-6355](https://pkg.go.dev/vuln/GO-2026-6355), [GO-2026-6354](https://pkg.go.dev/vuln/GO-2026-6354), and [GO-2026-5932](https://pkg.go.dev/vuln/GO-2026-5932). The SSH fixes are in `v0.56.0`; the unused OpenPGP package is unmaintained. Treat these as dependency maintenance, not demonstrated plugin exploits. The local Go scan used Go 1.27.1; CI selects the toolchain from `go.mod`.
- Targeted experiments reproduced the stale enrollment, lost advisory lock, recipient-query fanout, and organizations pagination plan described above. Synthetic timings are single local observations, not production benchmarks.

At the original review stage, the full production HTTP/deployment/restore matrix was not executed. Production traffic distributions, database cardinalities, host capacity, mailbox-provider behavior, and live secret configuration were not inspected. Dependency scans do not establish that all container operating-system packages are safe. This final section records original-review validation only. Current implementation checks and remaining limits are listed at the top of this report.
