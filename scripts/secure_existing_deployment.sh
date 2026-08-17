#!/bin/sh
set -eu

# One-time transition helper for deployments that were created with the old
# committed database passwords. It never prints generated credentials.
umask 077

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
database_container=${DNR_DB_CONTAINER:-dnr-db-1}
environment_path="$project_directory/.env"
backup_directory="$project_directory/backups"

if [ -e "$environment_path" ]; then
    echo ".env already exists; refusing to overwrite deployment credentials." >&2
    exit 1
fi

command -v docker >/dev/null 2>&1 || { echo "docker is required" >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "openssl is required" >&2; exit 1; }
docker inspect "$database_container" >/dev/null 2>&1 || {
    echo "Database container $database_container is not available." >&2
    exit 1
}

mkdir -p "$backup_directory"
backup_path="$backup_directory/dnr-before-secret-rotation-$(date -u +%Y%m%dT%H%M%SZ).sql"
docker exec "$database_container" sh -c \
    'exec mysqldump --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    > "$backup_path"
chmod 600 "$backup_path"

new_root_password=$(openssl rand -hex 32)
new_app_password=$(openssl rand -hex 32)
new_calendar_token=$(openssl rand -hex 32)

sql_file=$(mktemp "${TMPDIR:-/tmp}/dnr-credential-rotation.XXXXXX")
environment_temp=$(mktemp "$project_directory/.env.XXXXXX")
cleanup() {
    rm -f "$sql_file" "$environment_temp"
}
trap cleanup EXIT HUP INT TERM

printf "%s\n" \
    "ALTER USER 'root'@'localhost' IDENTIFIED BY '$new_root_password';" \
    "ALTER USER 'dnruser'@'%' IDENTIFIED BY '$new_app_password';" \
    > "$sql_file"
docker exec -i "$database_container" sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < "$sql_file"

printf "%s\n" \
    "MYSQL_ROOT_PASSWORD=$new_root_password" \
    "MYSQL_PASSWORD=$new_app_password" \
    "DNR_CALENDAR_TOKEN=$new_calendar_token" \
    "DNR_BIND_ADDRESS=127.0.0.1" \
    "PORT=8080" \
    "DNR_PUBLIC_BASE_URL=https://dnr.beneliath.com" \
    > "$environment_temp"
chmod 600 "$environment_temp"
mv "$environment_temp" "$environment_path"

cd "$project_directory"
docker compose up -d --force-recreate db web

echo "Database credentials were rotated and the private calendar token was created."
echo "The pre-rotation backup is $backup_path"
