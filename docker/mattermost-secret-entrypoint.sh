#!/bin/sh
set -eu

source_path=${DNR_MATTERMOST_TOKEN_FILE:-}
if [ -n "$source_path" ]; then
    if [ ! -r "$source_path" ]; then
        echo "The configured Mattermost token file is not readable by the container entrypoint." >&2
        exit 1
    fi

    runtime_path=/run/dnr-mattermost/token
    runtime_tmp="${runtime_path}.$$"
    trap 'rm -f "$runtime_tmp"' EXIT HUP INT TERM
    install -m 0400 -o www-data -g www-data "$source_path" "$runtime_tmp"
    mv -f "$runtime_tmp" "$runtime_path"
    trap - EXIT HUP INT TERM
    export DNR_MATTERMOST_TOKEN_FILE=$runtime_path
fi

exec docker-php-entrypoint "$@"
