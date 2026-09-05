<?php

declare(strict_types=1);

/** Read only public deployment state; never open a session or touch its idle time. */
function deploymentNoticeStatus(?string $path = null, ?float $now = null): array
{
    $now ??= microtime(true);
    $path ??= '/run/dnr/deployment/notice.json';
    $payload = ['server_now' => $now, 'notice' => null];
    if (!is_file($path)) {
        return $payload;
    }
    $contents = @file_get_contents($path);
    $notice = $contents === false ? null : json_decode($contents, true);
    if (!is_array($notice)
        || !is_string($notice['id'] ?? null)
        || preg_match('/\A[a-f0-9]{32}\z/', $notice['id']) !== 1
        || !in_array($notice['phase'] ?? null, ['preparing', 'pending', 'deploying', 'failed'], true)
    ) {
        return $payload;
    }
    foreach (['started_at', 'expires_at'] as $field) {
        if (!is_int($notice[$field] ?? null) && !is_float($notice[$field] ?? null)) {
            return $payload;
        }
    }
    if (in_array($notice['phase'], ['preparing', 'pending'], true) && $notice['expires_at'] <= $now) {
        return $payload;
    }
    if ($notice['phase'] === 'preparing') {
        if (($notice['not_before'] ?? null) !== null || ($notice['countdown_started_at'] ?? null) !== null) {
            return $payload;
        }
    } else {
        $countdownStart = $notice['countdown_started_at'] ?? $notice['started_at'];
        if ((!is_int($countdownStart) && !is_float($countdownStart))
            || (!is_int($notice['not_before'] ?? null) && !is_float($notice['not_before'] ?? null))
            || $countdownStart < $notice['started_at']
            || $notice['not_before'] < $countdownStart + 300
        ) {
            return $payload;
        }
    }
    // Allowlist: no source SHA, paths, recovery details, credentials, or user data.
    $payload['notice'] = array_intersect_key($notice, array_flip([
        'id', 'phase', 'started_at', 'countdown_started_at', 'not_before', 'expires_at',
    ]));
    return $payload;
}
