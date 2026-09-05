#!/bin/sh
# Exercise a supplied ingress image in isolation with disposable notice data.
set -eu
image=${1:?Usage: deployment_notice_ingress_test.sh INGRESS_IMAGE}
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
# Match DeploymentNotice.locked(): Apache must traverse this public directory.
chmod 0755 "$fixture"
docker run --rm --network none --read-only \
    --tmpfs /tmp --tmpfs /var/run/apache2 --tmpfs /var/lock/apache2 \
    --mount "type=bind,src=$fixture,dst=/run/dnr/deployment" \
    --mount "type=bind,src=$root/tests/deployment_notice_http_test.php,dst=/opt/notice-test.php,readonly" \
    -e DNR_DEPLOYMENT_NOTICE_HTTP_TEST=1 --entrypoint sh "$image" -c '
        apache2-foreground >/tmp/apache.log 2>&1 &
        apache_pid=$!
        trap '\''kill "$apache_pid" 2>/dev/null || true'\'' EXIT
        if ! php /opt/notice-test.php; then cat /tmp/apache.log; exit 1; fi
    '
