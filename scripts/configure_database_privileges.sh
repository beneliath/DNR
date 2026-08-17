#!/bin/sh
set -eu

case "${MYSQL_DATABASE:-}" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_DATABASE contains unsupported characters" >&2; exit 1 ;;
esac
case "${MYSQL_USER:-}" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_USER contains unsupported characters" >&2; exit 1 ;;
esac

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
