#!/bin/sh
set -eu

base_url=${DNR_TEST_BASE_URL:-http://127.0.0.1:8080}
fixture_suffix=${DNR_HTTP_FIXTURE_SUFFIX:-$(openssl rand -hex 6)}
fixture_password='HttpTestPassword!123'
login_recovery_code='ABCD-EFGH-JKLM'
elevation_recovery_code='NPQR-STUV-WXYZ'
deactivation_recovery_code='BCDF-GHJK-LMNP'
deletion_recovery_code='QRST-VWXY-ZABC'
temporary_directory=$(mktemp -d)
fixtures_created=0

compose() {
    if [ -n "${DNR_TEST_COMPOSE_PROJECT:-}" ]; then
        docker compose -p "$DNR_TEST_COMPOSE_PROJECT" \
            -f docker-compose.yaml -f docker-compose.dev.yaml "$@"
        return
    fi
    docker compose -f docker-compose.yaml -f docker-compose.dev.yaml "$@"
}

fixture() {
    compose exec -T \
        -e DNR_INTEGRATION_TEST=1 \
        -e DNR_INTEGRATION_TARGET=disposable \
        -e DNR_TEST_SOURCE_DIR=/var/www/html \
        web php /opt/dnr/tests/http_fixture.php "$@"
}

cleanup() {
    if [ "$fixtures_created" = '1' ]; then
        fixture cleanup "$fixture_suffix" >/dev/null || true
    fi
    rm -rf "$temporary_directory"
}
trap cleanup EXIT INT TERM

csrf_from() {
    sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n 1
}

field_from() {
    file=$1
    field=$2
    sed -n "s/.*name=\"$field\" value=\"\([^\"]*\)\".*/\1/p" "$file" | head -n 1
}

expect_status() {
    actual=$1
    expected=$2
    description=$3
    if [ "$actual" != "$expected" ]; then
        echo "HTTP authenticated test failed: $description returned $actual, expected $expected." >&2
        exit 1
    fi
}

expect_location() {
    headers=$1
    expected=$2
    description=$3
    actual=$(grep -i '^location:' "$headers" | head -n 1 \
        | sed 's/^[^:]*:[[:space:]]*//' | tr -d '\r')
    if [ "$actual" != "$expected" ]; then
        echo "HTTP authenticated test failed: $description did not redirect to $expected." >&2
        sed -n '1,20p' "$headers" >&2
        exit 1
    fi
}

login_password() {
    role=$1
    expected_location=$2
    cookie_jar="$temporary_directory/$role.cookies"
    login_page="$temporary_directory/$role-login.html"
    login_headers="$temporary_directory/$role-login.headers"
    curl -fsS -c "$cookie_jar" -o "$login_page" "$base_url/login.php"
    csrf_token=$(csrf_from "$login_page")
    test -n "$csrf_token"
    status=$(curl -sS -b "$cookie_jar" -c "$cookie_jar" \
        -D "$login_headers" -o "$temporary_directory/$role-login-response.html" \
        -w '%{http_code}' \
        --data-urlencode "csrf_token=$csrf_token" \
        --data-urlencode "username=http-$role-$fixture_suffix" \
        --data-urlencode "password=$fixture_password" \
        "$base_url/login.php")
    expect_status "$status" '302' "$role password login"
    expect_location "$login_headers" "$expected_location" "$role password login"
}

elevate_admin() {
    recovery_code=$1
    description=$2
    curl -fsS -b "$admin_cookies" -o "$temporary_directory/admin-elevation.html" \
        "$base_url/admin_elevation.php?return=users.php"
    admin_csrf=$(csrf_from "$temporary_directory/admin-elevation.html")
    status=$(curl -sS -b "$admin_cookies" -c "$admin_cookies" \
        -D "$temporary_directory/admin-elevation.headers" -o /dev/null -w '%{http_code}' \
        --data-urlencode "csrf_token=$admin_csrf" \
        --data-urlencode "admin_password=$fixture_password" \
        --data-urlencode "admin_code=$recovery_code" \
        --data-urlencode 'return=users.php' \
        "$base_url/admin_elevation.php")
    expect_status "$status" '302' "$description"
    expect_location "$temporary_directory/admin-elevation.headers" 'users.php' "$description"
}

active_mail_transport=$(compose exec -T web php -r 'echo getenv("DNR_MAIL_TRANSPORT");' </dev/null)
if [ "$active_mail_transport" != 'log' ]; then
    echo 'HTTP authenticated tests require the non-delivering log mail transport.' >&2
    echo 'Refusing to generate test account links through an active SMTP relay.' >&2
    exit 1
fi

fixtures_created=1
fixture setup "$fixture_suffix" >/dev/null

# Reviewers may inspect records but cannot enter edit/create routes or archive data.
login_password reviewer dashboard.php
reviewer_cookies="$temporary_directory/reviewer.cookies"
status=$(curl -sS -b "$reviewer_cookies" -D "$temporary_directory/reviewer-edit.headers" \
    -o /dev/null -w '%{http_code}' "$base_url/edit_engagement.php?id=1")
expect_status "$status" '302' 'reviewer edit route'
expect_location "$temporary_directory/reviewer-edit.headers" 'index.php' 'reviewer edit route'
status=$(curl -sS -b "$reviewer_cookies" -o /dev/null -w '%{http_code}' "$base_url/users.php")
expect_status "$status" '403' 'reviewer administrator route'
curl -fsS -b "$reviewer_cookies" -o "$temporary_directory/reviewer-organizations.html" "$base_url/organizations.php"
reviewer_csrf=$(csrf_from "$temporary_directory/reviewer-organizations.html")
status=$(curl -sS -b "$reviewer_cookies" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$reviewer_csrf" \
    --data-urlencode 'organization_id=1' \
    --data-urlencode 'action=archive' \
    "$base_url/organizations.php")
expect_status "$status" '403' 'reviewer archive request'

# Resending verification should queue and audit successfully instead of
# reporting a failure after the transport has already accepted the message.
fixture mark-email-unverified "$fixture_suffix" reviewer >/dev/null
curl -fsS -b "$reviewer_cookies" -o "$temporary_directory/reviewer-profile.html" \
    "$base_url/profile.php"
reviewer_csrf=$(csrf_from "$temporary_directory/reviewer-profile.html")
status=$(curl -sS -b "$reviewer_cookies" \
    -D "$temporary_directory/reviewer-verification.headers" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$reviewer_csrf" \
    --data-urlencode 'action=resend_verification' \
    "$base_url/profile.php")
expect_status "$status" '302' 'reviewer verification resend'
expect_location "$temporary_directory/reviewer-verification.headers" \
    'profile.php?verification_test_only=1' 'reviewer verification resend'

# Editors can create, update, and archive records, while CSRF is enforced first.
login_password editor dashboard.php
editor_cookies="$temporary_directory/editor.cookies"

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-profile.html" \
    "$base_url/profile.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-profile.html")
grep -q 'name="task_digest_enabled"' "$temporary_directory/editor-profile.html"
grep -q 'name="task_digest_time"' "$temporary_directory/editor-profile.html"
grep -Fq 'name="task_digest_days[]"' "$temporary_directory/editor-profile.html"
status=$(curl -sS -b "$editor_cookies" -D "$temporary_directory/editor-profile.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode 'action=save' \
    --data-urlencode 'first_name=HTTP' \
    --data-urlencode 'last_name=Editor' \
    --data-urlencode "email=http-editor-$fixture_suffix@example.test" \
    --data-urlencode 'phone_country_code=+1' \
    --data-urlencode 'phone=' \
    --data-urlencode 'task_digest_enabled=1' \
    --data-urlencode 'task_digest_schedule_present=1' \
    --data-urlencode 'task_digest_time=16:45' \
    --data-urlencode 'task_digest_days[]=1' \
    --data-urlencode 'task_digest_days[]=4' \
    --data-urlencode 'task_digest_days[]=16' \
    "$base_url/profile.php")
expect_status "$status" '302' 'editor digest preference update'
expect_location "$temporary_directory/editor-profile.headers" 'profile.php?updated=1' 'editor digest preference update'
test "$(fixture digest-enabled "$fixture_suffix" editor)" = '1'
test "$(fixture digest-schedule "$fixture_suffix" editor)" = '16:45:00|21'

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-task-reminders.html" \
    "$base_url/tasks.php"
grep -q 'class="task-reminder-badges"' "$temporary_directory/editor-task-reminders.html"

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-add.html" "$base_url/add_organization.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-add.html")
status=$(curl -sS -b "$editor_cookies" -o /dev/null -w '%{http_code}' \
    --data-urlencode 'save_org=1' \
    --data-urlencode "organization_name=HTTP Test Organization $fixture_suffix" \
    "$base_url/add_organization.php")
expect_status "$status" '400' 'editor request without CSRF token'
status=$(curl -sS -b "$editor_cookies" -D "$temporary_directory/editor-create.headers" \
    -o "$temporary_directory/editor-create.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode 'save_org=1' \
    --data-urlencode "organization_name=HTTP Test Organization $fixture_suffix" \
    --data-urlencode 'same_address=yes' \
    --data-urlencode 'physical_address_line_1=100 Test Street' \
    --data-urlencode 'physical_city=Testville' \
    --data-urlencode 'physical_state=TX' \
    --data-urlencode 'physical_zipcode=75001' \
    --data-urlencode 'physical_country=USA' \
    "$base_url/add_organization.php")
expect_status "$status" '302' 'editor organization creation'
expect_location "$temporary_directory/editor-create.headers" 'add_organization.php' 'editor organization creation'
test "$(fixture organization-state "$fixture_suffix")" = '0'
organization_id=$(fixture organization-id "$fixture_suffix")

# Exercise real contact and engagement creation, plus optimistic concurrency.
curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-add-contact.html" "$base_url/add_contact.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-add-contact.html")
status=$(curl -sS -b "$editor_cookies" -D "$temporary_directory/editor-contact-create.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode 'save_contact=1' \
    --data-urlencode "organization_id=$organization_id" \
    --data-urlencode "contact_first_name=HTTP-$fixture_suffix" \
    --data-urlencode 'contact_last_name=Contact' \
    --data-urlencode 'contact_role=admin' \
    --data-urlencode "contact_email=http-$fixture_suffix@example.test" \
    --data-urlencode "contact_email_confirm=http-$fixture_suffix@example.test" \
    "$base_url/add_contact.php")
expect_status "$status" '302' 'editor contact creation'
contact_id=$(fixture contact-id "$fixture_suffix")
test "$contact_id" -gt 0
expect_location "$temporary_directory/editor-contact-create.headers" "view_contact.php?id=$contact_id" 'editor contact creation'

curl -fsS -b "$editor_cookies" \
    -o "$temporary_directory/editor-organization-contacts.json" \
    "$base_url/organization_contacts.php?organization_id=$organization_id"
grep -q "\"id\":$contact_id" "$temporary_directory/editor-organization-contacts.json"
grep -q '"primary_host":"Primary host"' "$temporary_directory/editor-organization-contacts.json"

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-add-engagement.html" "$base_url/index.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-add-engagement.html")
status=$(curl -sS -b "$editor_cookies" -D "$temporary_directory/editor-engagement-create.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode 'save_engagement=1' \
    --data-urlencode "organization_id=$organization_id" \
    --data-urlencode "event_title=HTTP Test Engagement $fixture_suffix" \
    --data-urlencode 'event_start_date=2026-09-10' \
    --data-urlencode 'event_end_date=2026-09-12' \
    --data-urlencode 'event_type=conference' \
    --data-urlencode 'confirmation_status=work_in_progress' \
    --data-urlencode 'lifecycle_status=active' \
    --data-urlencode 'travel_covered=unknown' \
    --data-urlencode 'compensation_type=Unknown' \
    --data-urlencode 'housing_type=Unknown' \
    --data-urlencode "engagement_contacts[$contact_id][]=primary_host" \
    --data-urlencode "engagement_contacts[$contact_id][]=travel" \
    "$base_url/index.php")
expect_status "$status" '302' 'editor engagement creation'
expect_location "$temporary_directory/editor-engagement-create.headers" 'engagements.php' 'editor engagement creation'
engagement_id=$(fixture engagement-id "$fixture_suffix")
test "$engagement_id" -gt 0

curl -fsS -b "$editor_cookies" \
    -o "$temporary_directory/editor-reschedule-options.json" \
    "$base_url/engagement_reschedule_options.php?organization_id=$organization_id&exclude_id=$engagement_id"
grep -q '"engagements":' "$temporary_directory/editor-reschedule-options.json"

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-view-engagement.html" \
    "$base_url/view_engagement.php?id=$engagement_id"
grep -q 'Primary host' "$temporary_directory/editor-view-engagement.html"
grep -q '>Travel<' "$temporary_directory/editor-view-engagement.html"

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-edit-engagement.html" \
    "$base_url/edit_engagement.php?id=$engagement_id"
editor_csrf=$(csrf_from "$temporary_directory/editor-edit-engagement.html")
engagement_version=$(field_from "$temporary_directory/editor-edit-engagement.html" engagement_version)
test -n "$engagement_version"
status=$(curl -sS -b "$editor_cookies" -o "$temporary_directory/editor-conflict.html" \
    -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode 'save_engagement=1' \
    --data-urlencode 'engagement_version=stale-version' \
    --data-urlencode "organization_id=$organization_id" \
    --data-urlencode "event_title=HTTP Test Engagement $fixture_suffix Updated" \
    --data-urlencode 'event_start_date=2026-09-10' \
    --data-urlencode 'event_end_date=2026-09-12' \
    --data-urlencode 'event_type=conference' \
    --data-urlencode 'confirmation_status=work_in_progress' \
    --data-urlencode 'lifecycle_status=active' \
    --data-urlencode 'travel_covered=unknown' \
    --data-urlencode 'compensation_type=Unknown' \
    --data-urlencode 'housing_type=Unknown' \
    --data-urlencode "engagement_contacts[$contact_id][]=primary_host" \
    --data-urlencode "engagement_contacts[$contact_id][]=travel" \
    "$base_url/edit_engagement.php?id=$engagement_id")
expect_status "$status" '200' 'stale engagement update'
grep -q 'changed after you opened it' "$temporary_directory/editor-conflict.html"
test "$(fixture engagement-title "$fixture_suffix")" = "HTTP Test Engagement $fixture_suffix"

status=$(curl -sS -b "$editor_cookies" -D "$temporary_directory/editor-engagement-update.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode 'save_engagement=1' \
    --data-urlencode "engagement_version=$engagement_version" \
    --data-urlencode "organization_id=$organization_id" \
    --data-urlencode "event_title=HTTP Test Engagement $fixture_suffix Updated" \
    --data-urlencode 'event_start_date=2026-09-10' \
    --data-urlencode 'event_end_date=2026-09-12' \
    --data-urlencode 'event_type=conference' \
    --data-urlencode 'confirmation_status=work_in_progress' \
    --data-urlencode 'lifecycle_status=active' \
    --data-urlencode 'travel_covered=unknown' \
    --data-urlencode 'compensation_type=Unknown' \
    --data-urlencode 'housing_type=Unknown' \
    --data-urlencode "engagement_contacts[$contact_id][]=primary_host" \
    --data-urlencode "engagement_contacts[$contact_id][]=travel" \
    "$base_url/edit_engagement.php?id=$engagement_id")
expect_status "$status" '302' 'editor engagement update'
expect_location "$temporary_directory/editor-engagement-update.headers" 'engagements.php' 'editor engagement update'
test "$(fixture engagement-title "$fixture_suffix")" = "HTTP Test Engagement $fixture_suffix Updated"

# Archive dependent records before exercising the organization archive guard.
curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-contacts.html" "$base_url/contacts.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-contacts.html")
status=$(curl -sS -b "$editor_cookies" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode "contact_id=$contact_id" \
    --data-urlencode 'action=archive' \
    "$base_url/contacts.php")
expect_status "$status" '302' 'editor contact archive'
test "$(fixture contact-state "$fixture_suffix")" = '1'

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-engagements.html" "$base_url/engagements.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-engagements.html")
status=$(curl -sS -b "$editor_cookies" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode "engagement_id=$engagement_id" \
    --data-urlencode 'action=archive' \
    "$base_url/engagements.php")
expect_status "$status" '302' 'editor engagement archive'
test "$(fixture engagement-state "$fixture_suffix")" = '1'

curl -fsS -b "$editor_cookies" -o "$temporary_directory/editor-organizations.html" "$base_url/organizations.php"
editor_csrf=$(csrf_from "$temporary_directory/editor-organizations.html")
status=$(curl -sS -b "$editor_cookies" -D "$temporary_directory/editor-archive.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$editor_csrf" \
    --data-urlencode "organization_id=$organization_id" \
    --data-urlencode 'action=archive' \
    "$base_url/organizations.php")
expect_status "$status" '302' 'editor organization archive'
test "$(fixture organization-state "$fixture_suffix")" = '1'

# Administrators must complete 2FA and fresh elevation before destructive actions.
login_password admin verify_2fa.php
admin_cookies="$temporary_directory/admin.cookies"
curl -fsS -b "$admin_cookies" -o "$temporary_directory/admin-verify.html" "$base_url/verify_2fa.php"
admin_csrf=$(csrf_from "$temporary_directory/admin-verify.html")
status=$(curl -sS -b "$admin_cookies" -c "$admin_cookies" \
    -D "$temporary_directory/admin-verify.headers" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode "authentication_code=$login_recovery_code" \
    "$base_url/verify_2fa.php")
expect_status "$status" '302' 'administrator second factor'
expect_location "$temporary_directory/admin-verify.headers" 'dashboard.php' 'administrator second factor'
status=$(curl -sS -b "$admin_cookies" -o "$temporary_directory/admin-users.html" \
    -w '%{http_code}' "$base_url/users.php")
expect_status "$status" '200' 'administrator user list'

elevate_admin "$elevation_recovery_code" 'administrator elevation for active-account deletion check'

curl -fsS -b "$admin_cookies" -o "$temporary_directory/admin-users-elevated.html" "$base_url/users.php"
admin_csrf=$(csrf_from "$temporary_directory/admin-users-elevated.html")
target_user_id=$(fixture user-id "$fixture_suffix" target)
status=$(curl -sS -b "$admin_cookies" -D "$temporary_directory/admin-delete.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode "id=$target_user_id" \
    --data-urlencode 'delete_confirmation=DELETE USER' \
    "$base_url/delete_user.php")
expect_status "$status" '302' 'active-account deletion rejection'
expect_location "$temporary_directory/admin-delete.headers" 'users.php' 'active-account deletion rejection'
test "$(fixture user-exists "$fixture_suffix" target)" = '1'

elevate_admin "$deactivation_recovery_code" 'administrator elevation for account deactivation'
curl -fsS -b "$admin_cookies" -o "$temporary_directory/admin-users-deactivation.html" "$base_url/users.php"
admin_csrf=$(csrf_from "$temporary_directory/admin-users-deactivation.html")
status=$(curl -sS -b "$admin_cookies" -D "$temporary_directory/admin-deactivation.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode "id=$target_user_id" \
    --data-urlencode 'action=deactivate' \
    "$base_url/user_lifecycle.php")
expect_status "$status" '302' 'administrator user deactivation'
expect_location "$temporary_directory/admin-deactivation.headers" 'users.php' 'administrator user deactivation'
test "$(fixture user-status "$fixture_suffix" target)" = 'inactive'

elevate_admin "$deletion_recovery_code" 'administrator elevation for inactive-account deletion'
curl -fsS -b "$admin_cookies" -o "$temporary_directory/admin-users-delete.html" "$base_url/users.php"
admin_csrf=$(csrf_from "$temporary_directory/admin-users-delete.html")
status=$(curl -sS -b "$admin_cookies" -D "$temporary_directory/admin-delete.headers" \
    -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$admin_csrf" \
    --data-urlencode "id=$target_user_id" \
    --data-urlencode 'delete_confirmation=DELETE USER' \
    "$base_url/delete_user.php")
expect_status "$status" '302' 'inactive administrator user deletion'
expect_location "$temporary_directory/admin-delete.headers" 'users.php' 'inactive administrator user deletion'
test "$(fixture user-exists "$fixture_suffix" target)" = '0'

echo 'Authenticated HTTP behavior tests passed.'
