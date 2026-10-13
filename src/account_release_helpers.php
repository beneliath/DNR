<?php

declare(strict_types=1);

/** Validate build provenance from the authenticated member profile response. */
function assertAccountReleaseParity(array $profile, string $expectedCommit, string $expectedVersion): void
{
    $commit = $profile['application_commit'] ?? null;
    $version = $profile['application_version'] ?? null;
    if (preg_match('/\A[a-f0-9]{40}\z/D', $expectedCommit) !== 1
        || !is_string($commit) || !hash_equals($expectedCommit, $commit)
        || $expectedVersion === '' || !is_string($version) || !hash_equals($expectedVersion, $version)
    ) {
        throw new RuntimeException('Account release does not match the current qualified release.');
    }
}
