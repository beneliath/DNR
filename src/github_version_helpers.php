<?php

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

function githubPushMetadata($fallback_commit = null, $fallback_pushed_at = null)
{
    $fallback = [
        'commit' => strtolower((string) $fallback_commit),
        'pushed_at' => (string) $fallback_pushed_at,
    ];
    $fallback = githubPushMetadataIsValid($fallback) ? $fallback : null;

    $repository = getenv('DNR_GITHUB_REPOSITORY') ?: 'beneliath/DNR';
    $branch = getenv('DNR_GITHUB_BRANCH') ?: 'main';
    if (!preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repository)
        || !preg_match('/\A[A-Za-z0-9._\/-]+\z/', $branch)) {
        return $fallback;
    }

    $cache_path = sys_get_temp_dir() . '/dnr-github-push-v2-' . sha1($repository . '|' . $branch) . '.json';
    $cached = null;
    if (is_file($cache_path)) {
        $cached = json_decode((string) @file_get_contents($cache_path), true);
        if (!githubPushMetadataIsValid($cached)) {
            $cached = null;
        }
    }

    $cache_ttl = (int) (getenv('DNR_GITHUB_PUSH_CACHE_TTL') ?: 120);
    $cache_ttl = max(30, min(3600, $cache_ttl));
    $cache_modified_at = is_file($cache_path) ? @filemtime($cache_path) : false;
    if ($cached !== null && $cache_modified_at !== false && time() - $cache_modified_at < $cache_ttl) {
        return $cached;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: DNR-Version-Footer\r\n",
            'timeout' => 2,
            'ignore_errors' => true,
        ],
    ]);
    $activities_json = @file_get_contents(
        'https://api.github.com/repos/' . $repository . '/activity?ref=' . rawurlencode($branch)
            . '&activity_type=push&per_page=30',
        false,
        $context
    );
    $activities = $activities_json === false ? null : json_decode($activities_json, true);
    $metadata = is_array($activities)
        ? githubPushMetadataFromActivities($activities, $branch)
        : null;

    if ($metadata !== null) {
        @file_put_contents($cache_path, json_encode($metadata), LOCK_EX);
        return $metadata;
    }

    return $cached ?? $fallback;
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
