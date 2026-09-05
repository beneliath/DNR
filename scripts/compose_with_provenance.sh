#!/bin/sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

usage() {
    cat <<'EOF'
Usage:
  scripts/compose_with_provenance.sh development [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh development-mail [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh development-smtp [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh development-smtp-ca [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh development-mail-smtp [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh development-mail-smtp-ca [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh development-mattermost [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-mail [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-smtp [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-smtp-ca [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-mail-smtp [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-mail-smtp-ca [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-mattermost [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-ubuntu [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-ubuntu-mattermost [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-ubuntu-proton [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh production-ubuntu-proton-mattermost [COMPOSE_ARGUMENTS...]
  scripts/compose_with_provenance.sh --print-metadata

With no arguments, development builds locally; production requires DNR_APP_IMAGE and uses --no-build.
EOF
}

build_commit=${DNR_BUILD_COMMIT:-}
build_timestamp=${DNR_BUILD_TIMESTAMP:-}

if [ -n "$build_commit" ] || [ -n "$build_timestamp" ]; then
    if [ -z "$build_commit" ] || [ -z "$build_timestamp" ]; then
        echo "DNR_BUILD_COMMIT and DNR_BUILD_TIMESTAMP must be supplied together." >&2
        exit 1
    fi
else
    command -v git >/dev/null 2>&1 || {
        echo "Git is required when build provenance is not supplied by the environment." >&2
        exit 1
    }
    git -C "$project_directory" rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
        echo "The project must be a Git worktree when build provenance is not supplied." >&2
        exit 1
    }
    if [ -n "$(git -C "$project_directory" status --porcelain --untracked-files=normal)" ]; then
        echo "Refusing to build with uncommitted files because the footer commit would be misleading." >&2
        echo "Commit or stash the changes, or supply explicit DNR_BUILD_COMMIT and DNR_BUILD_TIMESTAMP values in CI." >&2
        exit 1
    fi
    build_commit=$(git -C "$project_directory" rev-parse --verify HEAD)
    build_timestamp=$(
        TZ=UTC git -C "$project_directory" show -s \
            --date='format-local:%Y-%m-%dT%H:%M:%SZ' \
            --format='%cd' HEAD
    )
fi

printf '%s\n' "$build_commit" | grep -Eq '^[0-9A-Fa-f]{40}$' || {
    echo "DNR_BUILD_COMMIT must be a complete 40-character Git commit hash." >&2
    exit 1
}
printf '%s\n' "$build_timestamp" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' || {
    echo "DNR_BUILD_TIMESTAMP must use UTC format YYYY-MM-DDTHH:MM:SSZ." >&2
    exit 1
}

export DNR_BUILD_COMMIT=$build_commit
export DNR_BUILD_TIMESTAMP=$build_timestamp

if [ "${1:-}" = "--print-metadata" ]; then
    printf 'DNR_BUILD_COMMIT=%s\nDNR_BUILD_TIMESTAMP=%s\n' \
        "$DNR_BUILD_COMMIT" "$DNR_BUILD_TIMESTAMP"
    exit 0
fi

mode=${1:-}
if [ -z "$mode" ]; then
    usage >&2
    exit 2
fi
shift
if [ "$#" -eq 0 ]; then
    case "$mode" in
        production*|prod*)
            case "${DNR_APP_IMAGE:-}" in *@sha256:*) ;; *) echo 'Production requires a qualified image digest.' >&2; exit 1 ;; esac
            set -- up -d --no-build ;;
        *) set -- up -d --build ;;
    esac
fi

command -v docker >/dev/null 2>&1 || {
    echo "Docker is required." >&2
    exit 1
}

printf 'DNR build provenance: %.7s at %s\n' "$DNR_BUILD_COMMIT" "$DNR_BUILD_TIMESTAMP"
cd "$project_directory"
case "$mode" in
    development|dev)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            "$@"
        ;;
    development-mail|dev-mail)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            -f docker-compose.mail.yaml \
            "$@"
        ;;
    development-smtp|dev-smtp)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            -f docker-compose.smtp.yaml \
            "$@"
        ;;
    development-smtp-ca|dev-smtp-ca)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.smtp-ca.yaml \
            "$@"
        ;;
    development-mail-smtp|dev-mail-smtp)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            "$@"
        ;;
    development-mail-smtp-ca|dev-mail-smtp-ca)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.smtp-ca.yaml \
            "$@"
        ;;
    development-mattermost|dev-mattermost)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.dev.yaml \
            -f docker-compose.mattermost.yaml \
            "$@"
        ;;
    production|prod)
        exec docker compose -f docker-compose.yaml "$@"
        ;;
    production-mail|prod-mail)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mail.yaml \
            "$@"
        ;;
    production-smtp|prod-smtp)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.smtp.yaml \
            "$@"
        ;;
    production-smtp-ca|prod-smtp-ca)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.smtp-ca.yaml \
            "$@"
        ;;
    production-mail-smtp|prod-mail-smtp)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            "$@"
        ;;
    production-mail-smtp-ca|prod-mail-smtp-ca)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.smtp-ca.yaml \
            "$@"
        ;;
    production-mattermost|prod-mattermost)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mattermost.yaml \
            "$@"
        ;;
    production-ubuntu|prod-ubuntu)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.ubuntu.yaml \
            "$@"
        ;;
    production-ubuntu-mattermost|prod-ubuntu-mattermost)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.ubuntu.yaml \
            -f docker-compose.mattermost.yaml \
            "$@"
        ;;
    production-ubuntu-proton|prod-ubuntu-proton)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.proton-bridge.yaml \
            -f docker-compose.ubuntu.yaml \
            "$@"
        ;;
    production-ubuntu-proton-mattermost|prod-ubuntu-proton-mattermost)
        exec docker compose \
            -f docker-compose.yaml \
            -f docker-compose.mail.yaml \
            -f docker-compose.smtp.yaml \
            -f docker-compose.proton-bridge.yaml \
            -f docker-compose.ubuntu.yaml \
            -f docker-compose.mattermost.yaml \
            "$@"
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
