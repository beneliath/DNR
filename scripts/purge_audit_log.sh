#!/bin/sh
set -eu

if [ "${1:-}" != "PURGE" ]; then
    echo "Refusing to purge. Run: purge_audit_log.sh PURGE" >&2
    exit 64
fi

database_name=${MYSQL_DATABASE:-dnr}
case "$database_name" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_DATABASE contains unsupported characters" >&2; exit 1 ;;
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
mysql --protocol=socket -uroot "$database_name" <<'SQL'
START TRANSACTION;
SET @purged_entry_count = (SELECT COUNT(*) FROM security_audit_log);
DELETE FROM security_audit_log;
INSERT INTO security_audit_log (
    event_category, event_type, entity_type, entity_label, details, ip_address
) VALUES (
    'security',
    'audit_log_purged',
    'audit_log',
    'DNR audit log',
    CONCAT('Database maintenance command permanently deleted ', @purged_entry_count, ' prior entries'),
    'local-maintenance'
);
COMMIT;
SELECT CONCAT('Purged ', @purged_entry_count, ' audit entries.');
SQL
