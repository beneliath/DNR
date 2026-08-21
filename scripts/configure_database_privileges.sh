#!/bin/sh
set -eu

case "${MYSQL_DATABASE:-}" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_DATABASE contains unsupported characters" >&2; exit 1 ;;
esac
case "${MYSQL_USER:-}" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_USER contains unsupported characters" >&2; exit 1 ;;
esac

root_password=${MYSQL_ROOT_PASSWORD:-}
if [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ]; then
    root_password=$(cat "${MYSQL_ROOT_PASSWORD_FILE}")
fi
if [ -z "$root_password" ]; then
    echo "The database root password secret is required" >&2
    exit 1
fi

maintenance_user=${MYSQL_MAINTENANCE_USER:-dnrmaintenance}
case "$maintenance_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_MAINTENANCE_USER contains unsupported characters" >&2; exit 1 ;;
esac
maintenance_password=${MYSQL_MAINTENANCE_PASSWORD:-}
if [ -n "${MYSQL_MAINTENANCE_PASSWORD_FILE:-}" ]; then
    maintenance_password=$(cat "${MYSQL_MAINTENANCE_PASSWORD_FILE}")
fi
case "$maintenance_password" in
    ''|*[!A-Za-z0-9]*)
        echo "The maintenance password secret must contain only letters and numbers" >&2
        exit 1
        ;;
esac
if [ "${#maintenance_password}" -lt 32 ]; then
    echo "The maintenance password secret must be at least 32 characters" >&2
    exit 1
fi

MYSQL_PWD=$root_password mysql --protocol=socket -uroot <<SQL
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${MYSQL_USER}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.schema_migrations TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.users TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.user_recovery_codes TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.security_audit_log TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.authentication_rate_limits TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.calendar_subscriptions TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.organizations TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagements TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_map_geocodes TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_map_geocode_queue TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_chron_entries TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.presentations TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.contacts TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.follow_up_tasks TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.standard_event_tasks TO '${MYSQL_USER}'@'%';
CREATE USER IF NOT EXISTS '${maintenance_user}'@'%' IDENTIFIED BY '${maintenance_password}';
ALTER USER '${maintenance_user}'@'%' IDENTIFIED BY '${maintenance_password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${maintenance_user}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.* TO '${maintenance_user}'@'%';
FLUSH PRIVILEGES;
SQL
