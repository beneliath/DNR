#!/bin/sh
set -eu
# The upstream entrypoint probes mysqld as root before switching to mysql.
# Pre-create its empty keyring with the correct owner so that probe cannot
# leave a root-owned file which prevents first initialization as mysql.
if [ "$(id -u)" = "0" ]; then
    install -d -m 0700 -o mysql -g mysql /tmp/innodb-session-temp
    install -d -m 0700 -o mysql -g mysql /var/lib/mysql-keyring
    if [ ! -e /var/lib/mysql-keyring/keyring ]; then
        install -m 0600 -o mysql -g mysql /dev/null /var/lib/mysql-keyring/keyring
    fi
else
    mkdir -p /tmp/innodb-session-temp
    chmod 0700 /tmp/innodb-session-temp
fi
exec /usr/local/bin/docker-entrypoint.sh "$@"
