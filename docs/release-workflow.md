# Release and recovery workflow

The standing [database-backup directive](../.cursor/rules/backup-before-database-upgrade.mdc) applies to automated and manual deployment. Major schema changes and database-version changes require a new, verified backup **before** the application checkout/version, database image, or schema advances. The s1 command performs this for every deployment, avoiding ambiguity over which migrations count as major.

## Prepare and merge

1. Fetch current `origin/main` and work on a feature/release branch. Run `scripts/prepare_release minor --base-ref origin/main` near release preparation. The project vocabulary is `minor` = x.y.(z+1), `major` = x.(y+1).0, `super` = (x+1).0.0. Repeating the same preparation does not allocate another version. `scripts/prepare_release check` is read-only.
2. If the plugin changes, add `--plugin-bump minor` (or the intended project bump). `mattermost-plugin/plugin.json` is authoritative; the Makefile and compiled client User-Agent derive their version from it.
3. Review and authorize the PR merge. `scripts/merge_pr.sh PR_NUMBER` requests squash auto-merge for the observed PR head. Repository auto-merge must be enabled by an owner if this optional feature is used. No administrator bypass, reviewer requirement, or merge queue is added. Keep the six required checks and strict up-to-date protection.
4. Wait for **CI on the final merged main SHA**, including `release-image`. That job builds or reuses commit-tagged application, ingress, database and Proton Bridge images, scans their software inventories, exercises the application image, rehearses the native backup/restore gate on its disposable database, and uploads `release-SHA`. Only a successful run qualifies its manifest. Registry access errors stop a retry instead of rebuilding over a possibly existing tag.
5. Download that run's `release-SHA` artifact. On local main at that exact SHA, run `python3 scripts/mirror_release.py /path/to/manifest.json --publish --output /path/to/mirrors.json`. This creates the annotated `vVERSION` tag on the merged SHA and pushes/verifies main and the tag on both `origin` and `gitlab`. Retries preserve completed pushes. A conflicting version/tag is an error; never force it or allocate a replacement version merely to retry a mirror outage.

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
