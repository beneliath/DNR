#!/bin/sh
# Run only when the release owner authorizes merging this PR. Protection remains authoritative.
set -eu
pr=${1:?Usage: scripts/merge_pr.sh PR_NUMBER}
case "$pr" in ''|*[!0-9]*) exit 64 ;; esac
head=$(gh pr view "$pr" --json headRefOid --jq .headRefOid)
base=$(gh pr view "$pr" --json baseRefName --jq .baseRefName)
[ "$base" = main ] || { echo 'Release PR must target main.' >&2; exit 1; }
gh pr merge "$pr" --squash --auto --match-head-commit "$head"
