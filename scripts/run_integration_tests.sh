#!/bin/sh
set -eu

mode=${1:-}
integration_test_files=$(
    find tests -maxdepth 1 -type f \
        \( -name '*_integration_test.php' -o -name 'integration_*_test.php' \) \
        -print \
        | LC_ALL=C sort
)

if [ -z "$integration_test_files" ]; then
    echo 'No integration test suites were found.' >&2
    exit 1
fi

if [ "$mode" = 'list' ]; then
    printf '%s\n' "$integration_test_files"
    exit 0
fi

if [ "$mode" != 'disposable' ]; then
    echo 'Refusing to run integration tests without the explicit disposable target.' >&2
    echo 'Usage: sh scripts/run_integration_tests.sh disposable' >&2
    exit 64
fi

compose() {
    docker compose -f docker-compose.yaml -f docker-compose.dev.yaml "$@"
}

isolated_project=
isolated_backend_subnet=
isolated_ingress_proxy_ip=
compose_isolated() {
    DNR_BACKEND_SUBNET="$isolated_backend_subnet" \
    DNR_INGRESS_PROXY_IP="$isolated_ingress_proxy_ip" \
        docker compose -p "$isolated_project" \
            -f docker-compose.yaml -f docker-compose.dev.yaml "$@"
}

cleanup_isolated_backup() {
    if [ -n "$isolated_project" ]; then
        compose_isolated down --volumes --remove-orphans --rmi local >/dev/null 2>&1 || true
        isolated_project=
    fi
}

printf '%s\n' "$integration_test_files" | while IFS= read -r test_file; do
    test_name=$(basename "$test_file")
    echo "Running ${test_name}"
    if [ "$test_name" = 'database_backup_integration_test.php' ]; then
        isolated_octet=$((($$ % 200) + 20))
        isolated_project="dnr-backup-test-$(date +%s)-$$"
        isolated_backend_subnet="10.253.${isolated_octet}.0/24"
        isolated_ingress_proxy_ip="10.253.${isolated_octet}.254"
        trap cleanup_isolated_backup EXIT HUP INT TERM

        compose_isolated up -d --wait db </dev/null
        compose_isolated run --rm --no-deps migrator </dev/null
        compose_isolated build maintenance </dev/null
        compose_isolated run --rm --no-deps --entrypoint php \
            -e DNR_INTEGRATION_TEST=1 \
            -e DNR_INTEGRATION_TARGET=disposable \
            -e DNR_DESTRUCTIVE_BACKUP_TEST=isolated-restore \
            -e DNR_TEST_SOURCE_DIR=/var/www/html \
            -v "${PWD}/src:/var/www/html:ro" \
            maintenance "/opt/dnr/${test_file}" </dev/null
        cleanup_isolated_backup
        trap - EXIT HUP INT TERM
        continue
    fi
    if [ "$test_name" = 'email_outbox_worker_integration_test.php' ] \
        || [ "$test_name" = 'engagement_email_integration_test.php' ] \
        || [ "$test_name" = 'operational_retention_integration_test.php' ] \
        || [ "$test_name" = 'task_notifications_integration_test.php' ]; then
        compose run --rm --no-deps --entrypoint php \
            -e DNR_INTEGRATION_TEST=1 \
            -e DNR_INTEGRATION_TARGET=disposable \
            -e DNR_TEST_SOURCE_DIR=/var/www/html \
            -v "${PWD}/src:/var/www/html:ro" \
            maintenance "/opt/dnr/${test_file}" </dev/null
        continue
    fi
    if [ "$test_name" = 'geocoder_worker_integration_test.php' ]; then
        compose run --rm --no-deps --entrypoint php \
            -e DNR_INTEGRATION_TEST=1 \
            -e DNR_INTEGRATION_TARGET=disposable \
            -e DNR_TEST_SOURCE_DIR=/var/www/html \
            geocoder "/opt/dnr/${test_file}" </dev/null
        continue
    fi

    compose exec -T \
        -e DNR_INTEGRATION_TEST=1 \
        -e DNR_INTEGRATION_TARGET=disposable \
        -e DNR_TEST_SOURCE_DIR=/var/www/html \
        web php "/opt/dnr/${test_file}" </dev/null
done
