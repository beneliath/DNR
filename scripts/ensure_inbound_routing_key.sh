#!/bin/sh
set -eu

umask 077
project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
secret_path=${DNR_INBOUND_ROUTING_KEY_FILE:-$project_directory/secrets/dnr_inbound_routing_key}
secret_directory=$(dirname -- "$secret_path")

command -v openssl >/dev/null 2>&1 || {
    echo "openssl is required to create the inbound routing key." >&2
    exit 1
}

mkdir -p "$secret_directory"
chmod 700 "$secret_directory"
if [ ! -e "$secret_path" ]; then
    openssl rand -base64 32 > "$secret_path"
fi
chmod 600 "$secret_path"

key_length=$(openssl base64 -d -A < "$secret_path" | wc -c | tr -d ' ')
if [ "$key_length" != "32" ]; then
    echo "The inbound routing key must be a base64-encoded 32-byte key: $secret_path" >&2
    exit 1
fi

echo "Inbound routing key is ready."
