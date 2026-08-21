<?php

function expectBuildProvenanceScript($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Build provenance script test failed: {$message}\n");
        exit(1);
    }
}

$script_path = __DIR__ . '/../scripts/compose_with_provenance.sh';
$script = file_get_contents($script_path);
$readme = file_get_contents(__DIR__ . '/../README.md');
$secure_existing = file_get_contents(__DIR__ . '/../scripts/secure_existing_deployment.sh');

expectBuildProvenanceScript(
    is_executable($script_path)
        && str_contains($script, 'git -C "$project_directory" rev-parse --verify HEAD')
        && str_contains($script, 'TZ=UTC git -C "$project_directory" show -s')
        && str_contains($script, "grep -Eq '^[0-9A-Fa-f]{40}$'")
        && str_contains($script, "YYYY-MM-DDTHH:MM:SSZ"),
    'the wrapper should derive and validate immutable commit metadata.'
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
    substr_count($readme, 'compose_with_provenance.sh') >= 6
        && str_contains($secure_existing, 'compose_with_provenance.sh" "$compose_mode" up -d --build web geocoder')
        && str_contains($readme, 'DNR_COMPOSE_MODE=development'),
    'documented and automated deployment builds should use the provenance wrapper.'
);

echo "Build provenance script tests passed.\n";
