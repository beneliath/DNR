#!/bin/sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
secrets_directory=${1:-"${project_directory}/secrets"}

command -v setfacl >/dev/null 2>&1 || {
    echo 'setfacl is required (Ubuntu package: acl).' >&2
    exit 1
}

if [ ! -d "$secrets_directory" ]; then
    echo "Secrets directory not found: $secrets_directory" >&2
    exit 1
fi

chmod 700 "$secrets_directory"
find "$secrets_directory" -maxdepth 1 -type f -exec chmod 600 {} +

# Native Linux Compose implements local secrets as read-only bind mounts. The
# migrator is UID 0 with every capability dropped, and the dedicated workers
# run as www-data (UID 33). File ACLs let those two container identities read
# the mounted files without making the host secrets group/world-readable. The
# mode ACL is deliberately restricted to read-only for both named identities.
find "$secrets_directory" -maxdepth 1 -type f \
    -exec setfacl -m u:0:r--,u:33:r--,m::r-- {} +

printf 'Prepared Linux secret ACLs in %s\n' "$secrets_directory"
