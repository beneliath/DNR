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
$engagementMigration = file_get_contents(
    $root . '/migrations/20260823_add_engagement_aware_inbound_mail.sql'
);
$quarantineMigration = file_get_contents($root . '/migrations/20260823_add_inbound_email_quarantine.sql');
$helper = file_get_contents($root . '/src/inbound_email_helpers.php');
$worker = file_get_contents($root . '/scripts/process_inbound_mail.php');
$review = file_get_contents($root . '/src/inbound_mail.php');
$reviewStyles = file_get_contents($root . '/src/assets/css/pages/inbound_mail.css');
$engagementView = file_get_contents($root . '/src/view_engagement.php');
$header = file_get_contents($root . '/src/templates/header.php');
$compose = file_get_contents($root . '/docker-compose.mail.yaml');
$grants = file_get_contents($root . '/scripts/configure_database_privileges.sh');
$migrate = file_get_contents($root . '/scripts/migrate.sh');
$environment = file_get_contents($root . '/.env.example');
$readme = file_get_contents($root . '/README.md');
$workflow = file_get_contents($root . '/.github/workflows/ci.yml');

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
    is_string($engagementMigration)
        && str_contains($engagementMigration, 'inbound_email_message_id')
        && str_contains($engagementMigration, 'fk_engagement_chron_inbound_email')
        && str_contains($engagementMigration, 'ON DELETE SET NULL')
        && str_contains($engagementMigration, 'uq_engagement_chron_inbound_email'),
    'Engagement Chron routing should be idempotent and preserve entries when retained mail is purged.'
);
expectInboundFeature(
    str_contains($helper, 'routeInboundEmailMessage')
        && str_contains($helper, "'automatic' => \$authoritativeEngagement && \$recognizedSender")
        && str_contains($helper, 'inboundEmailMessageEngagementMarkers')
        && str_contains($helper, 'applicationInboundMarkerIsValid')
        && str_contains($helper, "'authoritative_engagement' => \$authoritativeEngagement")
        && str_contains($helper, 'parseInboundEmailEngagementMarkers')
        && str_contains($helper, "'engagements' => array_values(\$engagements)")
        && str_contains($helper, 'INSERT INTO engagement_chron_entries')
        && str_contains($helper, "\$routing['applied_engagements']")
        && str_contains($helper, "'Email Gateway'")
        && str_contains($helper, '$creatorId = null')
        && str_contains($helper, 'LEFT JOIN organizations organization')
        && str_contains($helper, 'organization.id IS NULL OR organization.is_deleted = 0')
        && str_contains($helper, 'ON DUPLICATE KEY UPDATE id = id')
        && str_contains($helper, 'function purgeInboundEmailMessage')
        && str_contains($helper, 'DELETE FROM inbound_email_messages WHERE id = ?'),
    'routing should require signed markers and recognized senders, preserve standalone Contacts, use gateway attribution, and deduplicate delivery.'
);
expectInboundFeature(
    str_contains($worker, 'unseenUids')
        && str_contains($worker, 'markSeen')
        && str_contains($worker, 'claimInboundEmailMessage')
        && str_contains($worker, 'quarantineInboundEmailMessage')
        && str_contains($worker, '$client->abort()')
        && str_contains($worker, 'UIDVALIDITY changed after reconnecting')
        && str_contains($compose, 'DNR_IMAP_PASSWORD_FILE: /run/secrets/dnr_imap_password')
        && str_contains($compose, 'DNR_INBOUND_ROUTING_KEY_FILE: /run/secrets/dnr_inbound_routing_key')
        && str_contains($workflow, 'openssl rand -base64 32 > secrets/dnr_inbound_routing_key')
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
        && str_contains($review, 'name="engagement_ids[]"')
        && str_contains($review, 'No engagement selected')
        && str_contains($review, 'Associated Contact, Organization, and Engagement Chron Log entries will be preserved')
        && str_contains($engagementView, 'Email Routing Marker')
        && str_contains($engagementView, 'applicationInboundMarker($engagement_id)')
        && str_contains($header, '<span>Inbound Mail</span>'),
    'editors and administrators should have a CSRF-protected review workflow, with elevated purge restricted to administrators.'
);
expectInboundFeature(
    str_contains($review, '<main class="container inbound-mail-page">')
        && preg_match('/\.inbound-mail-page,[^{]*\.inbound-mail-page > \.inbound-mail-layout > section\s*\{[^}]*background:\s*transparent\s*!important;/s', $reviewStyles) === 1,
    'the Inbound Mail page and its layout sections should not paint a legacy black surface over the app background.'
);
expectInboundFeature(
    preg_match('/\\.inbound-message-detail > \\.inbound-routing-summary,[^{]*\\.inbound-message-detail > \\.inbound-message-body\\s*\\{[^}]*background:\\s*transparent\\s*!important;/s', $reviewStyles) === 1,
    'nested Routing, review, and Plain-Text Content sections should inherit the surrounding Inbound Mail detail pane background.'
);
expectInboundFeature(
    str_contains($grants, '.inbound_email_messages')
        && str_contains($grants, '.engagement_chron_entries')
        && str_contains($grants, '.engagements')
        && str_contains($grants, "TO '\${mail_ingest_user}'@'%'")
        && str_contains($migrate, 'DNR_PRIVILEGE_SCRIPT'),
    'fresh and upgraded databases should apply the single least-privilege manifest for inbound mail.'
);
expectInboundFeature(
    str_contains($environment, 'DNR_INBOUND_ADDRESS=dnr@example.org')
        && str_contains($environment, 'DNR_IMAP_PASSWORD_FILE=./secrets/imap_password')
        && str_contains($readme, '### Inbound email to Chron')
        && str_contains($readme, '[MOED#123.<signed-token>]')
        && str_contains($readme, 'Unsigned legacy markers intentionally require review')
        && str_contains($readme, 'searchable')
        && str_contains($readme, 'production-mail')
        && str_contains($readme, 'Attachment contents are not stored')
        && str_contains($readme, 'does not delete or move the source message'),
    'the gateway, deployment modes, routing limits, and attachment policy should be documented.'
);

echo "Inbound email feature tests passed.\n";
