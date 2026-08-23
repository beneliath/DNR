## DNR: MOED מוֹעֵד

### Description

DNR (MOED מוֹעֵד) is a web-based application for managing speaking engagements, presentations, and organizational contacts.

### Installation

To set up the project on your local machine, follow these steps:

1. **Clone the repository**

   ```
   git clone https://github.com/beneliath/DNR.git
   ```

2. **Navigate to the project directory**
   ```
   cd DNR
   ```
3. **Create deployment secrets**

   ```sh
   cp .env.example .env
   install -d -m 700 secrets backups
   umask 077
   openssl rand -hex 32 > secrets/mysql_root_password
   openssl rand -hex 32 > secrets/mysql_app_password
   openssl rand -hex 32 > secrets/mysql_maintenance_password
   openssl rand -hex 32 > secrets/mysql_geocoder_password
   openssl rand -hex 32 > secrets/mysql_mail_ingest_password
   openssl rand -hex 32 > secrets/mysql_mail_dispatch_password
   openssl rand -base64 32 > secrets/dnr_2fa_encryption_key
   : > secrets/backup_password
   chmod 600 secrets/*
   ```

   Store a protected offline copy of `dnr_2fa_encryption_key` separately from database backups and do not rotate it casually. Enrolled authenticator secrets cannot be recovered without it. The empty `backup_password` file is populated only for a one-shot restore and is removed afterward.

4. **Build and run the application using Docker Compose**

   ```sh
   ./scripts/compose_with_provenance.sh production
   ```

   Production mode requires HTTPS from a trusted reverse proxy and uses only
   source code baked into the immutable image. For local HTTP development, run
   `./scripts/compose_with_provenance.sh development`
   and browse to `http://localhost:8080`. The published port binds to
   `127.0.0.1` unless `DNR_BIND_ADDRESS` is explicitly changed.
   A one-shot migrator applies the baseline and every forward migration before
   the web or worker services can start, so no separate fresh-install migration
   command is required.

5. **Create the first administrator on a fresh database**

   ```sh
   docker compose exec web dnr-create-admin admin
   ```

   The command securely prompts for a password. The administrator must enroll an authenticator during the first login. DNR no longer installs accounts with known default passwords.

   A server administrator can securely reset an existing account password from the container console:

   ```sh
   docker compose exec web dnr-set-password USERNAME
   ```

### Upgrading an existing database

Create the secrets shown above before running Docker Compose commands with the updated configuration. Back up the database, then start the release through the wrapper. Its migrator applies pending schema changes and reasserts every service-specific grant before application traffic starts:

```sh
mkdir -p backups
docker compose exec -T db sh -c 'MYSQL_PWD="$(cat "$MYSQL_ROOT_PASSWORD_FILE")" mysqldump --single-transaction --routines --triggers --no-tablespaces -uroot dnr' > backups/dnr-before-upgrade.sql
chmod 600 backups/dnr-before-upgrade.sql
./scripts/compose_with_provenance.sh production
```

For the one-time upgrade from a legacy installation that still exposes database
passwords through container environment variables, run:

```sh
# Production deployment (default)
sh scripts/secure_existing_deployment.sh

# Local development deployment
DNR_COMPOSE_MODE=development sh scripts/secure_existing_deployment.sh
```

The helper makes a timestamped database backup, generates private database
credentials, writes the ignored `.env` with mode `600`, rotates the root account
and five service-specific MySQL accounts,
applies tracked migrations and grants, and rebuilds the services with
immutable footer provenance. An existing `.env` is accepted only when it has
the two recognized legacy MySQL password variables. The file is never sourced:
only an explicit allowlist of non-secret application settings is copied to the
replacement. Deprecated password and calendar-token values are discarded. The
helper refuses an already-secured, unrecognized, or partially migrated setup.

The migration runner obtains database-administrator access only in the isolated migrator/database services. It holds a MySQL advisory lock, records `applying`, `applied`, or `failed` state plus each filename and SHA-256 checksum, and refuses to guess after interrupted DDL. Fresh installs and upgrades both start with `migrations/20260813_baseline.sql` and then follow the same ordered forward migrations. Add a new migration instead of editing an applied file. Existing administrators will be required to enroll 2FA after their next password login; other roles can enable it from **Account Security**.

If the upgraded database predates password hashing and still contains plaintext user passwords, run the one-time CLI migration. It leaves recognized password hashes unchanged, invalidates sessions for migrated accounts, and requires those users to choose a new password after login:

```sh
docker compose exec web php /opt/dnr/bin/migrate_passwords.php
```

There is intentionally no browser-accessible password migration page.

The audit-log and Chron-entry migrations use the database administrator account because they install triggers; MySQL requires elevated privileges for that operation when binary logging is enabled. The application continues to connect with the restricted `dnruser` account. The migrations record successful logins, security events, and row-level inserts, updates, and deletes for users, organizations, contacts, engagements, Chron entries, and presentations. Administrators can review this history from **Users → Audit Log**. Audit entries identify the actor, affected record, IP address, and UTC timestamp without storing passwords, authentication secrets, recovery codes, Chron contents, or before/after field values.

### Configuration

For installations outside Docker, Composer validates the PHP runtime used by
the application. Install the Fileinfo, GD, mbstring, mysqli, OpenSSL, and Sodium
extensions before running `composer install`.

### Front-end assets

The application serves committed minified CSS and JavaScript while retaining readable source files in `src/assets`. After changing a source asset, rebuild the production files with:

```sh
npm ci
npm run build:assets
```

Page heads and asset cache keys are generated by `renderPageHead()` and `assetUrl()`. Page-specific source styles live in `src/assets/css/pages`; executable page behavior lives in `src/assets/js/page-actions.js`. Inline executable scripts and style attributes are intentionally avoided so the application can enforce a strict content security policy. The map alone permits runtime inline positioning because Leaflet requires it.

### Architecture and quality checks

HTTP entry points load `src/bootstrap.php`, which initializes Composer/local autoloading, structured request logging, database configuration, and shared application helpers. Reusable validation and reference data live under `src/app/Domain`; create and edit routes use the same normalizers. PHPStan checks migrated request/controller routes at level 3, the remaining HTTP surface at level 0, the legacy helper layer at level 5, and the typed domain/runtime layer at level 6 so newly extracted code can move progressively into the stricter configurations.

Telephone numbers are validated with libphonenumber metadata and stored in canonical E.164 form. National and international formatting is applied only when values are rendered or returned to an edit form.

Run the complete local quality gate before opening a pull request:

```sh
composer install
npm ci
composer validate --strict --no-check-publish
composer audit --no-interaction
npm audit --audit-level=high
composer check
npm test
```

`composer check` runs PHP syntax checks, PHPStan level 6 on the typed domain/runtime layer, PHPStan level 5 on the legacy helper layer, level 3 on migrated request/controller routes, level 0 on the remaining HTTP surface, and all unit/feature tests. `npm test` rebuilds every committed production asset and runs JavaScript syntax and behavior tests. GitHub Actions repeats these checks on PHP 8.4 and 8.5, audits locked dependencies, validates every Compose overlay combination, and starts a disposable Docker/MySQL environment through the documented automatic-migration path before exercising HTTP and database behavior. Dependabot proposes weekly Composer, npm, Docker, and pinned GitHub Actions updates.

Database integration suites are discovered automatically from `tests/*_integration_test.php` and `tests/integration_*_test.php`. Run them only against the disposable Compose environment with `sh scripts/run_integration_tests.sh disposable`; the runner sends destructive backup/restore coverage to the isolated maintenance container.

`VERSION` is the single source of release-version metadata. Update it once when preparing a release; runtime responses, asset cache keys, the footer, backups, and container images read that value automatically.

### Health and operations

- `/health.php` is a dependency-free liveness response.
- `/ready.php` verifies database connectivity and every migration filename/checksum before reporting ready.
- Application errors are logged as structured JSON with a request ID. Public error responses omit database and exception details and include the request ID for correlation.

### Outbound account email

Invitation, verification, and recovery messages use an encrypted transactional outbox. Creating a
token and its queued message is one database change; a dedicated `mail-dispatch` worker owns the
SMTP credential and the only SMTP egress path. The web service never connects to SMTP. A previous
single-use link remains valid while its replacement is pending and is invalidated only after the
relay accepts the new message. Failed deliveries retry with bounded exponential backoff, and the
encrypted payload is erased after success or terminal failure.

Create `secrets/smtp_password`, configure the sender and relay in `.env`, and use
`production-smtp` or `development-smtp`. Combine inbound and outbound mail with
`production-mail-smtp` or `development-mail-smtp`.

### Inbound email to Chron

DNR can poll a dedicated IMAP mailbox and turn messages copied to it into Contact and Organization
Chron entries. Event routing is intentionally not included. This first version uses exact email-address
rules; it does not send message content to an AI service.

The routing policy is deliberately conservative:

- The sender must uniquely match an active DNR user with a verified email address, an active Contact,
  or an active Organization.
- DNR considers the message's `From`, `To`, and `Cc` addresses, excluding the configured gateway
  address and recognized internal users. A unique Contact match adds the message to that Contact and
  the Contact's Organization. A direct Organization email match adds it to the Organization.
- Duplicate delivery of the same RFC Message-ID is idempotent. Ambiguous senders, shared email
  addresses, unknown senders, and messages with no matched target go to **Inbound Mail** for an
  administrator or editor to review. If an address is missing from DNR, update the record and use
  **Retry routing**.
- The Chron entry contains the normalized headers, subject, timestamps, plain-text body, attachment
  names, and a link to the retained inbound record. Attachment contents are not stored. HTML-only
  mail is converted to inert plain text.

Exact address matching is a routing decision, not independent proof of sender identity. The mailbox
provider should enforce its normal spam and SPF/DKIM/DMARC checks, and suspicious messages should be
left for manual review rather than approved solely because the visible `From` address is familiar.

Both `Cc: moed@beneliath.com` and `Bcc: moed@beneliath.com` work. A Bcc delivery normally omits the
gateway address from the stored message headers, which is expected; DNR routes using the remaining
participants. For example, mail from a verified DNR user to a unique Contact routes to the Contact
and its Organization, while a reply from that Contact is recognized from `From`.

Apply the tracked database migration before enabling the worker. Then create the ignored IMAP
password secret, set the inbound variables in `.env`, and start a mail-enabled Compose mode:

```sh
install -m 600 /dev/null secrets/imap_password
# Put the exact IMAP/Bridge password in secrets/imap_password without committing it.
./scripts/compose_with_provenance.sh production-mail
```

For local development, use `development-mail`. The worker imports unseen messages in bounded
batches, marks a message seen only after DNR has stored it, and retries transient routing failures.
Oversized or unparseable poison messages are recorded in `inbound_email_quarantine` before being
marked seen so they cannot starve later UIDs; transient mailbox or database failures remain unseen.
It does not delete or move the source message, including after successful routing; the IMAP mailbox
remains a recoverable source of record. The web and worker accounts retain only the database
privileges needed for this workflow.

Proton Mail accounts require [Proton Mail Bridge](https://proton.me/support/imap-smtp-and-pop3-setup)
and a paid Proton plan. Configure DNR with the IMAP hostname, port, username, and generated password
shown by Bridge—not the Proton account password. Bridge uses a local, self-signed IMAP endpoint and
is designed for clients on the same computer. Set `DNR_IMAP_VERIFY_PEER=0` only for that local or
tightly isolated Bridge connection; keep certificate verification enabled for any remote IMAP
server. Because a Docker container may not be able to reach a Bridge listener bound only to the
host's loopback interface, confirm network reachability from the `mail-ingest` service before using
the production mail mode. If Bridge cannot be made reachable without exposing it, run the worker
beside Bridge or use a dedicated standards-compliant IMAP mailbox rather than publishing Bridge to
an untrusted network. Proton documents the local-only design in its
[Bridge overview](https://proton.me/support/why-you-need-bridge) and
[Bridge CLI guide](https://proton.me/support/bridge-cli-guide).

Database initialization uses only the ordered files under `migrations/`. The Compose `migrator`
service applies the minimal baseline and every forward migration, records immutable checksums and
state, and then applies the single privilege manifest. There is no independent schema snapshot to
drift from the upgrade path.

Environment Variables:

Configure these values as needed:

- `PORT`: published HTTP port; defaults to `8080`.
- `DNR_BIND_ADDRESS`: address on which Docker publishes the HTTP port; defaults to `127.0.0.1`.
- `DNR_MYSQL_ROOT_PASSWORD_FILE`, `DNR_MYSQL_APP_PASSWORD_FILE`, `DNR_MYSQL_MAINTENANCE_PASSWORD_FILE`, `DNR_MYSQL_GEOCODER_PASSWORD_FILE`, `DNR_MYSQL_MAIL_INGEST_PASSWORD_FILE`, and `DNR_MYSQL_MAIL_DISPATCH_PASSWORD_FILE`: host paths to independent secret files. Web, geocoding, inbound parsing, outbound delivery, migration, and destructive maintenance identities receive only their own table-specific privileges.
- `DNR_BACKUP_PASSWORD_FILE`: host path to the temporary file containing the exact password of the backup being restored. It is mounted only in the maintenance profile and should be emptied or removed immediately after the restore is verified.
- `DNR_PUBLIC_BASE_URL`: externally visible HTTPS origin used to construct calendar, invitation, verification, and recovery links.
- `DNR_MAIL_TRANSPORT`: `smtp` enables account email delivery; the secure default is `disabled`. `log` acknowledges messages without logging their bearer links and is intended only for automated tests.
- `DNR_MAIL_FROM` and `DNR_MAIL_FROM_NAME`: validated sender address and display name for account email.
- `DNR_SMTP_HOST`, `DNR_SMTP_PORT`, and `DNR_SMTP_ENCRYPTION`: SMTP relay connection. Encryption accepts `starttls` (the default), implicit `tls`, or `none` for a trusted internal relay.
- `DNR_SMTP_USERNAME` and `DNR_SMTP_PASSWORD_SECRET_FILE`: optional SMTP authentication and the host path mounted by the SMTP overlay. The dedicated `mail-dispatch` service receives it as `DNR_SMTP_PASSWORD_FILE`; the web service remains backend-only and cannot reach the relay.
- `DNR_EMAIL_OUTBOX_BATCH_SIZE` and `DNR_EMAIL_OUTBOX_IDLE_SECONDS`: bounded outbound messages per worker cycle and idle polling interval. Defaults are 20 messages and 15 seconds.
- `DNR_INBOUND_ADDRESS`: dedicated mailbox address copied on messages for Chron capture; defaults to `moed@beneliath.com` in the mail Compose overlay.
- `DNR_INBOUND_MAX_BYTES`, `DNR_INBOUND_BATCH_SIZE`, and `DNR_INBOUND_IDLE_SECONDS`: maximum raw message size, bounded messages per polling cycle, and idle polling interval. Defaults are 10 MiB, 20 messages, and 30 seconds.
- `DNR_IMAP_HOST`, `DNR_IMAP_PORT`, and `DNR_IMAP_SECURITY`: inbound mailbox endpoint. Security accepts `starttls` (the default), implicit `tls`, or `none` only for a trusted isolated connection.
- `DNR_IMAP_USERNAME`, `DNR_IMAP_PASSWORD_FILE`, and `DNR_IMAP_MAILBOX`: mailbox credentials and selected folder. The mail Compose overlay mounts the password as a Docker secret; `DNR_IMAP_PASSWORD` is accepted only for simple non-Compose or development execution.
- `DNR_IMAP_VERIFY_PEER`: verifies the IMAP server certificate by default. Disable it only for a local Proton Bridge endpoint using Bridge's self-signed certificate.
- `DEFAULT_SPEAKER`: speaker name pre-filled on new presentations; defaults to `Olivier Melnick`. Set it in `.env` to customize it without editing `docker-compose.yaml`.
- `DNR_2FA_KEY_FILE`: host path to the Docker secret containing the base64-encoded 2FA encryption key; defaults to `./secrets/dnr_2fa_encryption_key`.
- `DNR_REQUIRE_HTTPS`: rejects non-HTTPS requests in production; defaults to `1`. The development Compose override sets it to `0` for loopback-only HTTP.
- `DNR_SESSION_IDLE_SECONDS`, `DNR_SESSION_ABSOLUTE_SECONDS`, and `DNR_SESSION_ROTATION_SECONDS`: authenticated-session idle lifetime, absolute lifetime, and identifier-rotation interval. Defaults are 30 minutes, 12 hours, and 15 minutes.
- `DNR_TRUSTED_PROXY_IPS`: comma-separated reverse-proxy IP addresses or CIDR networks whose `X-Forwarded-For` hops DNR may trust; defaults to Docker Desktop's published-port proxy at `192.168.65.1`. DNR walks the forwarding chain from the nearest hop outward, skips only configured proxies, and uses the first untrusted address so a client-controlled leftmost value cannot override audit or throttling attribution. Other deployments can set an explicit proxy address or use `docker-gateway` to resolve the container's default route dynamically. If the published port is reachable beyond the reverse proxy, restrict it with a firewall and ensure the proxy replaces client-supplied forwarding headers, including `X-Forwarded-Proto`.
- `DNR_BACKEND_SUBNET` and `DNR_INGRESS_PROXY_IP`: private backend network and fixed address of the localhost ingress proxy. The defaults are `172.30.255.0/24` and `172.30.255.254`. Override both together if that subnet conflicts with another Docker network. Only the fixed proxy address is added to the application's trusted proxy list; the web container remains on the internal backend without an outbound route.
- `DNR_TRUSTED_CLOUDFLARE_PROXY_IPS`: comma-separated IP addresses or CIDR networks used by the trusted Cloudflare tunnel hop in `X-Forwarded-For`; defaults to this deployment's `172.18.0.0/24` private proxy network so container address changes do not break client-IP detection. On that route DNR records Cloudflare's `CF-Connecting-IP` value instead of the tunnel container address.
- `DNR_TIMEZONE`: timezone used to display audit timestamps; defaults to `America/Chicago`. UTC is also shown beneath each audit timestamp.
- `DNR_DATABASE_BACKUP_MAX_BYTES`: maximum unencrypted backup size; defaults to `67108864` bytes (64 MB). Restore plaintext exists only in the maintenance container's memory-backed `/tmp`.
- `DNR_GITHUB_REPOSITORY`, `DNR_BUILD_COMMIT`, and `DNR_BUILD_TIMESTAMP`: repository link and immutable build provenance displayed in the footer. The Compose wrapper derives the full hash and UTC commit timestamp automatically. CI builds from source archives without `.git` may export both values explicitly. Page rendering never calls GitHub or another third-party API.
- `DNR_GEOCODER_BASE_URL` and `DNR_GEOCODER_ALLOWED_HOSTS`: public HTTPS endpoint and explicit hostname allowlist used only by the background geocoder worker.
- `DNR_GEOCODER_USER_AGENT`: identifying user agent sent to the configured geocoder. Set this to the deployment name and a contact URL or email. When omitted, DNR identifies itself with its version and repository URL.
- `DNR_MAP_PAST_DAYS`, `DNR_MAP_FUTURE_DAYS`, and `DNR_MAP_MAX_EVENTS`: default bounded map window and hard result cap; defaults are 90 days back, 730 days forward, and 500 engagements.
- `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD_FILE`: runtime database connection settings for non-Compose deployments. Compose uses the fixed `dnr` database and restricted `dnruser` account.

### Build provenance

Use `scripts/compose_with_provenance.sh` instead of invoking a Compose build directly. It derives
the checked-out commit and UTC commit timestamp, validates both values, exports them as Docker build
arguments, and then runs Compose. It refuses a dirty worktree because a footer commit would not
accurately identify uncommitted source files.

```sh
# Local HTTP development
./scripts/compose_with_provenance.sh development

# Production behind the configured HTTPS proxy
./scripts/compose_with_provenance.sh production

# Production with the inbound IMAP worker
./scripts/compose_with_provenance.sh production-mail

# Local HTTP development with the inbound IMAP worker
./scripts/compose_with_provenance.sh development-mail

# Production with encrypted queued SMTP delivery
./scripts/compose_with_provenance.sh production-smtp

# Production with both inbound IMAP and outbound SMTP workers
./scripts/compose_with_provenance.sh production-mail-smtp

# Local equivalents
./scripts/compose_with_provenance.sh development-smtp
./scripts/compose_with_provenance.sh development-mail-smtp

# Forward a specific Compose command or service selection
./scripts/compose_with_provenance.sh development up -d --build web ingress

# Display the metadata without running Docker
./scripts/compose_with_provenance.sh --print-metadata
```

For CI source archives without `.git`, export a complete 40-character `DNR_BUILD_COMMIT` and a UTC
`DNR_BUILD_TIMESTAMP` in `YYYY-MM-DDTHH:MM:SSZ` format before invoking the wrapper. Both values must
be supplied together.

### Two-factor authentication

- TOTP authenticator codes use a 30-second time step and tolerate one neighboring time step for clock drift.
- A successful code cannot be reused.
- Five failed password or second-factor attempts temporarily lock that factor for 15 minutes.
- Recovery codes are single-use. DNR stores only a keyed HMAC lookup value, never the code itself.
- Administrators are required to use 2FA and cannot disable it themselves.
- An administrator can reset another user's 2FA from **Manage Users**. Resetting or replacing a factor invalidates that user's other sessions.
- An administrator can set a temporary password for another user from **Manage Users**. The control appears only during a five-minute sensitive-action window opened with the administrator's password plus a fresh authenticator or recovery code. The route rechecks that elevation, invalidates the target user's sessions, and forces the target to choose a private password after login.
- Users can change their own password from **Account Security**; doing so invalidates their other sessions.

### Password recovery

- The login page links to a self-service password recovery flow.
- Recovery links are sent only to the unique verified email address of an active account. Responses do not reveal whether an address belongs to an account.
- Recovery links are random, single-use bearer tokens. Only their SHA-256 digests are stored, and they expire after one hour.
- A successful recovery requires a new password of at least 12 characters, invalidates every existing session, records a security audit event, and returns the user to sign-in so normal 2FA requirements still apply.
- Account passwords must contain at least 12 characters and no more than 72 UTF-8 bytes; the upper bound prevents bcrypt from silently ignoring part of a password.
- Accounts without a verified email address require another administrator to set a temporary password from **Manage Users**, or a server administrator to run `docker compose exec web dnr-set-password USERNAME`.

### User lifecycle and invitations

- Freshly invited accounts cannot authenticate. The seven-day invitation link lets the recipient choose a private password, verifies the invited email address, and activates the account.
- Changing an account email clears its verified status and sends a new 24-hour verification link. Only verified addresses can receive password-recovery links.
- Deactivation retains the user and every audit reference while incrementing the authentication version, revoking all calendar subscriptions, invalidating outstanding email links, removing task assignments, and making any session-held administrator elevation unusable.
- Reactivation restores sign-in access only. Revoked calendar links and prior task assignments are intentionally not restored.

### Usage

Administrators can open **Database** in the primary navigation to download a DNR database backup.
Export requires the administrator's password, a fresh authenticator or recovery code, and a new
backup password. That password is required to restore the file and is never stored by DNR. The
complete `.dnrbackup` archive is encrypted with a
key derived by Argon2id and authenticated with XChaCha20-Poly1305 secretstream. Backups contain
every application table, including user authentication and audit data. Treat encrypted files as
secrets, use a strong unique backup password, and keep the password separately in a password
manager. A forgotten backup password cannot be recovered.

A restore is accepted only when the backup schema exactly matches the deployed database schema.
Install the matching DNR version and run its migrations before restoring. The data replacement is
transactional and automatically rolls back on failure; a successful restore invalidates all
existing sessions and requires everyone to sign in again. Restore is not exposed by HTTP: it runs
in a one-shot, non-egress maintenance container using a database credential that the web and
geocoder containers never receive. Database backups do not contain the
separate `DNR_2FA_ENCRYPTION_KEY`, so keep a secure copy of that key with the backup set. For full
disaster recovery, initialize and migrate an empty DNR deployment, restore the `.dnrbackup` from
the maintenance profile, and restore the same two-factor encryption key.

### Exact database restore runbook

The following procedure is complete for the Docker Compose deployment. Run every step from the
repository root. The example deliberately renames the selected archive to
`backups/restore.dnrbackup`, so the commands can be copied without shell globs or unresolved path
variables.

1. Check out the exact DNR release that created the backup. Restore the matching
   `secrets/dnr_2fa_encryption_key`; create or restore the independent root, application,
   maintenance, geocoder, inbound-mail, and outbound-mail database secret files; create an empty
   mode-`600` `secrets/backup_password`; and confirm `.env` points to those eight secret files.
   Then build every image used by the procedure:

   ```sh
   install -d -m 700 secrets backups
   install -m 600 /dev/null secrets/backup_password
   ./scripts/compose_with_provenance.sh production build maintenance web geocoder ingress
   ```

   Do not generate a new 2FA key: without the original key, authenticator secrets in the restored
   database cannot be decrypted. If the database service and its volume already exist, start the
   database and one-shot migrator:

   ```sh
   docker compose up -d db migrator --wait
   ```

   For disaster recovery with no database volume, the same command builds the empty `dnr`
   database entirely from the baseline and forward migrations and installs the restricted service
   accounts. The restore refuses a schema mismatch before deleting or inserting data.

2. Copy the selected encrypted backup into the ignored `backups/` directory under the fixed name
   and protect it. Replace `/absolute/path/to/selected.dnrbackup` with the actual source path:

   ```sh
   install -m 600 /absolute/path/to/selected.dnrbackup backups/restore.dnrbackup
   ```

3. Put the exact backup encryption password in `secrets/backup_password` with no added newline.
   `read -s` keeps it off the screen and `printf` preserves the value exactly:

   ```sh
   umask 077
   IFS= read -r -s DNR_RESTORE_PASSWORD
   printf '%s' "$DNR_RESTORE_PASSWORD" > secrets/backup_password
   unset DNR_RESTORE_PASSWORD
   chmod 600 secrets/backup_password
   ```

4. Before stopping the application, make an independent plaintext SQL safety dump of the current
   database. This file is the rollback point and contains secrets, so retain mode `600` and store it
   only on the deployment host:

   ```sh
   docker compose exec -T db sh -c 'MYSQL_PWD="$(cat "$MYSQL_ROOT_PASSWORD_FILE")" mysqldump --single-transaction --routines --triggers --no-tablespaces -uroot dnr' > backups/pre-restore-safety.sql
   chmod 600 backups/pre-restore-safety.sql
   ```

5. Stop every service that can mutate application data. Leave `db` running. If inbound or
   outbound mail overlays are enabled, stop `mail-ingest` and `mail-dispatch` through the same
   overlay set as well:

   ```sh
   docker compose stop ingress web geocoder
   docker compose ps
   ```

   Verify that `ingress`, `web`, and `geocoder` show `Exited` and `db` shows `Up (healthy)` before continuing.

6. Run the one-shot restore with the literal confirmation word `RESTORE`:

   ```sh
   docker compose --profile maintenance run --rm --no-deps maintenance /backups/restore.dnrbackup RESTORE
   ```

   Success prints the restored row and table counts. The command authenticates and decrypts the
   complete archive, checks its size, format, schema fingerprint, declared row counts, content hash,
   and trailing data, replaces all tables in one transaction, verifies every restored table count,
   records `database_restored`, and invalidates sessions. A wrong password, damaged archive,
   schema mismatch, insertion error, or count mismatch rolls the transaction back.

7. If step 6 failed, do not run the remaining success steps. Because the restore transaction was
   rolled back, restart the stopped services. If you will not immediately correct the problem and
   retry, empty the password secret and remove the working copy before investigating the printed
   error:

   ```sh
   docker compose up -d web geocoder ingress
   install -m 600 /dev/null secrets/backup_password
   rm -f backups/restore.dnrbackup
   ```

8. After a successful restore, apply any still-pending migrations, reassert table-specific runtime
   grants and the isolated maintenance grant, then restart the application services:

   ```sh
   docker compose run --rm migrator
   docker compose up -d web geocoder ingress
   ```

9. Wait for the health check, inspect service status, and run the schema health check directly:

   ```sh
   docker compose ps
   docker compose exec web php /opt/dnr/bin/check_schema.php
   ```

   Do not declare the restore complete until `ingress`, `web`, `geocoder`, and `db` are running;
   `ingress`, `web`, and `db` are healthy; and the schema command exits successfully.

10. Sign in with an administrator account that exists in the restored backup. Verify at least one
    known engagement, organization, contact, and user; open **Users → Audit Log** and verify the
    `database_restored` event. Also verify 2FA decryption by completing a fresh authenticator check.
    All pre-restore browser sessions should require sign-in again.

11. Empty the temporary restore password and remove the extra working copy only after verification. Keep
    the original encrypted backup and password in their approved long-term locations. Retain the SQL
    safety dump until the restored deployment has passed operational verification, then securely
    dispose of it according to the deployment's retention policy:

    ```sh
    install -m 600 /dev/null secrets/backup_password
    rm -f backups/restore.dnrbackup
    ```

If step 6 succeeded but later verification reveals incorrect data, stop `ingress`, `web`, and
`geocoder` (plus enabled mail workers), import the safety dump, rerun migrations and privilege
configuration, and restart the services:

```sh
docker compose stop ingress web geocoder
docker compose exec -T db sh -c 'MYSQL_PWD="$(cat "$MYSQL_ROOT_PASSWORD_FILE")" mysql -uroot dnr' < backups/pre-restore-safety.sql
docker compose exec -T db sh -c 'MYSQL_PWD="$(cat "$MYSQL_ROOT_PASSWORD_FILE")" mysql -uroot dnr -e "UPDATE users SET auth_version = auth_version + 1"'
docker compose run --rm migrator
docker compose up -d web geocoder ingress
docker compose exec web php /opt/dnr/bin/check_schema.php
```

The explicit `auth_version` update after the safety-dump import prevents browser sessions that
existed before either restore from becoming valid again. Repeat steps 9 through 11 after the
rollback, using the safety-dump data for the representative-record checks. Once the approved
retention period ends, remove the plaintext rollback dump with
`rm -f backups/pre-restore-safety.sql`; filesystem-level secure erasure must follow the host's
encrypted-storage and media-disposal policy.

Authenticated users can open **Work Queue** to review assignable follow-up work. Tasks may be
general or linked to one engagement, organization, or contact. Each task supports an owner,
due date, priority, notes, and the states **Open**, **In progress**, **Waiting**, **Completed**,
and **Canceled**. The queue provides personal, overdue, due-today, next-seven-days, waiting,
unassigned, completed, and all-active views. Reviewers can inspect tasks; administrators and
editors can create, edit, assign, and complete them; permanent deletion remains limited to
administrators.

Engagement, organization, and contact detail pages show their open follow-up work. Active
engagements also offer an optional, idempotent standard checklist covering location, travel,
presentations, materials, host reconfirmation, post-event thanks, outcome capture, and financial
closeout. Every active standard item is automatically added and assigned to the creator when a new
engagement is saved; re-running **Add missing checklist tasks** on an older engagement only adds
missing active items. Open **Standard event tasks** from the Work Queue to add, view, or edit the
reusable task content, priority, event-relative due rule, and order. Archived definitions are
excluded from future checklists and may be restored;
administrators can permanently delete archived definitions after fresh authentication. Editing,
archiving, or deleting a definition does not rewrite tasks already generated for events.

## Financial closeout and giving history

Editors and administrators can close an active event from its detail page by finalizing the actual
giving/income, lodging, and travel amounts received. These actual receipts are stored separately
from the anticipated travel, lodging, and compensation fields used during planning. A report can be
corrected later without changing its original closer or close timestamp; concurrent corrections are
rejected rather than silently overwriting newer figures. Every insert and correction is captured by
the database audit log.

Organization detail pages calculate lifetime giving, latest-event giving, average giving per closed
event, and aggregate lodging and travel receipts from finalized reports only. “Latest event” follows
the event end date, even if its report was entered later, and archived events remain part of the
historical totals. The organization list also shows latest-event and lifetime giving for quick review.

Authenticated users can open **Map** in the primary navigation to view active engagements on an interactive, zoomable map. Pins use engagement-status colors and can be filtered to one status or to events that overlap a selected date window. Selecting a pin opens the event summary and a link to the full engagement. Events without an address are counted but cannot be placed.

The web request never calls the geocoder. New or changed addresses enter a database queue; the dedicated egress-enabled worker resolves them at no more than one request per 1.1 seconds and caches results by normalized address, so events at the same address share one result. The initial map window and result count are bounded. Map tiles and location results are attributed to OpenStreetMap contributors. For a larger or commercial deployment, configure an allowlisted provider appropriate for that workload instead of relying on the public default.

Authenticated users can open **Calendar** in the navigation to create, label, copy, and revoke private subscription URLs per device. The feed includes active (non-archived) engagements in the configured bounded calendar window, regardless of status. Calendar entries use `Event Status-Event Title-Event Type` when an event title is set and `Event Status-Organization-Event Type` otherwise; entries are all-day events covering the engagement date range and include the organization, event title, event type, status, and location. Calendar clients choose their own refresh schedule, so database changes may not appear immediately.

Each subscription URL contains a revocable bearer token and does not use a browser login. Treat it as a password; revoke only the affected device token if it is disclosed. DNR stores only a SHA-256 token digest, redacts all query strings from Apache access logs, and never includes contacts, chronological notes, travel, lodging, or compensation in the feed.

### Contributing

Database integration tests must run only against a disposable database created for that test run.
They refuse to start unless `DNR_INTEGRATION_TARGET=disposable` is set. The database-backup
integration additionally requires `DNR_DESTRUCTIVE_BACKUP_TEST=isolated-restore` because it
deletes and restores every application table. Never set either acknowledgement against a
development or production database; dispose of the isolated container and volume afterward.

Keep work from different development computers on separate remote branches. Codex-assisted
MacBook work uses `codex/macbook/<topic>` and home-computer work uses
`codex/home/<topic>`. Use the same Git author identity on both computers, and add these trailers
to every workstation-specific commit so its origin remains searchable after it is pushed:

```text
Development-Host: MacBook
Codex-Client: Desktop
```

Push each branch with an upstream (`git push -u origin <branch>`) and merge it through a pull
request. When continuing on a different computer, fetch the remote branch and create a new branch
with that computer's prefix instead of committing directly to the first computer's branch.
Application versions describe released behavior, not the computer that produced a commit; do not
bump the version for each workstation commit.

Contributions to the DNR project are welcome. To contribute:

- fork the repository
- create a new branch for your feature or bug fix
- commit your changes with descriptive messages
- push your branch to your forked repository
- submit a pull request to the main repository

Please ensure that your contributions adhere to the project's coding standards and include appropriate tests.

### Authors and Acknowledgment

### License

This project is licensed under the MIT License. See the LICENSE file for more details.

### Project Status

Under active development
