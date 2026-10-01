# MOED data recovery

The original recovery target was one hour. The live s1 schedule inspected on
October 1, 2026 runs every four hours at eleven minutes past, and its configured
freshness limit is **four hours and thirty minutes**. The external monitor follows
that live limit; changing the limit requires reviewing the backup cadence together.
The existing nightly s1 bare-metal backup remains in place. Its root-only backup
script and full machine restore have not been independently verified here.

## Live configuration (October 1, 2026)

Encrypted recovery snapshots run on s1 every four hours and copy directly over
encrypted SMB3 to `192.168.1.18/MOED_Backups`. A five-minute freshness check reports
failure through syslog (`moed-recovery`) if the last verified **snapshot time** is
older than the configured limit or the latest attempt failed. A separate five-minute
external monitor now emails failure and recovery notifications to
`admin@beneliath.com` using verified TLS SMTP. Its verification email was accepted
by the SMTP server on October 1. Failures repeat at most every six hours; failed
deliveries retry on the next check. Configuration is cached privately so a stopped
mail-dispatch container does not prevent the host monitor from using SMTP.

The dedicated `moed_backup` account has SMB access and read/write permission only
to this share, explicit denial of the other 27 shares, and no DSM or administrator
access. The Btrfs share uses checksums, hidden enumeration and an administrator-only
recycle bin. Credentials remain in the owner's mode-600 file
`/home/dgilmore/.config/moed-backup/nas.credentials`; they are never in the repository.

The helper bundle is installed at `/home/dgilmore/.local/lib/moed-recovery/v1/`.
Its user crontab preserves existing entries, with the original saved privately in
`~/.local/state/moed-backup/crontab-before-recovery.txt`.

The first full online recovery set was verified and published as
`20260928T021510Z-90981eee-6fac0bd643edee15`. The check reported a four-minute snapshot
age before the schedule was enabled. The earlier manually replicated release
backup remains separate from the scheduler's retention inventory.

## What each run verifies

1. Check the separately protected backup-key copy still exists on the NAS with its
   recorded hash and matches the current backup key's local coverage receipt.
2. Hold the deployment lock to exclude schema/release changes. Require InnoDB
   tables, then capture a consistent transactional database dump while users work.
3. Restore that encrypted dump into an isolated, network-disabled temporary database.
   Record its schema/data fingerprint and extract the file inventory from the restore.
4. Hold the file lifecycle lock against garbage collection while capturing precisely
   those immutable uploaded files. Decrypt and verify their content hashes.
5. Encrypt the deployment configuration, runtime secret files (including the complete
   application key/keyring), tracked application source and release image identities.
   Decrypt and verify the configuration/source archives without writing plaintext
   archives on the host.
6. Upload to a unique pending NAS directory, download every archive and receipt,
   compare hashes, and publish only after all checks pass. A failed run retains
   previous good copies. A 20-minute deadline and a job lock bound overlap.

The backup decryption key has its own NAS copy encrypted with the operator's
independent recovery passphrase. The operator entered that passphrase privately,
verified decryption, and was instructed to save it in a password manager outside
s1 and the NAS. The passphrase is not stored by the scheduler. Losing it and s1's
original key would make these encrypted copies unrecoverable.

Keep every scheduled copy for seven days, then the newest copy per UTC day through
30 days, always keeping at least two copies. Pruning only affects replicas recorded
by this scheduler, validates their receipts, and journals interrupted deletion.
Four verified local snapshots are retained. Manual backups and protected key
archives are not automatically deleted. Failed pending uploads may need manual
inspection and cleanup. The backup account can delete its own share's files;
separate NAS snapshots or immutable/off-site copies remain a useful additional
protection against account compromise and loss of the premises.

## Operator checks

On s1:

```sh
python3 ~/.local/lib/moed-recovery/v1/run_nas_recovery.py --check
python3 ~/.local/lib/moed-recovery/v1/recovery_monitor.py --check-only
journalctl -t moed-recovery --since '2 hours ago'
journalctl -t moed-recovery-monitor --since '2 hours ago'
```

To run one backup immediately, omit `--check`. Do not reinstall the schedule on
every run; its installer deliberately refuses duplicate managed entries. Inspect
`~/.local/state/moed-backup/status.json` privately for last success/error and
`replicas.json` for the managed inventory. Review capacity and error logs regularly.
After changing the backup encryption key, rerun `recovery_key_setup.py` interactively
before expecting scheduled backups to resume successfully.

## Recovery drill and remaining limits

`scripts/recovery_restore_drill.py` now automates a replacement application restore:
it authenticates archive checksums, restores and fingerprints a fresh database,
checks every persistent file, restores source/migrations/configuration/application
keys, authenticates every encrypted application record, and verifies password/MFA
sign-in and record pages with a synthetic administrator. Its labelled containers
and fresh volumes use an internal network, expose no host ports, and are removed
after the drill. A synthetic integration rehearsal passed on October 1; restore
and application verification took about 17 seconds for that small fixture. That
timing is not an estimate for the production dataset.

The authorized production NAS drill passed on September 30, 2026 at 9:54 p.m.
America/Chicago (October 1 UTC). The database fingerprint matched; all 100 stored
files matched their recorded sizes and hashes; encrypted application records,
password/MFA sign-in and record pages passed. The successful attempt took 49.1
seconds for restore/application checks and 74.0 seconds including transfer. The
backup key came from s1, and the exact production image contents were imported
from s1: this did not simulate losing s1 or recovering the separately protected
backup key using the operator's passphrase. Docker assigned different image IDs
on import; the drill used the imported IDs through its existing image overrides.
All private backup/key/configuration/source copies and isolated Docker resources
were removed, with cleanup independently checked. The sanitized result is saved
in [data-recovery-drill-2026-09-30.json](data-recovery-drill-2026-09-30.json).

On an explicitly approved isolated host containing a verified recovery set and
the privately recovered backup key:

```sh
python3 scripts/recovery_restore_drill.py /private/recovery-set \
  --password-file /private/recovered-backup-key --report /private/drill-report.json
```

The existing SMB backup account cannot administer DSM snapshots, and no separate off-site
destination has been supplied. Snapshot/immutable and off-site protection are
therefore not claimed as configured. Use NAS Snapshot Replication with retention
outside the backup account's deletion authority, and a separately credentialed
encrypted off-site destination; test retrieval before reporting either as healthy.

The external monitor publishes sanitized recovery status to Operations once the
data-management schema is deployed on s1. Until then, publication is unavailable
and SMTP monitoring continues independently. Reports older than fifteen minutes
are labelled overdue in Operations.

Copy a completed NAS directory and its referenced protected key archive to an
isolated recovery host. Check `replica.json` hashes before decryption. Recover the
backup key with the independently saved passphrase using the native backup crypto
format; recover deployment configuration and application keys with that key.
Restore the database and uploaded-file archives using the recorded release image
identities/source and the application's native backup tooling. Do not expose the
recovery application publicly or enable email/inbound workers during a drill.
Verify encrypted records, administrator login, representative downloads and record
relationships before promoting a restored environment. Record elapsed recovery time.

Every scheduled run tests database restoration and file integrity before transfer,
and verifies identical bytes after transfer. Synthetic integration tests also
exercise concurrent writes and protected-key recovery. A complete replacement-host
restore from NAS, including login and deployment dependencies, passed in the
authorized Mac drill described above. Representative authenticated downloads and
independent production backup-key recovery remain manual recovery checks. Image
identities and source are preserved, but container image layers
are not exported: registry/base-image availability or a rebuild is still required.
The recovery target depends on successful ongoing runs and response to failures;
a schedule alone cannot guarantee it during an outage.
