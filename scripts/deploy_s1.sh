#!/bin/sh
set -eu

project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

usage() {
    echo "Usage: scripts/deploy_s1.sh EXPECTED_MAIN_COMMIT" >&2
}

expected_commit=${1:-}
if ! printf '%s\n' "$expected_commit" | grep -Eq '^[0-9A-Fa-f]{40}$'; then
    usage
    echo "EXPECTED_MAIN_COMMIT must be a complete 40-character Git commit hash." >&2
    exit 2
fi
expected_commit=$(printf '%s' "$expected_commit" | tr 'A-F' 'a-f')

for command_name in git gh ssh curl; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "$command_name is required." >&2
        exit 1
    }
done

if [ "$(git -C "$project_directory" branch --show-current)" != 'main' ]; then
    echo "Refusing to deploy outside the local main branch." >&2
    exit 1
fi
if [ -n "$(git -C "$project_directory" status --porcelain --untracked-files=normal)" ]; then
    echo "Refusing to deploy from a dirty local worktree." >&2
    exit 1
fi
local_commit=$(git -C "$project_directory" rev-parse --verify HEAD)
if [ "$local_commit" != "$expected_commit" ]; then
    echo "Local main is $local_commit, not the requested $expected_commit." >&2
    exit 1
fi

expected_version=$(tr -d '\r\n' < "$project_directory/VERSION")
if ! printf '%s\n' "$expected_version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "VERSION must use the project [super].[major].[minor] x.y.z format." >&2
    exit 1
fi

origin_main_sha=$(git -C "$project_directory" ls-remote --exit-code origin refs/heads/main \
    | awk 'NR == 1 { print tolower($1) }')
if [ -z "$origin_main_sha" ] || [ "$origin_main_sha" != "$expected_commit" ]; then
    echo "origin/main is not published at $expected_commit." >&2
    exit 1
fi

github_repository=${DNR_S1_GITHUB_REPOSITORY:-beneliath/DNR}
ci_state=$(gh run list \
    --repo "$github_repository" \
    --commit "$expected_commit" \
    --workflow CI \
    --limit 1 \
    --json status,conclusion \
    --jq 'if length == 0 then "missing" else .[0].status + ":" + (.[0].conclusion // "") end')
if [ "$ci_state" != 'completed:success' ]; then
    echo "GitHub CI for $expected_commit is $ci_state; refusing deployment." >&2
    exit 1
fi

s1_user=${DNR_S1_USER:-dgilmore}
s1_host=${DNR_S1_HOST:-192.168.1.150}
s1_project_directory=${DNR_S1_PROJECT_DIR:-/home/dgilmore/moed}
s1_compose_mode=${DNR_S1_COMPOSE_MODE:-production-ubuntu-proton-mattermost}
public_base_url=${DNR_S1_PUBLIC_BASE_URL:-https://moed.beneliath.com}

printf '%s\n' "$s1_user" | grep -Eq '^[A-Za-z0-9._-]+$' || {
    echo "DNR_S1_USER contains unsupported characters." >&2
    exit 1
}
printf '%s\n' "$s1_host" | grep -Eq '^[A-Za-z0-9.:-]+$' || {
    echo "DNR_S1_HOST contains unsupported characters." >&2
    exit 1
}
printf '%s\n' "$s1_project_directory" | grep -Eq '^/[A-Za-z0-9._/-]+$' || {
    echo "DNR_S1_PROJECT_DIR must be an absolute path without shell metacharacters." >&2
    exit 1
}
if [ "$s1_compose_mode" != 'production-ubuntu-proton-mattermost' ]; then
    echo "DNR_S1_COMPOSE_MODE must preserve s1's production-ubuntu-proton-mattermost topology." >&2
    exit 1
fi
case "$public_base_url" in
    https://*) ;;
    *)
        echo "DNR_S1_PUBLIC_BASE_URL must use HTTPS." >&2
        exit 1
        ;;
esac
public_base_url=${public_base_url%/}

echo "Deploying $expected_commit (version $expected_version) to $s1_user@$s1_host..."
ssh -o BatchMode=yes -o ConnectTimeout=10 "$s1_user@$s1_host" \
    sh -s -- "$s1_project_directory" "$expected_commit" "$expected_version" "$s1_compose_mode" <<'REMOTE'
set -eu

project_directory=$1
expected_commit=$2
expected_version=$3
compose_mode=$4

if [ ! -d "$project_directory/.git" ]; then
    echo "The s1 checkout was not found at $project_directory." >&2
    exit 1
fi
if [ "$(git -C "$project_directory" branch --show-current)" != 'main' ]; then
    echo "Refusing to deploy because the s1 checkout is not on main." >&2
    exit 1
fi
if [ -n "$(git -C "$project_directory" status --porcelain --untracked-files=normal)" ]; then
    echo "Refusing to deploy because the s1 checkout is dirty." >&2
    exit 1
fi

git -C "$project_directory" fetch origin main
git -C "$project_directory" merge --ff-only origin/main

deployed_commit=$(git -C "$project_directory" rev-parse --verify HEAD)
if [ "$deployed_commit" != "$expected_commit" ]; then
    echo "s1 resolved $deployed_commit instead of $expected_commit." >&2
    exit 1
fi
if [ "$(tr -d '\r\n' < "$project_directory/VERSION")" != "$expected_version" ]; then
    echo "The s1 VERSION file does not match $expected_version." >&2
    exit 1
fi
if [ -n "$(git -C "$project_directory" status --porcelain --untracked-files=normal)" ]; then
    echo "The s1 checkout became dirty before the build." >&2
    exit 1
fi

cd "$project_directory"
./scripts/compose_with_provenance.sh "$compose_mode" up -d --build

for service in db web ingress geocoder mail-ingest mail-dispatch proton-bridge; do
    container=$(./scripts/compose_with_provenance.sh "$compose_mode" ps -q "$service" | tail -n 1)
    if [ -z "$container" ] || [ "$(docker inspect --format '{{.State.Running}}' "$container")" != 'true' ]; then
        echo "The s1 $service service is not running." >&2
        exit 1
    fi
done

for service in db web ingress proton-bridge; do
    container=$(./scripts/compose_with_provenance.sh "$compose_mode" ps -q "$service" | tail -n 1)
    attempt=1
    health_status=$(docker inspect --format '{{.State.Health.Status}}' "$container")
    while [ "$health_status" != 'healthy' ] && [ "$attempt" -le 30 ]; do
        if [ "$(docker inspect --format '{{.State.Running}}' "$container")" != 'true' ]; then
            echo "The s1 $service service stopped while waiting for health." >&2
            exit 1
        fi
        sleep 2
        attempt=$((attempt + 1))
        health_status=$(docker inspect --format '{{.State.Health.Status}}' "$container")
    done
    if [ "$health_status" != 'healthy' ]; then
        echo "The s1 $service service did not become healthy within 60 seconds (status: $health_status)." >&2
        exit 1
    fi
done

web_container=$(./scripts/compose_with_provenance.sh "$compose_mode" ps -q web | tail -n 1)
if [ "$(docker exec "$web_container" sh -c 'cat /opt/dnr/VERSION')" != "$expected_version" ]; then
    echo "The running web image does not contain version $expected_version." >&2
    exit 1
fi
if [ "$(docker exec "$web_container" sh -c 'printf "%s" "$DNR_BUILD_COMMIT"')" != "$expected_commit" ]; then
    echo "The running web image does not contain the expected build provenance." >&2
    exit 1
fi

migrator_container=$(./scripts/compose_with_provenance.sh "$compose_mode" ps -aq migrator | tail -n 1)
if [ -z "$migrator_container" ] || [ "$(docker inspect --format '{{.State.ExitCode}}' "$migrator_container")" != '0' ]; then
    echo "The s1 migration gate did not exit successfully." >&2
    exit 1
fi

./scripts/compose_with_provenance.sh "$compose_mode" ps
REMOTE

ready_response=''
attempt=1
while [ "$attempt" -le 10 ]; do
    if ready_response=$(curl --silent --show-error --fail --max-time 20 "$public_base_url/ready.php"); then
        break
    fi
    attempt=$((attempt + 1))
    sleep 2
done
printf '%s\n' "$ready_response" | grep -F '"status":"ready"' >/dev/null || {
    echo "The public readiness endpoint did not report ready." >&2
    exit 1
}
printf '%s\n' "$ready_response" | grep -F "\"version\":\"$expected_version\"" >/dev/null || {
    echo "The public readiness endpoint did not report version $expected_version." >&2
    exit 1
}

health_response=$(curl --silent --show-error --fail --max-time 20 "$public_base_url/health.php")
printf '%s\n' "$health_response" | grep -F '"status":"ok"' >/dev/null || {
    echo "The public health endpoint did not report ok." >&2
    exit 1
}

echo "s1 deployment verified: $expected_commit, version $expected_version, CI green, services healthy, public endpoints ready."
