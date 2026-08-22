<?php

declare(strict_types=1);

function githubPushTimestampIsValid($timestamp)
{
    $timestamp = (string) $timestamp;
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d\TH:i:s\Z',
        $timestamp,
        new DateTimeZone('UTC')
    );

    return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $timestamp;
}

function githubPushMetadataFromActivities(array $activities, $branch = 'main')
{
    $expected_ref = 'refs/heads/' . ltrim((string) $branch, '/');

    foreach ($activities as $activity) {
        if (!is_array($activity)
            || ($activity['activity_type'] ?? '') !== 'push'
            || ($activity['ref'] ?? '') !== $expected_ref) {
            continue;
        }

        $commit = (string) ($activity['after'] ?? '');
        $pushed_at = (string) ($activity['timestamp'] ?? '');
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $commit)) {
            continue;
        }

        if (!githubPushTimestampIsValid($pushed_at)) {
            continue;
        }

        return [
            'commit' => strtolower($commit),
            'pushed_at' => $pushed_at,
        ];
    }

    return null;
}

function githubPushMetadataIsValid($metadata)
{
    if (!is_array($metadata)
        || !preg_match('/\A[0-9a-f]{40}\z/i', (string) ($metadata['commit'] ?? ''))) {
        return false;
    }

    return githubPushTimestampIsValid($metadata['pushed_at'] ?? '');
}

function githubRepositoryParts()
{
    $repository = getenv('DNR_GITHUB_REPOSITORY') ?: 'beneliath/DNR';
    if (!preg_match('/\A([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)\z/', $repository, $matches)) {
        return ['beneliath', 'DNR'];
    }
    return [$matches[1], $matches[2]];
}

function githubRepositoryUrl()
{
    [$owner, $repository] = githubRepositoryParts();
    return 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repository);
}

function githubPushMetadata($fallback_commit = null, $fallback_pushed_at = null)
{
    $metadata = [
        'commit' => strtolower((string) (getenv('DNR_BUILD_COMMIT') ?: $fallback_commit)),
        'pushed_at' => (string) (getenv('DNR_BUILD_TIMESTAMP') ?: $fallback_pushed_at),
    ];

    // Build provenance is injected by the release pipeline. Rendering a page
    // must never wait on, or disclose traffic to, a third-party API.
    return githubPushMetadataIsValid($metadata) ? $metadata : null;
}

function githubPushTimestampLabel($timestamp, $timezone_name)
{
    if (!githubPushTimestampIsValid($timestamp)) {
        return (string) $timestamp;
    }

    try {
        $timezone = new DateTimeZone((string) $timezone_name);
    } catch (Throwable $exception) {
        $timezone = new DateTimeZone('UTC');
    }

    return (new DateTimeImmutable((string) $timestamp))
        ->setTimezone($timezone)
        ->format('Y-m-d H:i:s T');
}
