# Storage and delivery operations

## Integration tests

Run `sh scripts/run_integration_tests.sh disposable`. The runner creates a random
`dnr-test-*` Compose project, new credentials, private notice files, and labeled
volumes. It verifies ownership before running fixtures and destroys only its own
project afterward. Existing localhost databases and uploaded files are not test
targets. To reuse already-built images, set `DNR_TEST_APP_IMAGE`,
`DNR_TEST_INGRESS_IMAGE`, and `DNR_TEST_DATABASE_IMAGE` explicitly. Tests remain in
fresh volumes even when images are reused.

## Uploaded files and capacity

PDFs, PowerPoints, and portraits remain immutable files in `uploaded_files`.
The database holds references, metadata, and checksums. Replacing or removing a
file detaches its reference; it does not immediately delete the old bytes.
Database Backup shows current and retained byte totals. Its archive-size warning
still includes the base64 expansion of retained files. Use the native encrypted
backup when data exceeds the browser archive limit; do not silently raise the
limit without budgeting exporter memory and temporary space.

The `file-monitor` service runs a bounded checksum pass every minute, carries its
cursor and outstanding failures across restarts, and probes actual write/fsync
access. Readiness fails if this monitor is stale, files fail verification, or
free storage falls below 128 MiB (or a higher `DNR_STORAGE_MIN_FREE_BYTES`). Check
`docker compose logs file-monitor` and Database Backup for details. Restore a
missing/corrupted file from a verified backup; do not edit a checksum to hide it.

Cleanup is an operator action, never a web request. First create and verify a
backup. Preview with:

```sh
docker compose run --rm --no-deps --entrypoint php maintenance /opt/dnr/bin/maintain_file_storage.php --prune
```

Add `--apply` to record the first observation of unused files. No newly observed
file is deleted. Subsequent approved runs can delete files observed unused for
at least 30 days. Preview again before applying deletion. Any current database
reference preserves the file. Uploads, downloads, restores and backups hold
shared lifecycle locks; cleanup requires an exclusive lock and refuses a busy
volume. The grace inventory is stored privately on that volume. If it is lost,
cleanup starts a new grace period. Do not remove files directly from the volume.

Keep pre-upgrade encrypted archives through recovery verification. Cleanup of
the current volume does not remove file copies inside those archives. Restore
the matching encrypted file archive alongside a native SQL restore.

## Encryption and production deployment

Native backups now pipe SQL and file archives directly into authenticated
secretstream encryption. Restore verification decrypts through pipes into an
isolated database and bounded file verifier. No SQL dump or file archive is
staged as plaintext on the host. Legacy unfinished plaintext staging artifacts
are removed only within the backup tool's own generated backup directories.
A private random database password used by the verification container is removed
in `finally`; abandoned labeled verification containers are removed next run.

MySQL tablespace encryption does not encrypt the separate uploaded-files volume.
Production deployment therefore checks the *actual* uploaded-files mount and its
block-device ancestry for dm-crypt before any save countdown or writer shutdown.
LVM alone does not satisfy this check. Unknown storage arrangements fail closed.
The deployment receipt records the verified backing device.

S1 requires an infrastructure change if this check fails. Provision an encrypted
block device with tested unlock/recovery keys, take a fresh restore-verified
backup, pause writers, copy the existing uploaded-files volume onto that device,
verify every registered size/checksum and ownership, and mount it at Docker's
configured volume location. Verify a reboot/unlock and a restore before retiring
the old copy. Do not reformat or relocate a live production disk as part of an
application deployment. Hardware/provider encryption needs independent evidence
and an explicit preflight implementation; an environment flag cannot bypass this
requirement. Local development is not subject to the Linux production disk check.

## Download capacity

Ingress sends authenticated presentation assets and anonymous QR-file downloads
to `downloads`, a separate bounded PHP pool with read-only file access. Login
sessions are shared with `web` in a private tmpfs volume; database authorization,
QR revocation, range requests, filenames, and visit-count semantics remain in the
application. Uploads, editing, and normal navigation use `web`. The regression
suite holds all 20 download workers with slow clients while checking normal web
responses. This is origin isolation, not a guarantee about venue bandwidth or
unlimited client concurrency. Direct requests to the internal app port bypass
this routing; publish only ingress.

## Uncertain mail delivery

Every claimed account email, digest, and correspondence delivery has a unique
claim token. A stale worker cannot complete or fail a newer claim. A stable
Message-ID is retained across retries for provider investigation; it does not
promise exactly-once SMTP delivery.

A definite SMTP rejection before acceptance follows the existing retry policy.
Transport loss after DATA, a crash after sending starts, or failure to save the
accepted result puts the delivery into `delivery_uncertain`. It is never retried
automatically. Check provider logs or the recipient before resending. The outbound
message page displays the provider reference and requires acknowledgement before
retrying uncertain correspondence. Operations counts uncertain correspondence.
For account links, request a fresh link through the normal account workflow after
checking delivery. For a digest, inspect the worker queue and provider log; a
later day's digest is independent. Preserve the queue record for investigation.
