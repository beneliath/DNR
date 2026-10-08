# Account preview operations

The local preview uses one public endpoint, `http://localhost:8080`, and a
private deployment per Account at `/a/<address-label>/`. Shalom in Messiah
holds the platform directory and canonical SuperAdmin identities.

Run `python3 scripts/provision_local_account.py --watch` from the repository
to process creation and lifecycle requests. It holds an exclusive local lock;
run only one worker. This is a local development worker, not a production
provisioner. It rejects a primary ingress not bound to the expected loopback
port. Its current log is `var/deployment/account-provisioner.log`.

New local Accounts inherit the primary's Amazon map settings and read-only
browser key mount. To update an existing member, briefly stop the local Account
worker, run `python3 scripts/provision_local_account.py test-account --refresh-map`,
then resume the worker. This recreates only the member's web container, preserves
its previous Compose file, and does not run migrations or change Account data.

SuperAdmins can archive, restore, and delete member Accounts from Accounts.
Actions require CSRF protection and recent administrator verification.
Administrator Preview Access cannot perform them. The primary Account is
protected because its directory and identities are required by every member.

- Archive immediately disables new shared sign-ins and switch tickets. The
  worker blocks the Account path, stops its containers, then marks it Archived.
  Its database, files, configuration, and usernames remain reserved.
- Restore revokes pre-archive sessions, restarts the saved services, and reopens
  the route. Existing users must sign in again.
- Delete requires an archived Account and its exact address label. Before
  removing the private containers, volumes, networks, and configuration, the
  worker creates and restore-verifies an encrypted database/files backup and
  verifies a separate encrypted configuration archive. These recovery copies
  remain under `var/backups/account-deletion/<label>/`; they use the primary
  `secrets/deployment_backup_password`. A durable receipt supports interrupted
  deletion. A directory tombstone reserves the address label permanently.
- A failure preserves the pending state and displays Needs Attention with
  Retry Action. The worker does not repeatedly attempt a failed destructive
  operation. Audit events record requests and completion.

SuperAdmins see the same complete Account directory from every Account, with
the active Account highlighted. They can submit Create Account from any Account
without switching first. Member deployments forward creation to the primary
using the canonical SuperAdmin identity and a primary-encrypted proof of recent
password/MFA verification. The proof is bound to that member Account, identity,
authentication version, and unlock deadline; Add 5 Minutes renews it centrally.
Locking actions or changing Preview Access clears it. New Account requests
appear in the shared directory while the local worker prepares them.

Local verification covered authorization, elevation, CSRF, the primary Account
guard, archive blocking existing sessions and public links, restoration of
records/files, session revocation, typed deletion confirmation, encrypted
backup verification, and removal of a disposable deployment. The initial
configuration backup check caught macOS AppleDouble entries; the archiver now
excludes that metadata and the resumed deletion passed.

The lifecycle schema was applied only after fresh encrypted, restore-verified
backups of all three existing local databases. Receipts are under
`var/backups/account-lifecycle-schema*` and include hashes, times, images,
versions, and migration state. Applied migrations must remain immutable.

Database exports target the current deployment's unique exporter hostname and
reject a mismatched Account key. The primary export also contains the platform
directory/routing metadata and remains restricted to SuperAdmin.

Shared-mail routing and its SuperAdmin-only Mail Review page are enabled in
the three local Accounts after explicit user authorization. The queue migration
and restricted worker grants were applied after fresh encrypted, restore-verified
backups. See [local mail verification](account-mail-local-preview.md). Live IMAP
polling remains disabled locally; tests import synthetic messages without sending
or fetching external mail.

Production routing/provisioning, common password recovery, and production mail
integration still require completion and review before s1 deployment.
The local Shalom Coach catalog currently has a separate-worktree mount whose
source contracts suppress three walkthroughs; this was diagnosed against s1,
not disabled by Account permissions.
