#!/bin/sh
set -eu

# One-time transition helper for deployments created with database passwords
# in container environment variables. It never prints generated credentials.
umask 077

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
database_container=${DNR_DB_CONTAINER:-dnr-db-1}
compose_mode=${DNR_COMPOSE_MODE:-production}
environment_path="$project_directory/.env"
backup_directory="$project_directory/backups"
secret_directory="$project_directory/secrets"

case "$compose_mode" in
    development|dev)
        compose_mode=development
        default_require_https=0
        default_public_base_url=http://localhost:8080
        ;;
    production|prod)
        compose_mode=production
        default_require_https=1
        default_public_base_url=https://dnr.example.org
        ;;
    *)
        echo "DNR_COMPOSE_MODE must be development or production." >&2
        exit 1
        ;;
esac

if [ -e "$environment_path" ]; then
    if grep -Eq '^[[:space:]]*DNR_MYSQL_(ROOT|APP|MAINTENANCE)_PASSWORD_FILE[[:space:]]*=' "$environment_path"; then
        echo ".env already uses database secret files; refusing to repeat the one-time migration." >&2
        exit 1
    fi
    if ! grep -Eq '^[[:space:]]*MYSQL_ROOT_PASSWORD[[:space:]]*=' "$environment_path" \
        || ! grep -Eq '^[[:space:]]*MYSQL_PASSWORD[[:space:]]*=' "$environment_path"; then
        echo ".env is not a recognized legacy configuration; refusing to overwrite it." >&2
        exit 1
    fi
fi

for secret_name in \
    mysql_root_password mysql_app_password mysql_backup_password mysql_maintenance_password \
    mysql_geocoder_password mysql_mail_ingest_password mysql_mail_dispatch_password \
    backup_password
do
    if [ -e "$secret_directory/$secret_name" ]; then
        echo "A database secret file already exists; refusing a potentially partial migration." >&2
        exit 1
    fi
done

command -v docker >/dev/null 2>&1 || { echo "docker is required" >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 1; }
"$project_directory/scripts/compose_with_provenance.sh" --print-metadata >/dev/null
docker inspect "$database_container" >/dev/null 2>&1 || {
    echo "Database container $database_container is not available." >&2
    exit 1
}

compose() {
    if [ "$compose_mode" = development ]; then
        docker compose -f docker-compose.yaml -f docker-compose.dev.yaml "$@"
    else
        docker compose -f docker-compose.yaml "$@"
    fi
}

mkdir -p "$backup_directory" "$secret_directory"
chmod 700 "$backup_directory" "$secret_directory"
backup_path="$backup_directory/dnr-before-secret-rotation-$(date -u +%Y%m%dT%H%M%SZ).sql"
docker exec "$database_container" sh -c \
    'exec mysqldump --single-transaction --routines --triggers --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    > "$backup_path"
chmod 600 "$backup_path"

new_root_password=$(openssl rand -hex 32)
new_app_password=$(openssl rand -hex 32)
new_backup_password=$(openssl rand -hex 32)
new_maintenance_password=$(openssl rand -hex 32)
new_geocoder_password=$(openssl rand -hex 32)
new_mail_ingest_password=$(openssl rand -hex 32)
new_mail_dispatch_password=$(openssl rand -hex 32)

sql_file=$(mktemp "${TMPDIR:-/tmp}/dnr-credential-rotation.XXXXXX")
environment_temp=$(mktemp "$project_directory/.env.XXXXXX")
cleanup() {
    rm -f "$sql_file" "$environment_temp"
}
trap cleanup EXIT HUP INT TERM

printf "%s\n" \
    "ALTER USER 'root'@'localhost' IDENTIFIED BY '$new_root_password';" \
    "ALTER USER 'dnruser'@'%' IDENTIFIED BY '$new_app_password';" \
    "CREATE USER IF NOT EXISTS 'dnrbackup'@'%' IDENTIFIED BY '$new_backup_password';" \
    "ALTER USER 'dnrbackup'@'%' IDENTIFIED BY '$new_backup_password';" \
    "CREATE USER IF NOT EXISTS 'dnrmaintenance'@'%' IDENTIFIED BY '$new_maintenance_password';" \
    "ALTER USER 'dnrmaintenance'@'%' IDENTIFIED BY '$new_maintenance_password';" \
    "CREATE USER IF NOT EXISTS 'dnrgeocoder'@'%' IDENTIFIED BY '$new_geocoder_password';" \
    "ALTER USER 'dnrgeocoder'@'%' IDENTIFIED BY '$new_geocoder_password';" \
    "CREATE USER IF NOT EXISTS 'dnrmailingest'@'%' IDENTIFIED BY '$new_mail_ingest_password';" \
    "ALTER USER 'dnrmailingest'@'%' IDENTIFIED BY '$new_mail_ingest_password';" \
    "CREATE USER IF NOT EXISTS 'dnrmaildispatch'@'%' IDENTIFIED BY '$new_mail_dispatch_password';" \
    "ALTER USER 'dnrmaildispatch'@'%' IDENTIFIED BY '$new_mail_dispatch_password';" \
    > "$sql_file"
docker exec -i "$database_container" sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < "$sql_file"

printf '%s' "$new_root_password" > "$secret_directory/mysql_root_password"
printf '%s' "$new_app_password" > "$secret_directory/mysql_app_password"
printf '%s' "$new_backup_password" > "$secret_directory/mysql_backup_password"
printf '%s' "$new_maintenance_password" > "$secret_directory/mysql_maintenance_password"
printf '%s' "$new_geocoder_password" > "$secret_directory/mysql_geocoder_password"
printf '%s' "$new_mail_ingest_password" > "$secret_directory/mysql_mail_ingest_password"
printf '%s' "$new_mail_dispatch_password" > "$secret_directory/mysql_mail_dispatch_password"
: > "$secret_directory/backup_password"
if [ ! -s "$secret_directory/dnr_2fa_encryption_key" ]; then
    openssl rand -base64 32 > "$secret_directory/dnr_2fa_encryption_key"
fi
chmod 600 "$secret_directory"/*

printf "%s\n" \
    '# Database and application secrets are mounted from ignored files.' \
    'DNR_MYSQL_ROOT_PASSWORD_FILE=./secrets/mysql_root_password' \
    'DNR_MYSQL_APP_PASSWORD_FILE=./secrets/mysql_app_password' \
    'DNR_MYSQL_BACKUP_PASSWORD_FILE=./secrets/mysql_backup_password' \
    'DNR_MYSQL_MAINTENANCE_PASSWORD_FILE=./secrets/mysql_maintenance_password' \
    'DNR_MYSQL_GEOCODER_PASSWORD_FILE=./secrets/mysql_geocoder_password' \
    'DNR_MYSQL_MAIL_INGEST_PASSWORD_FILE=./secrets/mysql_mail_ingest_password' \
    'DNR_MYSQL_MAIL_DISPATCH_PASSWORD_FILE=./secrets/mysql_mail_dispatch_password' \
    'DNR_2FA_KEY_FILE=./secrets/dnr_2fa_encryption_key' \
    'DNR_BACKUP_PASSWORD_FILE=./secrets/backup_password' \
    > "$environment_temp"

# Copy only explicitly approved, non-secret settings from a recognized legacy
# file. The file is parsed as data and is never sourced or evaluated.
if [ -e "$environment_path" ]; then
    awk '
        BEGIN {
            allowed["DEFAULT_SPEAKER"] = 1
            allowed["DNR_REQUIRE_HTTPS"] = 1
            allowed["DNR_BIND_ADDRESS"] = 1
            allowed["PORT"] = 1
            allowed["DNR_PUBLIC_BASE_URL"] = 1
            allowed["DNR_TRUSTED_PROXY_IPS"] = 1
            allowed["DNR_TRUSTED_CLOUDFLARE_PROXY_IPS"] = 1
            allowed["DNR_BACKEND_SUBNET"] = 1
            allowed["DNR_INGRESS_PROXY_IP"] = 1
            allowed["DNR_TIMEZONE"] = 1
            allowed["DNR_DATABASE_BACKUP_MAX_BYTES"] = 1
            allowed["DNR_GITHUB_REPOSITORY"] = 1
            allowed["DNR_SESSION_IDLE_SECONDS"] = 1
            allowed["DNR_SESSION_ABSOLUTE_SECONDS"] = 1
            allowed["DNR_SESSION_ROTATION_SECONDS"] = 1
            allowed["DNR_MAP_PAST_DAYS"] = 1
            allowed["DNR_MAP_FUTURE_DAYS"] = 1
            allowed["DNR_MAP_MAX_EVENTS"] = 1
            allowed["DNR_GEOCODER_BASE_URL"] = 1
            allowed["DNR_GEOCODER_ALLOWED_HOSTS"] = 1
            allowed["DNR_GEOCODER_USER_AGENT"] = 1
            allowed["DNR_GEOCODER_BATCH_SIZE"] = 1
            allowed["DNR_GEOCODER_IDLE_SECONDS"] = 1
        }
        {
            separator = index($0, "=")
            if (separator == 0) {
                next
            }
            key = substr($0, 1, separator - 1)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", key)
            if (key in allowed) {
                value[key] = substr($0, separator + 1)
            }
        }
        END {
            for (key in value) {
                print key "=" value[key]
            }
        }
    ' "$environment_path" >> "$environment_temp"
fi

append_default() {
    setting_name=$1
    setting_value=$2
    if ! grep -Eq "^${setting_name}=" "$environment_temp"; then
        printf '%s=%s\n' "$setting_name" "$setting_value" >> "$environment_temp"
    fi
}

append_default DNR_REQUIRE_HTTPS "$default_require_https"
append_default DNR_BIND_ADDRESS 127.0.0.1
append_default PORT 8080
append_default DNR_PUBLIC_BASE_URL "$default_public_base_url"
chmod 600 "$environment_temp"
mv "$environment_temp" "$environment_path"

cd "$project_directory"
compose up -d --force-recreate --wait --wait-timeout "${DNR_COMPOSE_WAIT_TIMEOUT:-120}" db
compose exec db sh /opt/dnr/bin/migrate
"$project_directory/scripts/compose_with_provenance.sh" "$compose_mode" up -d --build web geocoder ingress

echo "Database credentials were moved to separate secret files and service accounts were isolated."
echo "The pre-rotation safety backup is $backup_path"
echo "Review DNR_PUBLIC_BASE_URL and trusted proxy settings in .env before accepting traffic."
