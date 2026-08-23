<?php

declare(strict_types=1);

function expectServiceDatabaseIsolation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Service database isolation test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$compose = file_get_contents($root . '/docker-compose.yaml');
$mail = file_get_contents($root . '/docker-compose.mail.yaml');
$smtp = file_get_contents($root . '/docker-compose.smtp.yaml');
$grants = file_get_contents($root . '/scripts/configure_database_privileges.sh');
$smtpWebSection = explode("\n  mail-dispatch:", $smtp, 2)[0];
$baseWebSection = explode("\nservices:", $compose, 2)[0];

expectServiceDatabaseIsolation(
    str_contains($compose, 'MYSQL_USER: dnruser')
        && str_contains($compose, 'MYSQL_USER: dnrgeocoder')
        && str_contains($mail, 'MYSQL_USER: dnrmailingest')
        && str_contains($smtp, 'MYSQL_USER: dnrmaildispatch'),
    'the web, geocoder, inbound-mail, and outbound-mail services should use distinct identities.'
);
expectServiceDatabaseIsolation(
    str_contains($grants, '.engagement_map_geocodes')
        && str_contains($grants, "TO '\${geocoder_user}'@'%'")
        && str_contains($grants, '.inbound_email_messages')
        && str_contains($grants, "TO '\${mail_ingest_user}'@'%'")
        && str_contains($grants, '.email_outbox')
        && str_contains($grants, "TO '\${mail_dispatch_user}'@'%'")
        && !str_contains($grants, "GRANT ALL PRIVILEGES"),
    'worker grants should be table-scoped and should never grant all privileges.'
);
expectServiceDatabaseIsolation(
    str_contains($smtp, 'DNR_SMTP_PASSWORD_FILE: /run/secrets/dnr_smtp_password')
        && substr_count($smtp, '- dnr_smtp_password') === 1
        && !str_contains($smtpWebSection, 'dnr_smtp_password')
        && !str_contains($baseWebSection, 'DNR_SMTP_'),
    'only the outbound-mail worker should receive the SMTP password secret.'
);
expectServiceDatabaseIsolation(
    str_contains($grants, 'GRANT SELECT (token_id, status)')
        && !str_contains($grants, 'GRANT SELECT, INSERT, UPDATE, DELETE ON `${MYSQL_DATABASE}`.email_outbox TO'),
    'the web account should not be able to read encrypted outbox payloads.'
);

echo "Service database isolation tests passed.\n";
