#!/bin/sh
# Start this at the beginning of release preparation, before versioning or CI.
set -eu
project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_directory"
action=${1:-status}
case "$action" in start|cancel|status|resolve) ;; *) echo 'Usage: deployment_notice.sh start|cancel|status|resolve [NOTICE_ID]' >&2; exit 64 ;; esac
state_directory=$(git rev-parse --git-path dnr-deploy)
mkdir -p "$state_directory"
identifier=${2:-${DNR_DEPLOYMENT_NOTICE_ID:-}}
if [ -z "$identifier" ] && [ -f "$state_directory/notice-id" ]; then
    identifier=$(cat "$state_directory/notice-id")
fi
if [ "$action" = start ] && [ -z "$identifier" ]; then
    identifier=$(python3 -c 'import uuid; print(uuid.uuid4().hex)')
fi
if [ "$action" != status ]; then
    printf '%s\n' "$identifier" | grep -Eq '^[a-f0-9]{32}$' || { echo 'A valid deployment notice ID is required.' >&2; exit 64; }
fi
s1_user=${DNR_S1_USER:-dgilmore}
s1_host=${DNR_S1_HOST:-192.168.1.150}
s1_project_directory=${DNR_S1_PROJECT_DIR:-/home/dgilmore/moed}
printf '%s\n' "$s1_user" | grep -Eq '^[A-Za-z0-9._-]+$'
printf '%s\n' "$s1_host" | grep -Eq '^[A-Za-z0-9.:-]+$'
printf '%s\n' "$s1_project_directory" | grep -Eq '^/[A-Za-z0-9._/-]+$'
if [ "$action" = start ]; then
    # Keep the ID even if SSH loses its reply, so a retry is idempotent.
    (umask 077; printf '%s\n' "$identifier" > "$state_directory/notice-id")
fi
ssh -o BatchMode=yes -o ConnectTimeout=10 "$s1_user@$s1_host" \
    "python3 - '$s1_project_directory' '$action' '$identifier'" < scripts/deployment_notice.py
case "$action" in cancel|resolve)
    if [ -f "$state_directory/notice-id" ] && [ "$(cat "$state_directory/notice-id")" = "$identifier" ]; then
        rm "$state_directory/notice-id"
    fi
esac
