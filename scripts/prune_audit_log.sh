#!/bin/sh
set -eu

retention_days=${1:-}
confirmation=${2:-}

if [ "$retention_days" = '-h' ] || [ "$retention_days" = '--help' ]; then
    cat <<'EOF'
Usage: ./scripts/prune_audit_log.sh DAYS_TO_KEEP [PRUNE]

Omit PRUNE to preview the UTC cutoff and number of entries that would be
deleted. Repeat the command with the literal PRUNE confirmation to delete them.

Example:
  ./scripts/prune_audit_log.sh 365
  ./scripts/prune_audit_log.sh 365 PRUNE
EOF
    exit 0
fi

if [ "$#" -gt 2 ]; then
    echo 'Usage: ./scripts/prune_audit_log.sh DAYS_TO_KEEP [PRUNE]' >&2
    exit 64
fi
case "$retention_days" in
    ''|*[!0-9]*)
        echo 'DAYS_TO_KEEP must be a whole number from 1 through 36500.' >&2
        exit 64
        ;;
esac
if [ "$retention_days" -lt 1 ] || [ "$retention_days" -gt 36500 ]; then
    echo 'DAYS_TO_KEEP must be a whole number from 1 through 36500.' >&2
    exit 64
fi
if [ -n "$confirmation" ] && [ "$confirmation" != 'PRUNE' ]; then
    echo 'Audit log not changed. Pass PRUNE, or omit it for a preview.' >&2
    exit 64
fi

if [ -n "$confirmation" ]; then
    exec docker compose run --rm --no-deps --entrypoint php maintenance \
        /opt/dnr/bin/prune_audit_log.php "$retention_days" "$confirmation"
fi
exec docker compose run --rm --no-deps --entrypoint php maintenance \
    /opt/dnr/bin/prune_audit_log.php "$retention_days"
