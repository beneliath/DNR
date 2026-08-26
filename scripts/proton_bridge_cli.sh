#!/bin/sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

usage() {
    cat <<'EOF'
Usage:
  scripts/proton_bridge_cli.sh configure
  scripts/proton_bridge_cli.sh status
  scripts/proton_bridge_cli.sh logs
  scripts/proton_bridge_cli.sh version

The configure command stops the Bridge and inbound worker, then opens Proton's
interactive CLI. Use "login", "updates autoupdates disable", "info", and
"exit". Copy only the generated Bridge IMAP password to secrets/imap_password.
EOF
}

compose() {
    docker compose \
        -f docker-compose.yaml \
        -f docker-compose.mail.yaml \
        -f docker-compose.smtp.yaml \
        -f docker-compose.proton-bridge.yaml \
        "$@"
}

command -v docker >/dev/null 2>&1 || {
    echo 'Docker is required.' >&2
    exit 1
}

cd "$project_directory"
case "${1:-}" in
    configure)
        compose stop mail-ingest proton-bridge
        compose build proton-bridge
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.proton-bridge.yaml \
            run --rm --no-deps proton-bridge --cli
        ;;
    status)
        compose ps proton-bridge mail-ingest mail-dispatch
        ;;
    logs)
        compose logs --tail=200 proton-bridge mail-ingest mail-dispatch
        ;;
    version)
        compose run --rm --no-deps proton-bridge --version
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
