#!/bin/sh
set -eu

for test_file in tests/*_test.php; do
    php "$test_file"
done

php scripts/generate_daily_digest_preview.php --check
