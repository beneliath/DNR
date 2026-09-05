<?php

declare(strict_types=1);

function expectCiWorkflowFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CI workflow feature test failed: {$message}\n");
        exit(1);
    }
}

$workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/ci.yml');

expectCiWorkflowFeature(
    is_string($workflow)
        && str_contains($workflow, 'group: ${{ github.workflow }}-${{ github.event.pull_request.number || github.ref }}')
        && str_contains($workflow, 'cancel-in-progress: true'),
    'superseded runs for the same branch or pull request should be cancelled.'
);
expectCiWorkflowFeature(
    substr_count($workflow, 'npm ci') === 2
        && substr_count($workflow, '--no-audit') === 2
        && substr_count($workflow, 'timeout 45s npm audit ') === 1
        && substr_count($workflow, 'timeout 45s composer audit ') === 1,
    'frontend installation should skip its implicit audit and explicit dependency audits should not be duplicated.'
);
expectCiWorkflowFeature(
    str_contains($workflow, 'dependency-audit:')
        && str_contains($workflow, 'frontend-quality:')
        && str_contains($workflow, "php-version: ['8.4', '8.5']"),
    'dependency, frontend, and PHP compatibility checks should remain independent parallel jobs.'
);
expectCiWorkflowFeature(
    str_contains($workflow, 'timeout 45s composer audit --locked --no-interaction')
        && str_contains($workflow, 'timeout 45s npm audit --package-lock-only --audit-level=high')
        && str_contains($workflow, '--fetch-retries=0')
        && str_contains($workflow, 'has("advisories") or has("abandoned")')
        && str_contains($workflow, '.metadata | has("vulnerabilities")'),
    'advisory-service calls should use bounded retries and distinguish outages from security findings.'
);
expectCiWorkflowFeature(
    str_contains($workflow, 'make -C mattermost-plugin webapp')
        && str_contains($workflow, 'git diff --exit-code -- src/assets mattermost-plugin'),
    'the single frontend job should still verify application and plugin build artifacts.'
);

echo "CI workflow feature test passed.\n";
