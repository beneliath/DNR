#!/bin/sh
set -eu

# One-time transition helper for deployments created with database passwords
# in container environment variables. It never prints generated credentials.
umask 077

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
database_container=${DNR_DB_CONTAINER:-dnr-db-1}
environment_path="$project_directory/.env"
backup_directory="$project_directory/backups"
secret_directory="$project_directory/secrets"

if [ -e "$environment_path" ]; then
    echo ".env already exists; refusing to overwrite deployment configuration." >&2
    exit 1
fi

command -v docker >/dev/null 2>&1 || { echo "docker is required" >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 1; }
"$project_directory/scripts/compose_with_provenance.sh" --print-metadata >/dev/null
docker inspect "$database_container" >/dev/null 2>&1 || {
    echo "Database container $database_container is not available." >&2
    exit 1
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
new_maintenance_password=$(openssl rand -hex 32)

sql_file=$(mktemp "${TMPDIR:-/tmp}/dnr-credential-rotation.XXXXXX")
environment_temp=$(mktemp "$project_directory/.env.XXXXXX")
cleanup() {
    rm -f "$sql_file" "$environment_temp"
}
trap cleanup EXIT HUP INT TERM

printf "%s\n" \
    "ALTER USER 'root'@'localhost' IDENTIFIED BY '$new_root_password';" \
    "ALTER USER 'dnruser'@'%' IDENTIFIED BY '$new_app_password';" \
    "CREATE USER IF NOT EXISTS 'dnrmaintenance'@'%' IDENTIFIED BY '$new_maintenance_password';" \
    "ALTER USER 'dnrmaintenance'@'%' IDENTIFIED BY '$new_maintenance_password';" \
    > "$sql_file"
docker exec -i "$database_container" sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < "$sql_file"

printf '%s' "$new_root_password" > "$secret_directory/mysql_root_password"
printf '%s' "$new_app_password" > "$secret_directory/mysql_app_password"
printf '%s' "$new_maintenance_password" > "$secret_directory/mysql_maintenance_password"
: > "$secret_directory/backup_password"
if [ ! -s "$secret_directory/dnr_2fa_encryption_key" ]; then
    openssl rand -base64 32 > "$secret_directory/dnr_2fa_encryption_key"
fi
chmod 600 "$secret_directory"/*

printf "%s\n" \
    'DNR_MYSQL_ROOT_PASSWORD_FILE=./secrets/mysql_root_password' \
    'DNR_MYSQL_APP_PASSWORD_FILE=./secrets/mysql_app_password' \
    'DNR_MYSQL_MAINTENANCE_PASSWORD_FILE=./secrets/mysql_maintenance_password' \
    'DNR_2FA_KEY_FILE=./secrets/dnr_2fa_encryption_key' \
    'DNR_BACKUP_PASSWORD_FILE=./secrets/backup_password' \
    'DNR_REQUIRE_HTTPS=1' \
    'DNR_BIND_ADDRESS=127.0.0.1' \
    'PORT=8080' \
    'DNR_PUBLIC_BASE_URL=https://dnr.example.org' \
    > "$environment_temp"
chmod 600 "$environment_temp"
mv "$environment_temp" "$environment_path"

cd "$project_directory"
docker compose up -d --force-recreate db
docker compose exec db sh /opt/dnr/bin/migrate
docker compose exec db sh /docker-entrypoint-initdb.d/99-configure_database_privileges.sh
"$project_directory/scripts/compose_with_provenance.sh" production up -d --build web geocoder

echo "Database credentials were moved to separate secret files and the maintenance account was isolated."
echo "The pre-rotation safety backup is $backup_path"
echo "Review DNR_PUBLIC_BASE_URL and trusted proxy settings in .env before accepting traffic."
