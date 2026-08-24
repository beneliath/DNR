#!/bin/sh
set -eu

base_url=${DNR_TEST_BASE_URL:-http://127.0.0.1:8080}
temporary_directory=$(mktemp -d)
trap 'rm -rf "$temporary_directory"' EXIT

curl -fsS "$base_url/health.php" > "$temporary_directory/health.json"
grep -q '"status":"ok"' "$temporary_directory/health.json"

curl -fsS "$base_url/ready.php" > "$temporary_directory/ready.json"
grep -q '"status":"ready"' "$temporary_directory/ready.json"

curl -fsS -D "$temporary_directory/login.headers" -o "$temporary_directory/login.html" "$base_url/login.php"
grep -qi '^content-security-policy:' "$temporary_directory/login.headers"
grep -qi '^x-request-id:' "$temporary_directory/login.headers"
grep -q 'class="auth-brand-logo"' "$temporary_directory/login.html"

curl -fsS -H 'Accept-Encoding: gzip' -D "$temporary_directory/static.headers" \
    -o "$temporary_directory/static.css" "$base_url/assets/css/style.min.css?v=smoke"
grep -qi '^cache-control:.*max-age=31536000.*immutable' "$temporary_directory/static.headers"
grep -qi '^content-encoding: gzip' "$temporary_directory/static.headers"

status=$(curl -sS -o /dev/null -w '%{http_code}' "$base_url/engagements.php")
[ "$status" = '302' ]

status=$(curl -sS -o /dev/null -w '%{http_code}' "$base_url/init.sql")
[ "$status" = '403' ]

status=$(curl -sS -o /dev/null -w '%{http_code}' "$base_url/migrate_passwords.php")
[ "$status" = '404' ]

echo 'HTTP smoke tests passed.'
