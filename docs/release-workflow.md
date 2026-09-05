# Release and recovery workflow

The standing [database-backup directive](../.cursor/rules/backup-before-database-upgrade.mdc) applies to automated and manual deployment. Major schema changes and database-version changes require a new, verified backup **before** the application checkout/version, database image, or schema advances. The s1 command performs this for every deployment, avoiding ambiguity over which migrations count as major.

## Minor bump deployment shorthand

Going forward, the instruction **"do a minor bump deployment"** means:

> Do the following in order: increase the patch version from x.y.z to x.y.(z+1). Commit all pending project changes, including the version bump, on the current working branch. Merge that branch into main, then push main to every configured remote. After all pushes succeed, trigger the established s1 (192.168.1.150) deployment workflow for that exact main commit. Monitor the deployment through completion and report the result.

Invoking this shorthand authorizes the complete sequence, including the merge, all configured remote pushes, and the s1 deployment, without separate confirmation for each step. The project's word `minor` means the patch increment `x.y.(z+1)`. All pending project changes includes modified files, deletions, and untracked files. Every configured remote currently includes `origin` and `gitlab`; resolve the full set from Git configuration when executing the request.

Follow the detailed workflow below, preserving its protected merge checks, successful final-main CI, release-tag publication, remote verification, and verified backup before upgrade. Commit the pending work and version bump on the working branch before merging. Deploy only the exact final merged `main` SHA after all required pushes and checks succeed. If a required stage fails, report it and resolve it before deployment. Monitor through completion and report the release version, deployed SHA, remote synchronization, and deployment/readiness result.

The persistent [minor bump deployment rule](../.cursor/rules/minor-bump-deployment.mdc) applies this definition to future action requests. Quoting, discussing, or defining the shorthand does not itself invoke deployment.

## Major bump deployment shorthand

Going forward, the instruction **"do a major bump deployment"** means:

> Do the following in order: increase the patch version from x.y.z to x.(y+1).0. Commit all pending project changes, including the version bump, on the current working branch. Merge that branch into main, then push main to every configured remote. After all pushes succeed, trigger the established s1 (192.168.1.150) deployment workflow for that exact main commit. Monitor the deployment through completion and report the result.

The numeric transition is authoritative: the project's `major` bump increments `y` and resets `z` to zero, producing `x.(y+1).0`. Use `scripts/prepare_release major --base-ref origin/main` to prepare this version.

Invoking this shorthand authorizes the complete sequence without separate confirmation for each step. The scope of pending changes and configured remotes, protected merge checks, successful final-main CI, release-tag publication, remote verification, verified backup before upgrade, failure handling, and monitoring/reporting requirements are the same as for the minor bump deployment above. Deploy the exact final merged `main` SHA to s1 at `192.168.1.150` only after all required pushes and checks succeed.

The persistent [major bump deployment rule](../.cursor/rules/major-bump-deployment.mdc) applies this definition to future action requests. Quoting, discussing, or defining the shorthand does not itself invoke deployment.

## Super bump deployment shorthand

Going forward, the instruction **"do a super bump deployment"** means:

> Do the following in order: increase the patch version from x.y.z to (x+1).0.0. Commit all pending project changes, including the version bump, on the current working branch. Merge that branch into main, then push main to every configured remote. After all pushes succeed, trigger the established s1 (192.168.1.150) deployment workflow for that exact main commit. Monitor the deployment through completion and report the result.

The numeric transition is authoritative: the project's `super` bump increments `x` and resets both `y` and `z` to zero, producing `(x+1).0.0`. Use `scripts/prepare_release super --base-ref origin/main` to prepare this version.

Invoking this shorthand authorizes the complete sequence without separate confirmation for each step. The scope of pending changes and configured remotes, protected merge checks, successful final-main CI, release-tag publication, remote verification, verified backup before upgrade, failure handling, and monitoring/reporting requirements are the same as for the minor bump deployment above. Deploy the exact final merged `main` SHA to s1 at `192.168.1.150` only after all required pushes and checks succeed.

The persistent [super bump deployment rule](../.cursor/rules/super-bump-deployment.mdc) applies this definition to future action requests. Quoting, discussing, or defining the shorthand does not itself invoke deployment.

## Deployment notice and save window

At the **beginning** of any minor, major, or super bump deployment workflow, before version preparation or Git/CI work, run:

```sh
./scripts/deployment_notice.sh start
```

This publishes a notice on s1 at `192.168.1.150` and remembers the workflow ID in the local Git directory. The server sets one deadline, five minutes after publication. Continue the prepare/merge/CI/mirror steps below while the timer runs. Repeating `start` from the same checkout reuses the notice and its original deadline. Another active workflow cannot replace it.

Authenticated application pages display a persistent red banner with white text above the content, stacked with the View As banner. The background uses a stronger red in both light and dark themes. It polls every five seconds without opening or extending a login session, and shows a normal `5:00` countdown based on server time. New pages/tabs get the remaining time, not a new five-minute window. At zero, the message becomes **“Update pending — please save your work. Deployment will begin shortly.”** The application remains usable during preparation. Only when s1 starts maintenance does it say **“Deployment in progress.”** Forms are never automatically reloaded, submitted, or cleared by the banner.

After image pulls and preflight, immediately before stopping writers and taking the verified backup, the s1 command enforces `max(0, notice deadline - current server time)`. For example, three minutes of preparation means a two-minute wait; eight minutes of preparation means no additional wait. The timer reaching zero never bypasses CI, remote verification, or backup requirements. The public ingress serves the read-only notice independently of the web/database containers, keeping it available during maintenance.

`./scripts/deploy_s1.sh EXPECTED_MAIN_SHA` automatically reuses the remembered notice. If invoked directly without a notice, it starts one and enforces the full remaining save window. When continuing from a different checkout, set `DNR_DEPLOYMENT_NOTICE_ID` to the ID printed by `start` (or pass it as the second argument to the notice command). IDs survive ambiguous SSH failures so retrying cannot reset the timer.

If preparation fails, is abandoned, or is cancelled before invoking the s1 command, cancel the notice explicitly:

```sh
./scripts/deployment_notice.sh cancel
```

Use `./scripts/deployment_notice.sh status` to inspect the remote state. The s1 command automatically cancels a pending notice when its preflight fails and clears a completed notice after success. Once maintenance begins, cancellation cannot clear it: a failed deployment displays **“Update needs attention”** and requires the recovery procedure below. After recovery has been verified, run `./scripts/deployment_notice.sh resolve NOTICE_ID` to clear the notice; this refuses while a deployment still holds the host lock. Do not use resolution to bypass recovery.

An abandoned pending notice expires after six hours. Expiration clears its banner but also makes it ineligible for deployment; cancel it and start a new workflow to provide a fresh warning. Failed or active maintenance notices do not expire silently. The state is stored in the ignored `var/deployment/` directory, mounted read-only into web and ingress; only SSH deployment tooling writes it. The public status endpoint exposes only the global notice and timing, with no user, credential, source SHA, or recovery information.

The first rollout of this feature cannot warn browsers running the older code. The new images and notice mounts must be deployed, and users must load a page containing the banner code, before subsequent deployments can display it. Sleeping or offline browsers see current status when they reconnect; the server always enforces the shared deadline.

## Prepare and merge

1. Start the deployment notice above, then fetch current `origin/main` and work on a feature/release branch. Near release preparation, run `scripts/prepare_release minor --base-ref origin/main` for a minor bump deployment, `scripts/prepare_release major --base-ref origin/main` for a major bump deployment, or `scripts/prepare_release super --base-ref origin/main` for a super bump deployment. The project vocabulary is `minor` = x.y.(z+1), `major` = x.(y+1).0, `super` = (x+1).0.0. Repeating the same preparation does not allocate another version. `scripts/prepare_release check` is read-only.
2. If the plugin changes, add `--plugin-bump minor` (or the intended project bump). `mattermost-plugin/plugin.json` is authoritative; the Makefile and compiled client User-Agent derive their version from it.
3. Commit all pending project changes, including the version bump, on the current working branch before merging. Publish the working branch as needed for the protected PR workflow.
4. Review the PR and merge when authorized; invoking any of the deployment shorthands above supplies that authorization. `scripts/merge_pr.sh PR_NUMBER` requests squash auto-merge for the observed PR head. Repository auto-merge must be enabled by an owner if this optional feature is used. No administrator bypass, reviewer requirement, or merge queue is added. Keep the six required checks and strict up-to-date protection.
5. Wait for **CI on the final merged main SHA**, including `release-image`. That job builds or reuses commit-tagged application, ingress, database and Proton Bridge images, scans their software inventories, exercises the application image, rehearses the native backup/restore gate on its disposable database, and uploads `release-SHA`. Only a successful run qualifies its manifest. Registry access errors stop a retry instead of rebuilding over a possibly existing tag.
6. Download that run's `release-SHA` artifact. On local main at that exact SHA, run `python3 scripts/mirror_release.py /path/to/manifest.json --publish --output /path/to/mirrors.json`. This creates the annotated `vVERSION` tag on the merged SHA and pushes/verifies main and the tag on both `origin` and `gitlab`. If additional Git remotes are configured, also push and verify that exact `main` SHA on each of them before deployment; the mirror script currently handles only `origin` and `gitlab`. Retries preserve completed pushes. A conflicting version/tag is an error; never force it or allocate a replacement version merely to retry a mirror outage.

The publishing job alone receives `packages: write`; other CI jobs retain read-only repository access. The s1 account needs read access to the GHCR packages. Run `docker login ghcr.io` on s1 with an appropriately scoped read-only credential before the first deployment. Runtime support is reviewed quarterly through `config/runtime-support.json`; Dependabot continues checking dependency and base-image pins.

## Deploy

Configure a private password file on s1 containing at least 16 characters; the default is `/home/dgilmore/moed/secrets/deployment_backup_password`. Preserve this password independently of the host. It encrypts native deployment recovery archives; it is separate from database credentials and the temporary `backup_password` file used for browser-format restores. Set `DNR_S1_BACKUP_PASSWORD_FILE` to override its absolute path.

From a clean local main at the qualified, mirrored SHA:

```sh
./scripts/deploy_s1.sh "$(git rev-parse HEAD)"
```

The command downloads the manifest from successful final-main CI and rechecks both remote branch/tag identities. The remote command holds `.git/dnr-deploy/deploy.lock` throughout preflight, backup, upgrade, and public readiness verification. Before any checkout change it fetches and checks the requested main SHA and validates migration checksums and image provenance.

It pauses web, geocoder and both mail writers, then:

- Creates a native consistent SQL/gzip backup, including BLOBs, routines, triggers and events, using the running database's client.
- Restores it into a disposable database using the **current database image ID**, with no network access or published ports. Compares table checksums and row counts, migration ledger, routines and triggers against the source.
- Encrypts the archive with Argon2id and XChaCha20-Poly1305, decrypts a verification copy and checks its checksum. Temporary plaintext and verification containers/volumes are removed. Private logs and encrypted archives remain under `.git/dnr-deploy/backups/`; directories are owner-only.
- Records the backup checksum/time, previous application SHA/version, database image/version and migration state, plus the qualified new manifest and both remote SHAs.
- Only after successful backup verification, fast-forwards the checkout to the exact requested SHA, updates the database, applies migrations on one persistent locked connection, and starts the qualified image digests with `--no-build`.
- Checks image identity, schema health, recent successful worker passes, and public readiness before marking the release successful.

Reserve disk space for the compressed SQL, encrypted copy, verification copy, and disposable database volume. Database-native backup capacity is independent of the browser's 512 MiB export limit. A failed backup blocks the upgrade; a failed migration or readiness check leaves an explicit recovery record and pauses writers. Migration ledger states `applying`/`failed` require operator review and are never silently replayed.

The Compose wrapper still supports explicit development/source builds. Manual database upgrades must follow the same backup directive; a bare Compose command is not a replacement for the guarded deployment procedure.

## Recovery

Inspect `.git/dnr-deploy/SHA.json` and the backup's `receipt.json`. Keep the encrypted pre-upgrade archive through deployment and recovery verification, then copy it to protected off-host storage under the deployment's retention policy. There is no automatic destructive retention cleanup.

For a schema-compatible application failure, a reviewed previous-image promotion may be possible. For a database-version change or incompatible DDL, restore the verified native backup into a **fresh volume with its recorded database image**, then point the previous application release at that restored database. Never run an older MySQL binary on an upgraded data directory. Prefer a forward fix when that preserves data written after deployment; restoring the pre-upgrade snapshot discards later writes.

Native archives use the `.sql.gz.dnrenc` suffix. They are encrypted SQL/gzip, not the JSON format accepted by the browser backup restore command. Decrypt with `/opt/dnr/bin/native_backup_crypto.php decrypt INPUT OUTPUT` in the recorded/compatible application image, mounting the deployment password at `/run/secrets/backup_password` and an owner-only writable recovery directory. Then decompress and import with the recorded database image's `mysql` client. Verify the recorded file and table checksums, row counts, migration ledger and application readiness before reopening writers. Retain the recorded database image (or an exported copy) with the recovery set; a local image ID alone is not a registry download address.

## Capacity and storage

The web image permits three PHP workers at 512 MiB each, a 1.25 GiB backup/upload tmpfs, and a 4 GiB / 2 CPU / 128 PID container envelope. Maintenance uses 2 GiB; workers use 768 MiB / 1 CPU / 128 PIDs. These bounds leave room for the shared runtime and encryption workspace; tune them only with measured workload and host capacity.

Browser backups default to 512 MiB of serialized data and expose an approximate size warning. The existing backup format is retained, but base64 rows are written in small chunks and restore bindings release old BLOB values. Downloads read 1 MiB chunks from a consistent snapshot. PDF uploads remain bounded at 100 MiB and still validate in memory.

Private object/file storage was evaluated: it could reduce database/backup growth, but would require transactional asset manifests, authorization-preserving streams, garbage collection, and coordinated database-plus-assets restoration. Keep database storage for this release while measuring growth; use native encrypted deployment backups beyond the browser export budget. The new limits do not imply unlimited attachment capacity.
