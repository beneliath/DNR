<?php

require_once __DIR__ . '/../src/github_version_helpers.php';

function expectGithubVersion($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "GitHub version helper test failed: {$message}\n");
        exit(1);
    }
}

$release_commit = 'd2778b2824bc50c6dae75fee99badacce25eae75';
$activities = [
    [
        'activity_type' => 'push',
        'timestamp' => '2026-08-17T08:10:00Z',
        'ref' => 'refs/heads/dev',
        'after' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ],
    [
        'activity_type' => 'push',
        'timestamp' => '2026-08-17T07:58:09Z',
        'ref' => 'refs/heads/main',
        'after' => $release_commit,
    ],
];

$metadata = githubPushMetadataFromActivities($activities, 'main');
expectGithubVersion(
    $metadata === [
        'commit' => $release_commit,
        'pushed_at' => '2026-08-17T07:58:09Z',
    ],
    'The newest repository push activity for the configured branch should be selected.'
);
expectGithubVersion(
    githubPushMetadataFromActivities($activities, 'missing') === null,
    'A missing branch should not return unrelated repository activity.'
);
expectGithubVersion(
    githubPushTimestampIsValid('2026-08-17T07:58:09Z')
        && !githubPushTimestampIsValid('')
        && !githubPushTimestampIsValid('2026-99-99T07:58:09Z'),
    'Only complete valid UTC push timestamps should be accepted.'
);
expectGithubVersion(
    githubPushTimestampLabel('2026-08-17T07:58:09Z', 'America/Chicago') === '2026-08-17 02:58:09 CDT',
    'The GitHub push timestamp should display in the configured application timezone.'
);
expectGithubVersion(
    githubPushTimestampLabel('2026-08-17T07:58:09Z', 'Not/A-Timezone') === '2026-08-17 07:58:09 UTC',
    'An invalid display timezone should safely fall back to UTC.'
);

$test_repository = 'test-owner/test-repo';
define('APP_VERSION', '1.1.2');
putenv('DNR_GITHUB_REPOSITORY=' . $test_repository);
putenv('DNR_BUILD_COMMIT=' . $release_commit);
putenv('DNR_BUILD_TIMESTAMP=2026-08-17T07:58:09Z');
putenv('DNR_TIMEZONE=America/Chicago');
ob_start();
include __DIR__ . '/../src/templates/footer.php';
$footer_markup = ob_get_clean();
putenv('DNR_GITHUB_REPOSITORY');
putenv('DNR_BUILD_COMMIT');
putenv('DNR_BUILD_TIMESTAMP');
putenv('DNR_TIMEZONE');

expectGithubVersion(
    str_contains($footer_markup, 'test-repo 1.1.2</a>')
        && str_contains($footer_markup, '>test-owner</a>')
        && str_contains($footer_markup, '<time datetime="2026-08-17T07:58:09Z">2026-08-17 02:58:09 CDT</time>')
        && !str_contains($footer_markup, 'pushed <time')
        && str_contains($footer_markup, '/commit/' . $release_commit)
        && str_contains($footer_markup, '>(d2778b2)</a>'),
    'The footer should render the push timestamp and linked abbreviated commit hash.'
);

expectGithubVersion(
    !str_contains(file_get_contents(__DIR__ . '/../src/github_version_helpers.php'), 'api.github.com')
        && !str_contains(file_get_contents(__DIR__ . '/../src/github_version_helpers.php'), 'file_get_contents('),
    'Rendering the footer must not perform a request-time GitHub API or cache-file lookup.'
);

echo "GitHub version helper tests passed.\n";
