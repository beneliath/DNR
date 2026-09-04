<?php

function expectBuildProvenanceScript($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Build provenance script test failed: {$message}\n");
        exit(1);
    }
}

$script_path = __DIR__ . '/../scripts/compose_with_provenance.sh';
$script = file_get_contents($script_path);
$s1_deploy_path = __DIR__ . '/../scripts/deploy_s1.sh';
$s1_deploy = file_get_contents($s1_deploy_path);
$readme = file_get_contents(__DIR__ . '/../README.md');
$secure_existing = file_get_contents(__DIR__ . '/../scripts/secure_existing_deployment.sh');
$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile');

expectBuildProvenanceScript(
    is_executable($script_path)
        && str_contains($script, 'git -C "$project_directory" rev-parse --verify HEAD')
        && str_contains($script, 'TZ=UTC git -C "$project_directory" show -s')
        && str_contains($script, "grep -Eq '^[0-9A-Fa-f]{40}$'")
        && str_contains($script, "YYYY-MM-DDTHH:MM:SSZ"),
    'the wrapper should derive and validate immutable commit metadata.'
);
expectBuildProvenanceScript(
    str_contains($script, 'development-smtp-ca|dev-smtp-ca)')
        && str_contains($script, 'development-mail-smtp-ca|dev-mail-smtp-ca)')
        && str_contains($script, 'production-smtp-ca|prod-smtp-ca)')
        && str_contains($script, 'production-mail-smtp-ca|prod-mail-smtp-ca)')
        && substr_count($script, '-f docker-compose.smtp-ca.yaml') === 4,
    'custom-CA SMTP modes should add the trust-anchor overlay without changing ordinary SMTP modes.'
);
expectBuildProvenanceScript(
    str_contains($script, 'status --porcelain --untracked-files=normal')
        && str_contains($script, 'Refusing to build with uncommitted files')
        && str_contains($script, 'DNR_BUILD_COMMIT and DNR_BUILD_TIMESTAMP must be supplied together.'),
    'the wrapper should reject misleading dirty or partial provenance.'
);
expectBuildProvenanceScript(
    str_contains($script, 'development|dev)')
        && str_contains($script, '-f docker-compose.dev.yaml')
        && str_contains($script, 'production|prod)')
        && str_contains($script, 'exec docker compose -f docker-compose.yaml "$@"'),
    'development and production builds should use the correct Compose files.'
);
expectBuildProvenanceScript(
    str_contains($script, 'development-mail|dev-mail)')
        && str_contains($script, 'production-mail|prod-mail)')
        && substr_count($script, '-f docker-compose.mail.yaml') === 8
        && str_contains($readme, 'compose_with_provenance.sh production-mail'),
    'mail-enabled modes should add the inbound worker Compose overlay.'
);
expectBuildProvenanceScript(
    str_contains($script, 'development-smtp|dev-smtp)')
        && str_contains($script, 'production-smtp|prod-smtp)')
        && str_contains($script, 'development-mail-smtp|dev-mail-smtp)')
        && str_contains($script, 'production-mail-smtp|prod-mail-smtp)')
        && substr_count($script, '-f docker-compose.smtp.yaml') === 10,
    'SMTP modes should add durable outbound delivery alone or alongside inbound mail.'
);
expectBuildProvenanceScript(
    str_contains($script, 'production-ubuntu|prod-ubuntu)')
        && str_contains($script, 'production-ubuntu-proton|prod-ubuntu-proton)')
        && substr_count($script, '-f docker-compose.ubuntu.yaml') === 4
        && substr_count($script, '-f docker-compose.proton-bridge.yaml') === 2,
    'Ubuntu modes should use the private Traefik edge and optionally the colocated Proton Bridge.'
);
expectBuildProvenanceScript(
    str_contains($script, 'development-mattermost|dev-mattermost)')
        && str_contains($script, 'production-mattermost|prod-mattermost)')
        && str_contains($script, 'production-ubuntu-mattermost|prod-ubuntu-mattermost)')
        && str_contains($script, 'production-ubuntu-proton-mattermost|prod-ubuntu-proton-mattermost)')
        && substr_count($script, '-f docker-compose.mattermost.yaml') === 4,
    'Mattermost modes should mount the integration secret in development and supported production layouts.'
);
expectBuildProvenanceScript(
    substr_count($readme, 'compose_with_provenance.sh') >= 6
        && str_contains($secure_existing, 'compose_with_provenance.sh" "$compose_mode" up -d --build web geocoder ingress')
        && str_contains($readme, 'DNR_COMPOSE_MODE=development'),
    'documented and automated deployment builds should use the provenance wrapper.'
);
expectBuildProvenanceScript(
    strpos($dockerfile, 'ARG DNR_BUILD_COMMIT') > strpos($dockerfile, 'COPY src/ /var/www/html/'),
    'provenance-only changes should not invalidate expensive image build layers.'
);

expectBuildProvenanceScript(
    is_string($s1_deploy)
        && is_executable($s1_deploy_path)
        && str_contains($s1_deploy, 'DNR_S1_HOST:-192.168.1.150')
        && str_contains($s1_deploy, 'DNR_S1_PROJECT_DIR:-/home/dgilmore/moed')
        && str_contains($s1_deploy, 'DNR_S1_COMPOSE_MODE:-production-ubuntu-proton-mattermost')
        && str_contains($s1_deploy, 'ci_state'),
    'the s1 workflow should encode its stable endpoint, checkout, full topology, and CI gate.'
);
expectBuildProvenanceScript(
    str_contains($s1_deploy, 'status --porcelain --untracked-files=normal')
        && str_contains($s1_deploy, 'generate_daily_digest_preview.php" --check')
        && str_contains($s1_deploy, 'merge --ff-only origin/main')
        && str_contains($s1_deploy, 'DNR_BUILD_COMMIT')
        && str_contains($s1_deploy, 'State.Health.Status')
        && str_contains($s1_deploy, 'did not become healthy within 60 seconds')
        && str_contains($s1_deploy, 'sleep 2')
        && str_contains($s1_deploy, 'State.ExitCode')
        && str_contains($s1_deploy, '/ready.php')
        && str_contains($s1_deploy, '/health.php'),
    'the s1 workflow should reject ambiguous source and stale previews, then verify provenance, migrations, health, and readiness.'
);
expectBuildProvenanceScript(
    str_contains($readme, '`[super].[major].[minor]` = `x.y.z`')
        && str_contains($readme, '`1.10.3` becomes `1.10.4`')
        && str_contains($readme, './scripts/deploy_s1.sh "$(git rev-parse HEAD)"'),
    'release and deployment documentation should preserve the project version ontology and one-command s1 path.'
);

echo "Build provenance script tests passed.\n";
