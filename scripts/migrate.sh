#!/bin/sh
set -eu

migration_directory=${DNR_MIGRATION_DIRECTORY:-/opt/dnr/migrations}
database_name=${MYSQL_DATABASE:-dnr}
application_user=${MYSQL_USER:-dnruser}

case "$database_name" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_DATABASE contains unsupported characters" >&2; exit 1 ;;
esac
case "$application_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_USER contains unsupported characters" >&2; exit 1 ;;
esac

root_password=${MYSQL_ROOT_PASSWORD:-}
if [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ]; then
    root_password=$(cat "${MYSQL_ROOT_PASSWORD_FILE}")
fi
if [ -z "$root_password" ]; then
    echo "MYSQL_ROOT_PASSWORD or MYSQL_ROOT_PASSWORD_FILE is required" >&2
    exit 1
fi
export MYSQL_PWD=$root_password

mysql --protocol=socket -uroot "$database_name" -e "
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(255) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)"

applied_count=$(mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM schema_migrations" "$database_name")
legacy_schema=$(mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'engagement_chron_entries'" "$database_name")

if [ "$applied_count" = "0" ] && [ "$legacy_schema" = "1" ]; then
    for legacy_migration in \
        20260814_add_last_login_at.sql \
        20260814_add_must_change_password.sql \
        20260814_add_shared_calendar.sql \
        20260814_add_two_factor_authentication.sql \
        20260814_add_user_timestamps.sql \
        20260815_add_audit_log.sql \
        20260815_add_contact_archiving.sql \
        20260815_add_event_title.sql \
        20260815_split_contact_names.sql \
        20260817_add_engagement_chron_entries.sql \
        20260817_add_presentation_archiving.sql
    do
        mysql --protocol=socket -uroot "$database_name" -e "
            INSERT IGNORE INTO schema_migrations (migration_name, checksum)
            VALUES ('$legacy_migration', REPEAT('0', 64))"
    done
fi

for migration_path in "$migration_directory"/*.sql
do
    [ -f "$migration_path" ] || continue
    migration_name=$(basename "$migration_path")
    case "$migration_name" in
        *[!A-Za-z0-9_.-]*) echo "Migration filename contains unsupported characters: $migration_name" >&2; exit 1 ;;
    esac
    migration_checksum=$(sha256sum "$migration_path" | awk '{print $1}')
    stored_checksum=$(mysql --protocol=socket -uroot -Nse "
        SELECT checksum FROM schema_migrations WHERE migration_name = '$migration_name'" "$database_name")
    if [ -n "$stored_checksum" ]; then
        if [ "$stored_checksum" = "$(printf '%064d' 0)" ]; then
            mysql --protocol=socket -uroot "$database_name" -e "
                UPDATE schema_migrations
                SET checksum = '$migration_checksum'
                WHERE migration_name = '$migration_name' AND checksum = REPEAT('0', 64)"
            echo "Sealed baseline checksum for $migration_name"
        elif [ "$stored_checksum" != "$migration_checksum" ]; then
            echo "Applied migration checksum mismatch: $migration_name" >&2
            echo "Create a new migration instead of editing an applied migration." >&2
            exit 1
        fi
        continue
    fi

    echo "Applying $migration_name"
    mysql --protocol=socket -uroot "$database_name" < "$migration_path"
    mysql --protocol=socket -uroot "$database_name" -e "
        INSERT INTO schema_migrations (migration_name, checksum)
        VALUES ('$migration_name', '$migration_checksum')
        ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = CURRENT_TIMESTAMP"
done

# New tables are not present when an existing database's initialization grant
# script originally ran. Refresh the least-privilege read grant after applying
# migrations so upgraded installations can read the calendar revision.
mysql --protocol=socket -uroot "$database_name" -e "
    GRANT SELECT ON \`${database_name}\`.calendar_feed_revision TO '${application_user}'@'%'
"

echo "Database migrations are current."
