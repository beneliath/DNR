#!/bin/sh
set -eu

migration_directory=${DNR_MIGRATION_DIRECTORY:-/opt/dnr/migrations}
migration_order_file=${DNR_MIGRATION_ORDER_FILE:-$migration_directory/order.txt}
database_name=${MYSQL_DATABASE:-dnr}
application_user=${MYSQL_USER:-dnruser}
database_host=${DB_HOST:-}

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

mysql_root() {
    if [ -n "$database_host" ]; then
        mysql --protocol=tcp -h "$database_host" -uroot "$@"
    else
        mysql --protocol=socket -uroot "$@"
    fi
}

lock_name="dnr_${database_name}_migrations"
lock_acquired=$(mysql_root -Nse "SELECT GET_LOCK('$lock_name', 60)")
if [ "$lock_acquired" != "1" ]; then
    echo "Could not obtain the database migration lock." >&2
    exit 1
fi

migration_in_progress=
cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$status" -ne 0 ] && [ -n "$migration_in_progress" ]; then
        mysql_root "$database_name" -e "
            UPDATE schema_migrations
            SET state = 'failed', migration_updated_at = CURRENT_TIMESTAMP
            WHERE migration_name = '$migration_in_progress' AND state = 'applying'" \
            >/dev/null 2>&1 || true
    fi
    mysql_root -Nse "SELECT RELEASE_LOCK('$lock_name')" >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

mysql_root "$database_name" -e "
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(255) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    state ENUM('applying', 'applied', 'failed') NOT NULL DEFAULT 'applied',
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    migration_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)"

has_state=$(mysql_root -Nse "
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = '$database_name'
      AND table_name = 'schema_migrations'
      AND column_name = 'state'")
if [ "$has_state" = "0" ]; then
    mysql_root "$database_name" -e "
        ALTER TABLE schema_migrations
            ADD COLUMN state ENUM('applying', 'applied', 'failed')
                NOT NULL DEFAULT 'applied' AFTER checksum,
            ADD COLUMN migration_updated_at TIMESTAMP NOT NULL
                DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                AFTER applied_at"
fi

if [ ! -r "$migration_order_file" ]; then
    echo "Migration order manifest is unavailable: $migration_order_file" >&2
    exit 1
fi
duplicate_migration=$(awk '
    /^[[:space:]]*($|#)/ { next }
    { count[$0]++ }
    END { for (name in count) if (count[name] > 1) { print name; exit } }
' "$migration_order_file")
if [ -n "$duplicate_migration" ]; then
    echo "Migration appears more than once in order.txt: $duplicate_migration" >&2
    exit 1
fi
for migration_path in "$migration_directory"/*.sql; do
    [ -f "$migration_path" ] || continue
    listed_name=$(basename "$migration_path")
    if ! grep -Fxq "$listed_name" "$migration_order_file"; then
        echo "Migration is missing from order.txt: $listed_name" >&2
        exit 1
    fi
done

while IFS= read -r migration_name || [ -n "$migration_name" ]
do
    case "$migration_name" in
        ''|'#'*) continue ;;
    esac
    case "$migration_name" in
        *[!A-Za-z0-9_.-]*) echo "Migration filename contains unsupported characters: $migration_name" >&2; exit 1 ;;
    esac
    migration_path="$migration_directory/$migration_name"
    if [ ! -f "$migration_path" ]; then
        echo "Ordered migration file is unavailable: $migration_name" >&2
        exit 1
    fi
    migration_checksum=$(sha256sum "$migration_path" | awk '{print $1}')
    stored_checksum=$(mysql_root -Nse "
        SELECT checksum FROM schema_migrations WHERE migration_name = '$migration_name'" "$database_name")
    if [ -n "$stored_checksum" ]; then
        stored_state=$(mysql_root -Nse "
            SELECT state FROM schema_migrations WHERE migration_name = '$migration_name'" "$database_name")
        if [ "$stored_state" != "applied" ]; then
            echo "Migration requires operator review after an interrupted or failed attempt: $migration_name" >&2
            exit 1
        fi
        if [ "$stored_checksum" = "$(printf '%064d' 0)" ]; then
            mysql_root "$database_name" -e "
                UPDATE schema_migrations
                SET checksum = '$migration_checksum', state = 'applied'
                WHERE migration_name = '$migration_name' AND checksum = REPEAT('0', 64)"
            echo "Sealed baseline checksum for $migration_name"
        elif [ "$stored_checksum" != "$migration_checksum" ]; then
            echo "Applied migration checksum mismatch: $migration_name" >&2
            echo "Create a new migration instead of editing an applied migration." >&2
            exit 1
        fi
        continue
    fi

    if [ "$migration_name" = "20260813_baseline.sql" ]; then
        baseline_tables=$(mysql_root -Nse "
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = '$database_name'
              AND table_name IN ('users', 'organizations', 'engagements', 'presentations', 'contacts')")
        if [ "$baseline_tables" = "5" ]; then
            mysql_root "$database_name" -e "
                INSERT INTO schema_migrations (migration_name, checksum, state)
                VALUES ('$migration_name', '$migration_checksum', 'applied')"
            echo "Recorded the existing pre-migration schema baseline."
            continue
        fi
    fi

    echo "Applying $migration_name"
    migration_in_progress=$migration_name
    mysql_root "$database_name" -e "
        INSERT INTO schema_migrations (migration_name, checksum, state)
        VALUES ('$migration_name', '$migration_checksum', 'applying')"
    mysql_root "$database_name" < "$migration_path"
    mysql_root "$database_name" -e "
        UPDATE schema_migrations
        SET checksum = '$migration_checksum', state = 'applied',
            applied_at = CURRENT_TIMESTAMP
        WHERE migration_name = '$migration_name'"
    migration_in_progress=
done < "$migration_order_file"

privilege_script=${DNR_PRIVILEGE_SCRIPT:-/opt/dnr/bin/configure_database_privileges}
if [ ! -r "$privilege_script" ]; then
    echo "The database privilege script is unavailable: $privilege_script" >&2
    exit 1
fi
sh "$privilege_script"

echo "Database migrations are current."
