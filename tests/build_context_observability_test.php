<?php

declare(strict_types=1);

function expectBuildContextObservability(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Build-context/observability test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$dockerignore = file_get_contents($root . '/.dockerignore');
$apache = file_get_contents($root . '/docker/apache-security.conf');
$ingress = file_get_contents($root . '/docker/apache-ingress.conf');
$dockerfile = file_get_contents($root . '/Dockerfile');

expectBuildContextObservability(
    is_string($dockerignore),
    '.dockerignore should be readable.'
);

expectBuildContextObservability(
    is_string($ingress)
        && str_contains(
            $ingress,
            'ProxyPass "/database_maintenance.php" "http://web:80/database_maintenance.php" connectiontimeout=5 timeout=300'
        )
        && str_contains($ingress, 'ProxyPass "/" "http://web:80/" connectiontimeout=5 timeout=60'),
    'only database-backup requests should receive the extended reverse-proxy timeout.'
);

foreach (['mattermost-plugin', 'output', 'operations-view-colon.png', 'tests'] as $excludedPath) {
    expectBuildContextObservability(
        preg_match('/^' . preg_quote($excludedPath, '/') . '$/m', $dockerignore) === 1,
        "{$excludedPath} should not be sent to application-image builds."
    );
}

expectBuildContextObservability(
    preg_match('/^migrations$/m', $dockerignore) !== 1
        && preg_match('/^\*\.sql$/m', $dockerignore) !== 1,
    'database migrations must remain available to the runtime image build.'
);

expectBuildContextObservability(
    is_string($apache)
        && str_contains($apache, 'duration_us=%D')
        && str_contains($apache, 'request_id=\"%{X-Request-ID}o\"')
        && str_contains($apache, '%m %U %H')
        && !str_contains($apache, '%q'),
    'access logs should include latency and correlation without logging query strings.'
);

expectBuildContextObservability(
    is_string($dockerfile)
        && str_contains($dockerfile, "find /usr/local/lib/php/extensions")
        && str_contains($dockerfile, 'apt-get purge -y --auto-remove'),
    'the production image should purge extension build dependencies after retaining runtime libraries.'
);

echo "Build-context and observability tests passed.\n";
