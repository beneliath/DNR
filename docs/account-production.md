# Account production rollout (not executed)

The branch contains an operator worker in `scripts/account_production.py` and an
inert service template in `deploy/moed-account-worker.service`. Nothing installs
or starts it automatically. Its default invocation renders a plan and does not
contact Docker, change a deployment, or apply migrations. `--apply` executes one
cycle; `--apply --watch` runs the durable worker. Only queued SuperAdmin operations
are executed. The web containers never receive the Docker socket.

## Required release preparation

Copy `deploy/account-production.example.json` to an owner-only configuration
outside Git. Replace every placeholder, including immutable image digests and
release commit. Use the actual primary router/network/container names, primary
backend subnet, primary ingress IP and exact trusted proxy addresses; example
network names are not discovery. SMTP must be
reachable over TLS from each private Account's egress network. Do not point at a
primary-only internal bridge hostname. Keep SMTP disabled/log-only in rehearsals.

Run `python3 scripts/account_production.py <config>` to review the generated
primary overlay. During the separately authorized rollout preparation, use
`python3 scripts/account_production.py <config> --prepare-primary` to save the
reviewed configuration under `var/deployment/account-production/`. Preparation
writes configuration files only; it does not start services or migrate a database.
The normal production Compose wrapper includes the saved `primary.compose.json`
on every release, and its optional notes-cache companion when caching is enabled.
An enabled deployment without that saved configuration fails release preflight.
The release transaction retains the previous Account configuration in its recovery
record and refreshes the saved release commit/image manifest inside the writer
pause, after backup verification. Start the worker with the saved `config.json`
(as in the service template), so it reloads the qualified manifest each cycle.
Release preparation also retains an existing primary control proxy's edge IP as
`primary_control_edge_ip`. The generated overlay reserves that address so Docker
cannot reassign the primary ingress's static address to the recreated proxy.
For a new installation, this optional field can be set to a verified free IPv4
address on the edge network, distinct from Traefik and ingress.

The overlay replaces the old host-wide router rule with the explicit
primary path and adds public legacy/common entry points. Preserve the existing
primary services, volumes, secrets, database, encryption keys and image settings.
Set the same canonical `DNR_PUBLIC_BASE_URL=https://<host>/a/shalom-in-messiah` in
the primary Compose environment, including optional notes-cache/mail workers.
Set the primary Account flags explicitly only during the approved release.
Account paths use exact boundaries; a root URL does not select an Account.
Common sign-in and recovery remain at the root. Existing root calendar, signed
short links, public notes/slidedecks, invitation, verification, health and Mattermost
API URLs are retained. Legacy invitations post to the canonical Account path and
continue MFA there, including forms already open before the transition.
Account Settings, backups and authenticated record pages require the Account path.
Unknown routes return to common sign-in.

Before any production change, stop the Account worker, publish the usual save
notice, pause writers, take a NEW encrypted database/files/configuration backup,
and restore-verify it. Run the normal ordered migrator and grants; no applied
migration is edited. The prepared primary overlay is included through that release
transaction. The worker shares its deployment lock with `deploy_release_host.py`,
so it cannot provision, deliver or remove an Account during primary migration.
Do not enable automatic image replacement/Watchtower for any Account.

The web and backup containers remain on their private internal networks. Each
deployment has an `account-control` relay with no application secrets or published
port. It permits CONNECT only from that deployment's backend subnet to the exact
configured HTTPS origin/port, pinned to the Traefik IP. It does not decrypt TLS;
PHP still validates the origin certificate and hostname end to end. Other hosts,
ports, plaintext requests and clients on the shared edge are denied. The primary
trust list includes both Traefik and its immediate ingress proxy. Qualify the
actual edge certificate and routing before enabling the worker. See Apache's
[CONNECT module](https://httpd.apache.org/docs/2.4/mod/mod_proxy_connect.html).

### Shared-domain browser and cookie protections

Keep the existing domain and canonical `/a/<account-label>` paths. No account
subdomains or DNS changes are required. Traefik strips the selected Account path
before forwarding to that Account's trusted ingress. The generated ingress
policy forwards only that Account's named session cookie and the application's
non-authentication preference/download cookies to PHP. Response filtering prevents
an Account from setting another Account's session cookie or a domain-wide cookie.
Member sessions use their Account path; the primary session retains `/` for the
common sign-in/recovery endpoints, but member PHP never receives it. Logout
expiry headers are preserved. Neither PHP pool may be exposed around this ingress.

The qualified ingress image serves `/assets/` from its own static release copy.
Its additional CSP allows executable scripts only from that Account's trusted
asset paths, rejects inline script/event handlers and PHP-served scripts, and
cannot be relaxed by an application-supplied CSP. Primary common pages also allow
the trusted root assets. The ingress rejects application attempts to clear
shared-origin browser data or widen service-worker scope. Account browser state
(drafts, navigation, Coach and reimbursement selections) uses namespaces derived
from the browser's actual URL; theme preference remains shared. Old unscoped
browser drafts/preferences are left intact but are not automatically imported
into any Account. This changes browser-local restoration only, not saved records.

These are layered protections, **not separate browser origins**. An arbitrary
JavaScript execution flaw in an allowed trusted asset still has same-origin
capabilities across Account paths. Treat frontend assets and every Account ingress
as trusted platform code, qualify them with each release, and do not permit
tenant-supplied scripts/plugins. A complete containment guarantee for a compromised
frontend cannot be made using path names alone. Validate CSP, cookies, static
asset versions and caching through the actual Traefik/Cloudflare path during the
isolated production-shaped rehearsal; direct-backend checks are insufficient.

## Worker behavior and recovery

New Accounts use unique databases, networks, volumes, session stores, routing
keys, application keys and backup passwords. There are no source bind mounts,
public host ports, development overlays, database copies or shared file stores.
The worker validates immutable image provenance, initializes only an empty
private database, waits for service health and checks the Account identity over
an authenticated HTTPS call before marking it ready. Pending operations resume
with their saved release and configuration. An existing member release is never
silently upgraded by creation retry; upgrading existing Accounts requires its
own writer pause, fresh backup and qualified migration transaction.

Provisioning saves a checksum-protected, resolved `runtime.compose.json` with the
member's exact images, environment and service definitions, including optional
profiles used by active workers. All application services explicitly receive the
Account identity and control-connection environment, including the isolated
backup exporter and workers that do not inherit the main web environment.
Static bind-mounted configuration (Apache/PHP configuration, the application
profile, relay configuration and migration scripts) is copied into the member's
`runtime-config/` directory. The saved runtime records hashes of file contents,
tree membership and permissions, and starts from those copies even on the first
provisioning attempt. A retry reuses the saved runtime.

### Shared Amazon map configuration

All Accounts use the primary deployment's Amazon browser-map configuration.
New member provisioning reads the primary app's effective region, style and
maximum zoom, and mounts the same read-only browser key file into member `web`.
The key is referenced as a Compose secret; its value is never written into the
Account environment, runtime manifest or operator output. Other services and
Account data/encryption keys remain separate. A missing or invalid Amazon key
stops new preparation instead of silently selecting OpenStreetMap. A deployment
that intentionally uses OpenStreetMap continues to work without an Amazon key.

For already-provisioned Accounts, or after changing the primary map settings,
review the configuration update with:

```sh
python3 scripts/refresh_account_map.py var/deployment/account-production/config.json --all
```

During an authorized production update, add `--apply` to update all ready and
archived members, or use `--account test-account` for one member. This operation
shares the deployment lock, preserves the saved release/images and frozen
configuration, and recreates only each ready member's `web` service with
`--no-deps`. It does not run migrations or change account data. Archived members
remain stopped and receive the shared map on their next restore. Finish pending
provisioning/lifecycle operations before updating all members. Repeating a
completed update is a no-op. Key rotation uses the same host secret file for
every Account; recreate web containers after replacing a bind-mounted key file.

Each changed member retains its prior runtime and metadata in an owner-only
`var/accounts/<label>/map-recovery-*` directory. A failed health check restores
that pair and restarts the previous web configuration. If the process is killed
between file writes, stop the Account worker and restore both saved files under
the deployment lock before resuming; a checksum mismatch fails closed.

### Shared MOED mailbox

IMAP is collected once by the primary `mail-ingest` worker using the shared
`moed@beneliath.com` mailbox configuration. Member deployments do not run their
own IMAP collectors or mount the IMAP password. The primary routing queue
delivers messages to the Account identified by a valid routing token; mail with
missing, invalid or conflicting tokens stays in SuperAdmin Mail Review until
explicitly routed or rejected. Sharing the mailbox does not share Account inboxes.

All Account `mail-dispatch` workers use the same SMTP host, port, TLS mode,
username and From address from the platform configuration's `smtp` section and
mount the same `smtp_password_file` read-only. On MOED, that identity is
`moed@beneliath.com`. Keep this platform configuration aligned with the primary
SMTP configuration during an authorized settings change. Each Account retains
its own outbox and routing tokens; SMTP credentials are mounted only into its
mail-dispatch worker, never into web or unrelated workers.

### Saved member release

The primary is qualified against its current release independently of a member's
saved release. If the primary advances from release A to B while a member is
still provisioning, retry uses that member's saved release A images, migrator,
configuration, active services and public URL. A crash before a runtime was saved
may restart preparation at the current release only if that project has no
containers, networks or volumes; existing secrets are retained. A crash between
runtime and metadata writes may recover the checksum only after validating the
original snapshot/images and proving no project resources exist. Missing runtime
information for an already-started Account fails closed.
Archiving stops the member containers and removes the active Traefik route while
retaining data. Restore verifies that saved runtime and image provenance, starts
only its saved database and active services with `--no-deps`, revokes old sessions
and checks health/identity before finishing. It never runs schema/file migrators
or consults a newer checkout's Compose definitions or static configuration. The
deployment notice and operator backup-input directory are explicitly recorded
live, read-only operational mounts. Compose secrets remain separately managed
and rotatable; they are not frozen as application configuration. A missing/changed runtime or frozen configuration is
an operator recovery error, not permission to reconstruct or upgrade silently.

Deletion also uses the saved runtime for its database backup. It requires the
queued typed-label confirmation, an archived state, a fresh encrypted restore-verified database and
file backup, and verified encrypted configuration. A durable deletion receipt
permits retry after interruption; checksums are rechecked before removing only
that Account's Compose-labelled resources. The primary is protected. Recovery
archives are retained outside the deleted Account directory. Never restore over
a running Account; rehearse restoring all three archives into isolated volumes.

Member service credentials do not prove a human sign-in. Consuming a primary
SuperAdmin's single-use switch ticket issues a separate encrypted sign-in grant,
bound to that member, user and authentication version, valid for 15 minutes.
Identity API calls verify the grant and current primary eligibility every time;
they cannot issue cross-Account tickets. Member Open Account forms carry
target-specific primary-issued intents and submit to the authenticated primary
browser session. Account creation and backups still require fresh administrator
verification. Existing member SuperAdmin sessions without a grant, and sessions
whose grant expires, must reopen the Account from the primary directory. Ordinary
Account users' login/session behavior is unchanged. No schema migration is needed
for these proofs.

Username changes keep the previous route until a private transaction commits.
Unique claims reserve both old and replacement usernames. A locking commit
receipt distinguishes rollback from an in-flight transaction; timeouts leave the
reservation held. Committed changes and Account names are reconciled after
commit and by the worker. Receipts are retained for replay safety. Monitor
`platform_login_reservations` for persistent unresolved work; do not manually
release a reservation without checking the private transaction receipt.
Shared password recovery queues generic requests and retries delivery idempotently
for up to 30 minutes; passwords, MFA and verified addresses remain private.

The worker writes `var/deployment/account-production-health.json`. Alert when
`ok` is false or `checked_at` is older than two minutes. Directory/recovery/mail
and lifecycle failures must remain visible to the operator; inspect private
state, not request payloads or secrets in public logs. Install the service with
site-specific paths/user only during an explicitly approved rollout.

## Qualification gate before s1

A passing local test is not a production restore rehearsal. Use a fresh isolated
copy of production and the exact release digests to check migration checksums,
record/file counts, Shalom identity, normal Admin vs SuperAdmin permissions,
common login/recovery/MFA, `/a/<label>` routing, legacy URLs, clean-image Coach,
backups and notes-cache invalidation. Exercise create/archive/restore/delete and
worker interruption with synthetic Accounts. Verify no outbound SMTP or IMAP is
enabled in that rehearsal. Rehearse rollback with the original images AND the
pre-upgrade database/files/configuration; never point an older image at an
unqualified newer schema. Only after that evidence and explicit deployment
authorization should s1 be changed.
