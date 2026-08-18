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
   # Put independent `openssl rand -base64 36` values in MYSQL_ROOT_PASSWORD,
   # MYSQL_PASSWORD, and DNR_CALENDAR_TOKEN inside .env.
   mkdir -p secrets
   openssl rand -base64 32 > secrets/dnr_2fa_encryption_key
   chmod 600 secrets/dnr_2fa_encryption_key
   ```

   Back up this key separately from the database and do not rotate it casually. Enrolled authenticator secrets cannot be recovered without it.

4. **Build and run the application using Docker Compose**

   ```sh
   docker compose up -d --build
   ```

   Browse to `http://localhost:8080` by default. The published port binds to
   `127.0.0.1` unless `DNR_BIND_ADDRESS` is explicitly changed. Use a TLS
   reverse proxy before exposing DNR beyond the local machine.

5. **Create the first administrator on a fresh database**

   ```sh
   docker compose exec web php /opt/dnr/bin/create_admin.php admin
   ```

   The command securely prompts for a password. The administrator must enroll an authenticator during the first login. DNR no longer installs accounts with known default passwords.

   A server administrator can securely reset an existing account password from the container console:

   ```sh
   docker compose exec web php /opt/dnr/bin/set_password.php USERNAME
   ```

### Upgrading an existing database

Create the secrets shown above before running Docker Compose commands with the updated configuration. Back up the database, run the tracked migration command, restrict the existing application account, then rebuild the web container:

```sh
mkdir -p backups
docker compose exec -T db sh -c 'mysqldump --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" dnr' > backups/dnr-before-upgrade.sql
docker compose exec db sh /opt/dnr/bin/migrate
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" dnr' < operations/restrict_app_database_user.sql
docker compose up -d --build
```

For the one-time upgrade from a legacy installation that still uses the former
committed database passwords and has no `.env`, first build the updated web
image and then run `sh scripts/secure_existing_deployment.sh`. The helper makes
a timestamped database backup, generates private database and calendar
credentials, writes the ignored `.env` with mode `600`, rotates both MySQL
accounts, and recreates the services. It refuses to overwrite an existing
`.env`.

The migration runner obtains database-administrator access only inside the database container, records each filename and checksum in `schema_migrations`, and skips applied migrations. It automatically baselines the documented legacy schema once. Existing administrators will be required to enroll 2FA after their next password login; other roles can enable it from **Account Security**.

The audit-log and Chron-entry migrations use the database administrator account because they install triggers; MySQL requires elevated privileges for that operation when binary logging is enabled. The application continues to connect with the restricted `dnruser` account. The migrations record successful logins, security events, and row-level inserts, updates, and deletes for users, organizations, contacts, engagements, Chron entries, and presentations. Administrators can review this history from **Users → Audit Log**. Audit entries identify the actor, affected record, IP address, and UTC timestamp without storing passwords, authentication secrets, recovery codes, Chron contents, or before/after field values.

### Configuration

### Front-end assets

The application serves committed minified CSS and JavaScript while retaining readable source files in `src/assets`. After changing a source asset, rebuild the production files with:

```sh
npm ci
npm run build:assets
```

Database Initialization:

The init.sql file contains the necessary SQL commands to set up the initial database schema and data. Ensure that this script is executed when the database service starts.

Environment Variables:

Configure these values as needed:

- `PORT`: published HTTP port; defaults to `8080`.
- `DNR_BIND_ADDRESS`: address on which Docker publishes the HTTP port; defaults to `127.0.0.1`.
- `MYSQL_ROOT_PASSWORD` and `MYSQL_PASSWORD`: independent random database secrets. Compose refuses to start when either is absent.
- `DNR_CALENDAR_TOKEN`: revocable random secret of at least 32 characters. The calendar feed remains disabled when it is absent.
- `DNR_PUBLIC_BASE_URL`: externally visible HTTPS origin used to construct the calendar subscription URL.
- `DEFAULT_SPEAKER`: speaker name pre-filled on new presentations; defaults to `Olivier Melnick`. Set it in `.env` to customize it without editing `docker-compose.yaml`.
- `DNR_2FA_KEY_FILE`: host path to the Docker secret containing the base64-encoded 2FA encryption key; defaults to `./secrets/dnr_2fa_encryption_key`.
- `DNR_TRUSTED_PROXY_IPS`: comma-separated reverse-proxy IP addresses or CIDR networks whose `X-Forwarded-For` client address DNR may trust; defaults to Docker Desktop's `192.168.65.1` gateway.
- `DNR_TRUSTED_CLOUDFLARE_PROXY_IPS`: comma-separated IP addresses or CIDR networks used by the trusted Cloudflare tunnel hop in `X-Forwarded-For`; defaults to this deployment's `172.18.0.14` tunnel address. On that route DNR records Cloudflare's `CF-Connecting-IP` value instead of the tunnel container address.
- `DNR_TIMEZONE`: timezone used to display audit timestamps; defaults to `America/Chicago`. UTC is also shown beneath each audit timestamp.
- `DNR_GITHUB_REPOSITORY` and `DNR_GITHUB_BRANCH`: public GitHub repository and deployed branch whose repository activity keeps the footer's latest push timestamp and commit hash current; defaults to `beneliath/DNR` and `main`. If GitHub is unavailable and no cached response exists, the footer omits the push metadata rather than displaying stale commit information.
- `DNR_GITHUB_PUSH_CACHE_TTL`: seconds to cache the latest GitHub push metadata; defaults to `120` and is constrained to 30–3600 seconds.
- `DNR_GITHUB_RETRY_TTL`: retry backoff after GitHub is unavailable; defaults to `300` seconds.
- `DNR_GEOCODER_BASE_URL`: configurable address-lookup endpoint used by the Map page; defaults to OpenStreetMap's public Nominatim search endpoint.
- `DNR_GEOCODER_USER_AGENT`: identifying user agent sent to the configured geocoder. Set this to the deployment name and a contact URL or email. When omitted, DNR identifies itself with its version and repository URL.
- `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD_FILE`: runtime database connection settings for non-Compose deployments. Compose uses the fixed `dnr` database and restricted `dnruser` account.

### Two-factor authentication

- TOTP authenticator codes use a 30-second time step and tolerate one neighboring time step for clock drift.
- A successful code cannot be reused.
- Five failed password or second-factor attempts temporarily lock that factor for 15 minutes.
- Recovery codes are single-use and stored only as password hashes.
- Administrators are required to use 2FA and cannot disable it themselves.
- An administrator can reset another user's 2FA from **Manage Users**. Resetting or replacing a factor invalidates that user's other sessions.
- An administrator can set a temporary password for another user from **Manage Users**. The action requires the administrator's password plus a fresh authenticator or recovery code, invalidates the target user's sessions, and forces the target to choose a private password after login.
- Users can change their own password from **Account Security**; doing so invalidates their other sessions.

### Password recovery

- The login page links to a self-service password recovery flow.
- Recovery requires proof from the account's enrolled authenticator or one unused recovery code; DNR does not send reset links because it has no verified email delivery channel.
- The identity-verification step expires after 10 minutes, and permission to set a new password expires five minutes after verification.
- Five failed recovery-factor attempts trigger the same 15-minute second-factor lockout used during login.
- A successful recovery requires a new password of at least 12 characters, invalidates other sessions, records a security audit event, and signs the recovered account in.
- Accounts without 2FA, or users who have lost every recovery method, require another administrator to set a temporary password from **Manage Users**, or a server administrator to run `docker compose exec web php /opt/dnr/bin/set_password.php USERNAME`.

### Usage

Authenticated users can open **Map** in the primary navigation to view active engagements on an interactive, zoomable map. Pins use engagement-status colors and can be filtered to one status or to events that overlap a selected date window. Selecting a pin opens the event summary and a link to the full engagement. Events without an address are counted but cannot be placed.

The first Map visit resolves uncached event addresses through the configured geocoder. Lookups are serialized to at most one request per second and cached by normalized address in the database, so events at the same address share one result. Map tiles and location results are attributed to OpenStreetMap contributors. For a larger or commercial deployment, configure a geocoding provider appropriate for that workload instead of relying on the public default.

Authenticated users can open **Calendar** in the navigation to copy the private, tokenized subscription URL or open it in a calendar app. The feed includes every active (non-archived) engagement, regardless of status. Calendar entries use `Event Status-Event Title-Event Type` when an event title is set and `Event Status-Organization-Event Type` otherwise; entries are all-day events covering the engagement date range and include the organization, event title, event type, status, and location. Calendar clients choose their own refresh schedule, so database changes may not appear immediately.

The subscription URL contains a revocable bearer token and does not use a browser login. Treat it as a password and rotate `DNR_CALENDAR_TOKEN` if it is disclosed. Contacts, chronological notes, travel, lodging, and compensation are never included.

### Contributing

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
