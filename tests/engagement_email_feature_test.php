<?php

declare(strict_types=1);

function expectEngagementEmailFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement email feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$migration = $read('migrations/20260831_add_engagement_email_correspondence.sql');
$mattermostReactionMigration = $read(
    'migrations/20260903_add_mattermost_email_reaction_notifications.sql'
);
$order = $read('migrations/order.txt');
$helpers = $read('src/engagement_email_helpers.php');
$emailHelpers = $read('src/email_helpers.php');
$composer = $read('src/compose_engagement_email.php');
$detail = $read('src/outbound_mail.php');
$view = $read('src/view_engagement.php');
$worker = $read('scripts/process_email_outbox.php');
$grants = $read('scripts/configure_database_privileges.sh');
$compose = $read('docker-compose.smtp.yaml');
$mailCompose = $read('docker-compose.mail.yaml');
$javascript = $read('src/assets/js/engagement-email.js');
$emailStyles = $read('src/assets/css/pages/engagement_email.css');
$package = $read('package.json');
$environment = $read('.env.example');
$readme = $read('README.md');
$chronView = $read('src/templates/entity_chron_log_view_section.php');
$chronEdit = $read('src/templates/entity_chron_log_edit_section.php');
$webGrantSection = explode("CREATE USER IF NOT EXISTS '\${backup_user}'", $grants, 2)[0];
$mailDispatchGrantSection = explode(
    "CREATE USER IF NOT EXISTS '\${maintenance_user}'",
    explode("CREATE USER IF NOT EXISTS '\${mail_dispatch_user}'", $grants, 2)[1] ?? '',
    2
)[0];

expectEngagementEmailFeature(
    str_contains($migration, 'CREATE TABLE engagement_email_messages')
        && str_contains($migration, 'CREATE TABLE engagement_email_deliveries')
        && str_contains($migration, 'payload_ciphertext MEDIUMTEXT')
        && str_contains($migration, 'reply_to VARCHAR(254) NULL')
        && str_contains($migration, "status ENUM('pending', 'processing', 'retry', 'sent', 'failed')")
        && str_contains($migration, 'outbound_email_message_id')
        && str_contains($migration, 'REFERENCES engagement_email_messages(id) ON DELETE SET NULL')
        && str_contains($migration, 'uq_engagement_chron_outbound_email')
        && str_contains($migration, 'uq_contact_chron_outbound_email')
        && str_contains($migration, 'uq_organization_chron_outbound_email')
        && str_contains($order, '20260831_add_engagement_email_correspondence.sql'),
    'the ordered schema should retain one source message, independent encrypted deliveries, and idempotent Chron links.'
);
expectEngagementEmailFeature(
    str_contains($mattermostReactionMigration, 'mattermost_post_id CHAR(26)')
        && str_contains(
            $mattermostReactionMigration,
            'CREATE TABLE mattermost_post_reaction_notifications'
        )
        && str_contains($mattermostReactionMigration, 'outbound_email_message_id')
        && str_contains($mattermostReactionMigration, 'engagement_chron_entry_id')
        && str_contains($mattermostReactionMigration, "reaction_name ENUM('memo', 'email')")
        && str_contains($mattermostReactionMigration, 'delivered_at DATETIME(6) NULL')
        && str_contains($mattermostReactionMigration, 'next_attempt_at DATETIME(6)')
        && str_contains(
            $order,
            '20260903_add_mattermost_email_reaction_notifications.sql'
        ),
    'the ordered schema should retain optional Mattermost source posts and durable reaction acknowledgements.'
);
expectEngagementEmailFeature(
    str_contains($helpers, 'function engagementEmailTemplates(')
        && str_contains($helpers, "'booking_confirmation'")
        && str_contains($helpers, "'final_reconfirmation'")
        && str_contains($helpers, "'post_event_thanks'")
        && str_contains($helpers, 'function engagementEmailSafeEventBrief(')
        && str_contains($helpers, 'function engagementEmailReplyToAddress(')
        && str_contains($helpers, 'function queueEngagementEmail(')
        && str_contains($helpers, 'ApplicationKey::seal')
        && str_contains($helpers, 'function claimQueuedEngagementEmail(')
        && str_contains($helpers, 'FOR UPDATE SKIP LOCKED')
        && str_contains($helpers, 'payload_ciphertext = NULL')
        && str_contains($helpers, 'function retryFailedEngagementEmailDeliveries('),
    'correspondence should have safe templates, encrypted durable delivery, bounded retries, and explicit manual recovery.'
);
expectEngagementEmailFeature(
    str_contains($emailHelpers, 'Reply-To: <')
        && str_contains($worker, "\$message['reply_to']"),
    'engagement replies should return to the configured inbound mailbox when one is available.'
);
expectEngagementEmailFeature(
    str_contains($composer, 'requireValidCsrfToken')
        && str_contains($composer, "['admin', 'editor']")
        && str_contains($composer, 'name="contact_ids[]"')
        && str_contains($composer, 'data-select-recipient-role')
        && str_contains($composer, 'include_event_brief')
        && str_contains($composer, 'excludes Chron, internal notes, compensation, and financial information')
        && str_contains($detail, 'Retry Failed Deliveries')
        && str_contains($detail, 'engagementEmailAggregateStatus')
        && str_contains($view, 'compose_engagement_email.php?id=')
        && str_contains($view, 'fetchEngagementEmailMessages')
        && str_contains($view, 'Correspondence'),
    'editors should compose by event role and inspect or retry delivery from the engagement workflow.'
);
expectEngagementEmailFeature(
    str_contains($composer, '<body class="compose-engagement-email-body">')
        && str_contains($composer, 'class="container engagement-email-page compose-engagement-email-page"')
        && str_contains($composer, 'class="page-heading compose-engagement-email-heading"')
        && preg_match('/\.engagement-email-page\.compose-engagement-email-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $emailStyles) === 1
        && preg_match('/\.compose-engagement-email-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $emailStyles) === 1
        && preg_match('/\.compose-engagement-email-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $emailStyles) === 1,
    'the engagement email composer should use the Dashboard content width, heading scale, and footer alignment.'
);
expectEngagementEmailFeature(
    str_contains($chronView, 'View Outbound Message')
        && str_contains($chronEdit, 'View Outbound Message')
        && str_contains($view, 'View Outbound Message')
        && substr_count($helpers, 'outbound_email_message_id') >= 3,
    'outbound source links should appear in engagement, contact, and organization Chron logs.'
);
expectEngagementEmailFeature(
    str_contains($worker, 'claimQueuedEngagementEmail($conn, 600, false)')
        && str_contains($worker, 'deliverApplicationEmailWithSession(')
        && str_contains($worker, 'maintainQueuedEngagementEmail(')
        && str_contains($worker, 'completeQueuedEngagementEmail(')
        && str_contains($compose, 'DNR_ENGAGEMENT_EMAIL_OUTBOX_BATCH_SIZE:')
        && str_contains($mailCompose, 'DNR_INBOUND_ADDRESS: ${DNR_INBOUND_ADDRESS:-}')
        && str_contains($grants, '.engagement_email_messages')
        && str_contains($grants, '.engagement_email_deliveries')
        && str_contains($grants, "GRANT SELECT (\n    id, message_id, contact_id")
        && !str_contains($grants, 'GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.engagement_email_deliveries')
        && str_contains($grants, "TO '\${mail_dispatch_user}'@'%'")
        && !str_contains($grants, 'GRANT ALL PRIVILEGES'),
    'the isolated SMTP worker should receive only the additional delivery-table access it needs.'
);
expectEngagementEmailFeature(
    str_contains($webGrantSection, '.mattermost_post_reaction_notifications')
        && !str_contains($mailDispatchGrantSection, '.mattermost_post_reaction_notifications'),
    'the web identity should queue and acknowledge Mattermost reactions without widening SMTP-worker access.'
);
expectEngagementEmailFeature(
    str_contains($javascript, 'suggested_roles')
        && str_contains($javascript, 'dataset.selectRecipientRole')
        && str_contains($javascript, 'updateCount')
        && str_contains($package, 'engagement-email.min.js'),
    'the committed asset pipeline should support template and role-based recipient controls.'
);
expectEngagementEmailFeature(
    str_contains($environment, 'DNR_ENGAGEMENT_EMAIL_OUTBOX_BATCH_SIZE=20')
        && str_contains($readme, 'send tracked plain-text correspondence from an active')
        && str_contains($readme, 'Every unique address receives an independent delivery')
        && str_contains($readme, 'share-safe event brief')
        && str_contains($readme, 'DNR_ENGAGEMENT_EMAIL_OUTBOX_BATCH_SIZE'),
    'deployment and retention behavior should be documented with an explicit worker batch setting.'
);

echo "Engagement email feature tests passed.\n";
