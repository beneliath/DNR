<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/mattermost_integration_helpers.php';

function expectMattermost(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Mattermost integration feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/20260831_add_mattermost_integration.sql');
$emailMigration = file_get_contents($root . '/migrations/20260901_add_mattermost_email_workflow.sql');
$api = file_get_contents($root . '/src/api/v1/mattermost.php');
$emailHelpers = file_get_contents($root . '/src/mattermost_email_helpers.php');
$emailQueue = file_get_contents($root . '/src/engagement_email_helpers.php');
$inboundEmail = file_get_contents($root . '/src/inbound_email_helpers.php');
$databasePrivileges = file_get_contents($root . '/scripts/configure_database_privileges.sh');
$accountPage = file_get_contents($root . '/src/mattermost.php');
$accountPageStyles = file_get_contents($root . '/src/assets/css/pages/mattermost.css');
$helpers = file_get_contents($root . '/src/mattermost_integration_helpers.php');
$manifestRaw = file_get_contents($root . '/mattermost-plugin/plugin.json');
$compose = file_get_contents($root . '/docker-compose.mattermost.yaml');
$secretEntrypoint = file_get_contents($root . '/docker/mattermost-secret-entrypoint.sh');
$apacheSecurity = file_get_contents($root . '/docker/apache-security.conf');
$documentation = file_get_contents($root . '/docs/mattermost-plugin.md');
$webapp = file_get_contents($root . '/mattermost-plugin/webapp/src/index.jsx');
$sidebarLabel = file_get_contents($root . '/mattermost-plugin/webapp/src/sidebar_channel_label.mjs');
$pluginServer = file_get_contents($root . '/mattermost-plugin/server/http.go');
$pluginCommand = file_get_contents($root . '/mattermost-plugin/server/command.go');
$pluginChannelMarker = file_get_contents($root . '/mattermost-plugin/server/channel_marker.go');
$pluginRender = file_get_contents($root . '/mattermost-plugin/server/render.go');
$pluginLifecycle = file_get_contents($root . '/mattermost-plugin/server/plugin.go');
$pluginNotifications = file_get_contents($root . '/mattermost-plugin/server/notifications.go');
$pluginReadme = file_get_contents($root . '/mattermost-plugin/README.md');

foreach ([$migration, $emailMigration, $api, $emailHelpers, $emailQueue, $inboundEmail, $databasePrivileges, $accountPage, $accountPageStyles, $helpers, $manifestRaw, $compose, $secretEntrypoint, $apacheSecurity, $documentation, $webapp, $sidebarLabel, $pluginServer, $pluginCommand, $pluginChannelMarker, $pluginRender, $pluginLifecycle, $pluginNotifications, $pluginReadme] as $source) {
    expectMattermost(is_string($source), 'all integration source files should be readable.');
}

expectMattermost(
    preg_match('/\bMoed\b/', implode("\n", [$api, $emailHelpers, $accountPage, $manifestRaw, $documentation, $webapp, $pluginServer, $pluginCommand, $pluginRender, $pluginLifecycle, $pluginNotifications, $pluginReadme])) === 0,
    'all user-visible Mattermost integration output should capitalize MOED consistently.'
);

expectMattermost(
    normalizeMattermostLinkCode(' abcd-2345 ') === 'ABCD2345'
        && normalizeMattermostLinkCode('AB CD_23-45') === 'ABCD2345',
    'account-link codes should be normalized without weakening their alphabet validation.'
);

expectMattermost(
    str_contains($emailMigration, 'uq_engagement_email_mattermost_request')
        && str_contains($emailMigration, 'mattermost_reply_notifications')
        && str_contains($emailMigration, 'delivered_at DATETIME(6)')
        && str_contains($emailQueue, '$mattermostIdempotencyKey')
        && str_contains($emailQueue, "existingRow['created_by']")
        && str_contains($emailQueue, 'belongs to another user')
        && str_contains($inboundEmail, 'queueMattermostReplyNotifications')
        && str_contains($emailHelpers, 'message.mattermost_instance_id = link.instance_id')
        && str_contains($databasePrivileges, 'mattermost_reply_notifications'),
    'the email extension should provide idempotent requests, durable private reply alerts, inbound routing, and least-privilege database access.'
);

expectMattermost(
    str_contains($migration, 'code_hash BINARY(32)')
        && str_contains($migration, 'consumed_at DATETIME(6)')
        && str_contains($migration, 'uq_mattermost_external_identity')
        && str_contains($migration, 'uq_mattermost_moed_identity')
        && str_contains($migration, 'mattermost_idempotency_keys'),
    'the migration should store only link-code digests, enforce one-to-one identities, and persist idempotency responses.'
);

expectMattermost(
    str_contains($helpers, "configurationSecret('DNR_MATTERMOST_TOKEN')")
        && str_contains($helpers, "hash('sha256', \$raw, true)")
        && str_contains($helpers, 'MATTERMOST_LINK_CODE_TTL_SECONDS = 600')
        && str_contains($helpers, "users.account_status = 'active'")
        && str_contains($helpers, 'canManageFollowUpTasks')
        && str_contains($helpers, 'hash_equals'),
    'MOED should fail closed, hash short-lived codes, require active linked accounts, and retain role checks.'
);

expectMattermost(
    str_contains($api, "mattermostApiHeader('Authorization')")
        && str_contains($api, "mattermostApiHeader('X-Mattermost-Instance-ID')")
        && str_contains($api, "mattermostApiHeader('X-Mattermost-User-ID')")
        && str_contains($api, "mattermostApiHeader('Idempotency-Key')")
        && str_contains($api, "header('Cache-Control: no-store")
        && !str_contains($api, 'startSecureSession()'),
    'the integration API should use service and external identity headers, idempotency, no-store responses, and no browser session.'
);

expectMattermost(
    str_contains($accountPage, 'requireValidCsrfToken();')
        && str_contains($accountPage, 'Generate One-Time Code')
        && str_contains($accountPage, 'revokeMattermostLink')
        && str_contains($accountPage, '/moed connect')
        && str_contains($accountPage, 'assets/css/pages/mattermost.min.css?rev=linked-columns-1')
        && str_contains($accountPage, 'mattermost-links-table')
        && str_contains($accountPageStyles, 'width: 100%')
        && str_contains($accountPageStyles, 'table-layout: fixed')
        && str_contains($accountPageStyles, 'overflow-x: auto'),
    'the authenticated account page should protect generation and revocation, explain the private linking command, and spread linked-account columns across the card responsively.'
);

$manifest = json_decode((string) $manifestRaw, true);
expectMattermost(
    is_array($manifest)
        && ($manifest['id'] ?? null) === 'org.moed.mattermost'
        && ($manifest['version'] ?? null) === '0.4.4'
        && isset($manifest['server']['executables']['linux-amd64'])
        && ($manifest['webapp']['bundle_path'] ?? null) === 'webapp/dist/main.js'
        && ($manifest['settings_schema']['settings'][1]['secret'] ?? false) === true,
    'the Mattermost manifest should be installable, versioned, cross-platform, and mask the service token.'
);

expectMattermost(
    str_contains($webapp, "registerPostTypeComponent('custom_moed_today'")
        && str_contains($webapp, "registerPostTypeComponent('custom_moed_event'")
        && str_contains($webapp, "registerPostDropdownMenuAction('Add MOED task'")
        && str_contains($webapp, "registerPostDropdownMenuAction('Add to MOED Chron'")
        && str_contains($webapp, "registerPostDropdownMenuAction('Send via MOED email'")
        && str_contains($webapp, 'registerChannelHeaderIcon')
        && str_contains($webapp, 'registerChannelHeaderButtonAction')
        && str_contains($webapp, 'registerChannelHeaderMenuAction')
        && str_contains($webapp, 'registerSidebarChannelLinkLabelComponent')
        && str_contains($webapp, 'shortMOEDSidebarChannelDisplayName(displayName)')
        && str_contains($webapp, "classList.add('moed-sidebar-label-active')")
        && str_contains($sidebarLabel, '[MOED#${match[1]}]')
        && str_contains($documentation, 'compact `[MOED#17]`')
        && str_contains($documentation, 'web, desktop, and mobile')
        && str_contains($documentation, 'continue to show and copy the full')
        && str_contains($webapp, 'moed-channel-header-icon--linked')
        && str_contains($pluginChannelMarker, 'channelDisplayNameWithMarker')
        && str_contains($pluginChannelMarker, 'compactMOEDChannelMarker')
        && str_contains($pluginChannelMarker, 'unlinkedChannelDisplayName')
        && str_contains($pluginChannelMarker, 'ChannelDisplayNameMaxRunes')
        && str_contains($pluginChannelMarker, 'reconcileChannelBindingMarkers')
        && str_contains($pluginChannelMarker, 'PermissionManagePublicChannelProperties')
        && str_contains($pluginChannelMarker, 'PermissionManagePrivateChannelProperties')
        && str_contains($pluginChannelMarker, 'PermissionCreatePost')
        && str_contains($pluginChannelMarker, 'isSignedMOEDChannelMarker')
        && str_contains($pluginLifecycle, 'p.reconcileChannelBindingMarkers()')
        && str_contains($webapp, "pluginRequest('/email-send'")
        && str_contains($webapp, 'stableIdempotencyKey(')
        && str_contains($webapp, "useRef({fingerprint: '', key: ''})")
        && str_contains($webapp, 'Send and add to Chron')
        && str_contains($webapp, 'include_thread')
        && !str_contains($webapp, 'registerPostDropdownSubMenuAction')
        && str_contains($webapp, "value.startsWith('MMCSRF=')")
        && str_contains($webapp, "headers['X-CSRF-Token'] = csrfToken")
        && str_contains($helpers, "\$engagement['email_routing_marker'] = applicationInboundMarker(\$engagementId)")
        && str_contains($webapp, "function RoutingMarker({value})")
        && str_contains($webapp, "navigator.clipboard.writeText(value)")
        && str_contains($webapp, "document.execCommand('copy')")
        && str_contains($webapp, "aria-live='polite'")
        && str_contains($webapp, "event.email_routing_marker && <RoutingMarker")
        && !str_contains($pluginCommand, 'channelVisibleEngagement')
        && str_contains($pluginRender, 'Email routing marker')
        && str_contains($pluginCommand, '**Message actions** (the grid icon, not the three-dot menu)')
        && str_contains($webapp, "pluginRequest('/post-action'")
        && str_contains($pluginServer, 'HasPermissionToChannel')
        && str_contains($pluginServer, 'p.channelBinding(post.ChannelId)')
        && str_contains($pluginServer, 'p.mattermostEmailContexts')
        && str_contains($pluginServer, 'binding.EngagementID')
        && str_contains($api, "if (\$action === 'create_task')")
        && str_contains($api, "if (\$action === 'save_chron')")
        && str_contains($api, "if (\$action === 'email_compose')")
        && str_contains($api, "if (\$action === 'email_send')")
        && str_contains($emailHelpers, 'mattermostEmailContactPayload')
        && str_contains($emailHelpers, 'fetchEngagementContacts')
        && str_contains($pluginNotifications, 'GetDirectChannel')
        && str_contains($pluginNotifications, 'The message was routed privately')
        && str_contains($documentation, '**Message actions**')
        && str_contains($documentation, '**Add MOED')
        && str_contains($documentation, '**Add to MOED Chron**')
        && str_contains($documentation, '**Send via MOED email**'),
    'the bundle should render native dashboards/cards, preserve quick-copy routing markers, identify linked channels, and expose server-authorized task, Chron, and reviewed email actions.'
);

expectMattermost(
    str_contains($compose, 'entrypoint: ["/usr/local/bin/dnr-mattermost-secret-entrypoint"]')
        && str_contains($compose, 'command: ["apache2-foreground"]')
        && str_contains($compose, '/run/dnr-mattermost:rw,noexec,nosuid,size=1m,mode=0700')
        && str_contains($compose, 'DNR_MATTERMOST_TOKEN_FILE: /run/secrets/dnr_mattermost_token')
        && str_contains($compose, 'DNR_MATTERMOST_TOKEN_SECRET_FILE')
        && str_contains($secretEntrypoint, 'chown www-data:www-data "$runtime_dir"')
        && str_contains($secretEntrypoint, 'install -m 0400 "$source_path" "$runtime_tmp"')
        && str_contains($secretEntrypoint, 'chown www-data:www-data "$runtime_tmp"')
        && str_contains($secretEntrypoint, 'export DNR_MATTERMOST_TOKEN_FILE=$runtime_path')
        && str_contains($apacheSecurity, 'SetEnvIfNoCase Authorization "^(.*)$" HTTP_AUTHORIZATION=$1')
        && str_contains($documentation, 'System Console → Plugins → Plugin Management')
        && str_contains($documentation, '/moed status'),
    'deployment should mount the host-protected service token, prepare an Apache-only runtime copy, and document installation and verification.'
);

echo "Mattermost integration feature tests passed.\n";
