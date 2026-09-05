#!/bin/sh
set -eu
project_directory=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
expected_commit=${1:-}
printf '%s\n' "$expected_commit" | grep -Eq '^[0-9a-f]{40}$' || {
    echo 'Usage: scripts/deploy_s1.sh EXPECTED_MAIN_COMMIT' >&2; exit 64;
}
for command_name in git gh ssh scp python3; do command -v "$command_name" >/dev/null; done
cd "$project_directory"
# Reuse the notice started before release preparation. Direct invocations start
# a new notice here and receive the same mandatory five-minute minimum.
notice_id=$(scripts/deployment_notice.sh start)
release_directory=''
cleanup() {
    exit_status=$?
    trap - EXIT
    # Cancel pending notices on preflight failure; never clear active maintenance.
    if ! scripts/deployment_notice.sh cancel "$notice_id" >/dev/null; then
        echo 'Deployment notice remains active; check s1 deployment/recovery status.' >&2
    fi
    if [ -n "$release_directory" ]; then rm -rf "$release_directory"; fi
    exit "$exit_status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
[ "$(git branch --show-current)" = main ] && [ "$(git rev-parse HEAD)" = "$expected_commit" ] || {
    echo 'Deploy the requested final commit from local main.' >&2; exit 1;
}
[ -z "$(git status --porcelain --untracked-files=normal)" ] || { echo 'Refusing a dirty deployment checkout.' >&2; exit 1; }
scripts/prepare_release check --base-ref HEAD
run_id=$(gh run list --commit "$expected_commit" --branch main --event push --workflow CI --limit 1 \
    --json databaseId,status,conclusion --jq 'if .[0].status == "completed" and .[0].conclusion == "success" then .[0].databaseId else empty end')
[ -n "$run_id" ] || { echo 'Successful final-main CI, including image qualification, is required.' >&2; exit 1; }
umask 077
release_directory=$(mktemp -d)
gh run download "$run_id" --name "release-$expected_commit" --dir "$release_directory"
python3 scripts/release_manifest.py check "$release_directory/manifest.json" --commit "$expected_commit"
python3 - "$release_directory/manifest.json" "$run_id" <<'PY'
import json, sys
if str(json.load(open(sys.argv[1]))['ci_run_id']) != sys.argv[2]:
    raise SystemExit('Manifest is not from the qualifying CI run')
PY
python3 scripts/mirror_release.py "$release_directory/manifest.json" --output "$release_directory/mirrors.json"
s1_user=${DNR_S1_USER:-dgilmore}
s1_host=${DNR_S1_HOST:-192.168.1.150}
s1_project_directory=${DNR_S1_PROJECT_DIR:-/home/dgilmore/moed}
backup_password_file=${DNR_S1_BACKUP_PASSWORD_FILE:-$s1_project_directory/secrets/deployment_backup_password}
public_base_url=${DNR_S1_PUBLIC_BASE_URL:-https://moed.beneliath.com}
printf '%s\n' "$s1_user" | grep -Eq '^[A-Za-z0-9._-]+$'
printf '%s\n' "$s1_host" | grep -Eq '^[A-Za-z0-9.:-]+$'
printf '%s\n' "$s1_project_directory" "$backup_password_file" | while IFS= read -r value; do
    printf '%s\n' "$value" | grep -Eq '^/[A-Za-z0-9._/-]+$'
done
printf '%s\n' "$public_base_url" | grep -Eq '^https://[A-Za-z0-9.:/_-]+$'
remote="$s1_user@$s1_host"
incoming="$s1_project_directory/.git/dnr-deploy/incoming/$expected_commit"
ssh -o BatchMode=yes -o ConnectTimeout=10 "$remote" "umask 077; mkdir -p '$incoming'"
scp -q "$release_directory/manifest.json" "$release_directory/mirrors.json" scripts/deploy_release_host.py scripts/deployment_backup.py scripts/deployment_notice.py scripts/release_timestamp.py "$remote:$incoming/"
ssh -o BatchMode=yes -o ConnectTimeout=10 "$remote" python3 "$incoming/deploy_release_host.py" \
    "$s1_project_directory" "$expected_commit" "$incoming/manifest.json" "$backup_password_file" "${public_base_url%/}" "$notice_id"
