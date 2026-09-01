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
$api = file_get_contents($root . '/src/api/v1/mattermost.php');
$accountPage = file_get_contents($root . '/src/mattermost.php');
$helpers = file_get_contents($root . '/src/mattermost_integration_helpers.php');
$manifestRaw = file_get_contents($root . '/mattermost-plugin/plugin.json');
$compose = file_get_contents($root . '/docker-compose.mattermost.yaml');
$secretEntrypoint = file_get_contents($root . '/docker/mattermost-secret-entrypoint.sh');
$documentation = file_get_contents($root . '/docs/mattermost-plugin.md');

foreach ([$migration, $api, $accountPage, $helpers, $manifestRaw, $compose, $secretEntrypoint, $documentation] as $source) {
    expectMattermost(is_string($source), 'all integration source files should be readable.');
}

expectMattermost(
    normalizeMattermostLinkCode(' abcd-2345 ') === 'ABCD2345'
        && normalizeMattermostLinkCode('AB CD_23-45') === 'ABCD2345',
    'account-link codes should be normalized without weakening their alphabet validation.'
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
    'Moed should fail closed, hash short-lived codes, require active linked accounts, and retain role checks.'
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
        && str_contains($accountPage, '/moed connect'),
    'the authenticated account page should protect generation and revocation and explain the private linking command.'
);

$manifest = json_decode((string) $manifestRaw, true);
expectMattermost(
    is_array($manifest)
        && ($manifest['id'] ?? null) === 'org.moed.mattermost'
        && ($manifest['version'] ?? null) === '0.2.0'
        && isset($manifest['server']['executables']['linux-amd64'])
        && ($manifest['settings_schema']['settings'][1]['secret'] ?? false) === true,
    'the Mattermost manifest should be installable, versioned, cross-platform, and mask the service token.'
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
        && str_contains($documentation, 'System Console → Plugins → Plugin Management')
        && str_contains($documentation, '/moed status'),
    'deployment should mount the host-protected service token, prepare an Apache-only runtime copy, and document installation and verification.'
);

echo "Mattermost integration feature tests passed.\n";
