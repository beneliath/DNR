## DNR: deploy and report

### Description

DNR (deploy and report) is a web-based application for managing speaking engagements, presentations, and organizational contacts.

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
3. **Create and protect the 2FA encryption key**

   ```sh
   mkdir -p secrets
   openssl rand -base64 32 > secrets/dnr_2fa_encryption_key
   chmod 600 secrets/dnr_2fa_encryption_key
   ```

   Back up this key separately from the database and do not rotate it casually. Enrolled authenticator secrets cannot be recovered without it.

4. **Build and run the application using Docker Compose**

   ```sh
   docker compose up -d --build
   ```

   Browse to `http://localhost:8080` by default.

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

Create the 2FA encryption key as shown above before running Docker Compose commands with the updated configuration. Back up the database, apply each migration that has not already been applied, then rebuild the web container:

```sh
mkdir -p backups
docker compose exec -T db mysqldump --no-tablespaces -uroot -prootpassword dnr > backups/dnr-before-2fa.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260814_add_user_timestamps.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260814_add_two_factor_authentication.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260814_add_last_login_at.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260814_add_must_change_password.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260814_add_shared_calendar.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260815_split_contact_names.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260815_add_contact_archiving.sql
docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260815_add_event_title.sql
docker compose up -d --build
```

Each migration is one-time. Skip the timestamp migration if it was applied previously. Existing administrators will be required to enroll 2FA after their next password login; other roles can enable it from **Account Security**.

For a database created before the user timestamp columns were added, the timestamp migration by itself is:

   ```sh
   docker compose exec -T db mysql -udnruser -pdnrpassword dnr < migrations/20260814_add_user_timestamps.sql
   ```

### Configuration

Database Initialization:

The init.sql file contains the necessary SQL commands to set up the initial database schema and data. Ensure that this script is executed when the database service starts.

Environment Variables:

Configure these values as needed:

- `PORT`: published HTTP port; defaults to `8080`.
- `DEFAULT_SPEAKER`: default speaker shown in engagement forms.
- `DNR_2FA_KEY_FILE`: host path to the Docker secret containing the base64-encoded 2FA encryption key; defaults to `./secrets/dnr_2fa_encryption_key`.
- `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, and `MYSQL_PASSWORD`: database connection settings.

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

Authenticated users can open **Calendar** in the navigation to copy the single shared subscription URL or open it in a calendar app. The public feed includes every active (non-archived) engagement, regardless of status. Titles include the status and use the engagement's event title when available; entries are all-day events covering the engagement date range and include the organization, event type, and location. Calendar clients choose their own refresh schedule, so database changes may not appear immediately.

The subscription URL is public and does not require a DNR login. Anyone with it can read the calendar-safe event fields. Contacts, chronological notes, travel, lodging, and compensation are never included.

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
