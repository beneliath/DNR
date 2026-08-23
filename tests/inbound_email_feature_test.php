<?php

declare(strict_types=1);

function expectInboundFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Inbound email feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/20260823_add_inbound_chron_mail.sql');
$quarantineMigration = file_get_contents($root . '/migrations/20260823_add_inbound_email_quarantine.sql');
$helper = file_get_contents($root . '/src/inbound_email_helpers.php');
$worker = file_get_contents($root . '/scripts/process_inbound_mail.php');
$review = file_get_contents($root . '/src/inbound_mail.php');
$header = file_get_contents($root . '/src/templates/header.php');
$compose = file_get_contents($root . '/docker-compose.mail.yaml');
$grants = file_get_contents($root . '/scripts/configure_database_privileges.sh');
$migrate = file_get_contents($root . '/scripts/migrate.sh');
$environment = file_get_contents($root . '/.env.example');
$readme = file_get_contents($root . '/README.md');

expectInboundFeature(
    str_contains($migration, 'CREATE TABLE inbound_email_messages')
        && str_contains($migration, 'deduplication_hash')
        && str_contains($migration, 'inbound_email_message_id')
        && str_contains($migration, 'REFERENCES inbound_email_messages(id) ON DELETE SET NULL')
        && str_contains($migration, 'uq_contact_chron_inbound_email')
        && str_contains($quarantineMigration, 'CREATE TABLE inbound_email_quarantine'),
    'the forward migrations should retain idempotent inbound sources, Chron links, and poison-message quarantine.'
);
expectInboundFeature(
    str_contains($helper, 'routeInboundEmailMessage')
        && str_contains($helper, "'automatic' => \$reasons === []")
        && str_contains($helper, "'Email Gateway'")
        && str_contains($helper, 'ON DUPLICATE KEY UPDATE id = id')
        && str_contains($helper, 'function purgeInboundEmailMessage')
        && str_contains($helper, 'DELETE FROM inbound_email_messages WHERE id = ?'),
    'routing should distinguish automatic matches, gateway attribution, and duplicate delivery.'
);
expectInboundFeature(
    str_contains($worker, 'unseenUids')
        && str_contains($worker, 'markSeen')
        && str_contains($worker, 'claimInboundEmailMessage')
        && str_contains($worker, 'quarantineInboundEmailMessage')
        && str_contains($worker, '$client->abort()')
        && str_contains($worker, 'UIDVALIDITY changed after reconnecting')
        && str_contains($compose, 'DNR_IMAP_PASSWORD_FILE: /run/secrets/dnr_imap_password')
        && str_contains($compose, 'cap_drop: [ALL]'),
    'the least-privilege worker should poll configurable IMAP and use a mounted password secret.'
);
expectInboundFeature(
    str_contains($review, 'requireValidCsrfToken')
        && str_contains($review, "['admin', 'editor']")
        && str_contains($review, 'Approve selected routes')
        && str_contains($review, 'Reject message')
        && str_contains($review, "\$action === 'purge'")
        && str_contains($review, 'canDeleteEntries($userRole)')
        && str_contains($review, 'requireRecentAdminElevation')
        && str_contains($review, 'Purge Mail Entry')
        && str_contains($review, 'Associated Contact and Organization Chron Log entries will be preserved')
        && str_contains($header, '<span>Inbound Mail</span>'),
    'editors and administrators should have a CSRF-protected review workflow, with elevated purge restricted to administrators.'
);
expectInboundFeature(
    str_contains($grants, '.inbound_email_messages')
        && str_contains($grants, "TO '\${mail_ingest_user}'@'%'")
        && str_contains($migrate, 'DNR_PRIVILEGE_SCRIPT'),
    'fresh and upgraded databases should apply the single least-privilege manifest for inbound mail.'
);
expectInboundFeature(
    str_contains($environment, 'DNR_INBOUND_ADDRESS=moed@beneliath.com')
        && str_contains($environment, 'DNR_IMAP_PASSWORD_FILE=./secrets/imap_password')
        && str_contains($readme, '### Inbound email to Chron')
        && str_contains($readme, 'production-mail')
        && str_contains($readme, 'Attachment contents are not stored')
        && str_contains($readme, 'does not delete or move the source message'),
    'the gateway, deployment modes, routing limits, and attachment policy should be documented.'
);

echo "Inbound email feature tests passed.\n";
