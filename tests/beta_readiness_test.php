<?php

function expectBetaReadiness($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Beta readiness test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function ($path) use ($root) {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $contents;
};

$edit_user = $read('src/edit_user.php');
expectBetaReadiness(
    str_contains($edit_user, 'begin_transaction()')
        && str_contains($edit_user, 'FOR UPDATE')
        && str_contains($edit_user, "role = 'admin'")
        && str_contains($edit_user, 'auth_version = auth_version + 1'),
    'role changes must lock the account set, preserve an administrator, and invalidate sessions.'
);

$new_engagement = $read('src/index.php');
$edit_engagement = $read('src/edit_engagement.php');
$presentation_helpers = $read('src/presentation_helpers.php');
expectBetaReadiness(
    str_contains($new_engagement, 'event_address_line_1')
        && str_contains($new_engagement, "header('Location: engagements.php')")
        && str_contains($new_engagement, 'requireActiveOrganization')
        && str_contains($edit_engagement, 'engagement_version')
        && str_contains($edit_engagement, 'nullableAmountsEqual($travel_amount')
        && str_contains($edit_engagement, 'nullableAmountsEqual($housing_amount')
        && str_contains($presentation_helpers, 'Every active presentation must be included')
        && str_contains($presentation_helpers, 'requirePresentationDateWithinEngagement'),
    'engagement writes must use PRG, active organizations, concurrency checks, and exact presentation synchronization.'
);

$add_contact = $read('src/add_contact.php');
expectBetaReadiness(
    str_contains($add_contact, "header('Location: view_contact.php?id=")
        && str_contains($add_contact, 'requireActiveOrganization'),
    'contact creation must use PRG and reject archived organizations.'
);

$add_organization = $read('src/add_organization.php');
$edit_organization = $read('src/edit_organization.php');
$view_organization = $read('src/view_organization.php');
expectBetaReadiness(
    str_contains($add_organization, '$mailing_address_line_1 = $physical_address_line_1;')
        && str_contains($edit_organization, '$mailing_address_line_1 = $physical_address_line_1;')
        && str_contains($add_organization, 'normalizedHttpUrl($website_url)')
        && str_contains($edit_organization, 'normalizedHttpUrl($website_url)')
        && str_contains($view_organization, 'normalizedHttpUrl'),
    'same-address data and website schemes must be enforced in every organization write/read path.'
);

$calendar = $read('src/calendar.php');
$calendar_helpers = $read('src/calendar_helpers.php');
expectBetaReadiness(
    str_contains($calendar, 'calendarRequestIsAuthorized')
        && str_contains($calendar, 'Cache-Control: private')
        && str_contains($calendar_helpers, 'DNR_CALENDAR_TOKEN'),
    'the calendar feed must require a private token and avoid public caching.'
);

$compose = $read('docker-compose.yaml');
$apache = $read('docker/apache-security.conf');
expectBetaReadiness(
    str_contains($compose, 'DNR_BIND_ADDRESS:-127.0.0.1')
        && str_contains($compose, 'MYSQL_ROOT_PASSWORD:?Set MYSQL_ROOT_PASSWORD in .env')
        && str_contains($compose, 'MYSQL_PASSWORD:?Set MYSQL_PASSWORD in .env')
        && !str_contains($compose, 'rootpassword')
        && !str_contains($compose, 'dnrpassword')
        && str_contains($apache, '\\.(?:sql|env|ini|log|bak|dist)')
        && str_contains($apache, 'Content-Security-Policy')
        && str_contains($apache, 'Strict-Transport-Security'),
    'deployment defaults must be local-only, secret-driven, and deny sensitive files with security headers.'
);

$migration = $read('migrations/20260817_beta_readiness_hardening.sql');
$runner = $read('scripts/migrate.sh');
$rotation = $read('scripts/secure_existing_deployment.sh');
expectBetaReadiness(
    str_contains($migration, 'authentication_rate_limits')
        && str_contains($migration, 'chk_engagement_date_range')
        && str_contains($migration, 'chk_presentation_time_format')
        && str_contains($runner, 'schema_migrations')
        && str_contains($rotation, 'openssl rand -hex 32')
        && str_contains($rotation, "ALTER USER 'dnruser'@'%'")
        && str_contains($rotation, 'refusing to overwrite deployment credentials'),
    'tracked migrations must install authentication bounds and data-integrity constraints.'
);

foreach ([
    'config/config.php',
    'src/config/config.php',
    'src/schema.sql',
    'src/add_is_deleted_to_organizations.sql',
    'src/add_unique_constraint.sql',
] as $public_or_stale_file) {
    expectBetaReadiness(
        !file_exists($root . '/' . $public_or_stale_file),
        "{$public_or_stale_file} should not remain as a stale or web-accessible configuration/schema copy."
    );
}

echo "Beta readiness tests passed.\n";
