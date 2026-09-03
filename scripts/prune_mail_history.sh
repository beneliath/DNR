#!/bin/sh
set -eu

retention_days=${1:-}
confirmation=${2:-}

if [ "$retention_days" = '-h' ] || [ "$retention_days" = '--help' ]; then
    cat <<'EOF'
Usage: ./scripts/prune_mail_history.sh DAYS_TO_KEEP [PRUNE]

Preview or remove terminal account/notification outbox rows and processed,
rejected, or failed inbound source messages. Pending and review work is kept.
At most 10000 rows per category are removed per confirmed invocation.
EOF
    exit 0
fi
if [ "$#" -gt 2 ]; then
    echo 'Usage: ./scripts/prune_mail_history.sh DAYS_TO_KEEP [PRUNE]' >&2
    exit 64
fi
case "$retention_days" in
    ''|*[!0-9]*)
        echo 'DAYS_TO_KEEP must be a whole number from 30 through 36500.' >&2
        exit 64
        ;;
esac
if [ "$retention_days" -lt 30 ] || [ "$retention_days" -gt 36500 ]; then
    echo 'DAYS_TO_KEEP must be a whole number from 30 through 36500.' >&2
    exit 64
fi
if [ -n "$confirmation" ] && [ "$confirmation" != 'PRUNE' ]; then
    echo 'Mail history not changed. Pass PRUNE, or omit it for a preview.' >&2
    exit 64
fi

exec docker compose run --rm --no-deps --entrypoint php maintenance \
    /opt/dnr/bin/prune_audit_log.php --mail-history "$retention_days" ${confirmation:+"$confirmation"}
