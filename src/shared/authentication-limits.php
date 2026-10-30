<?php

declare(strict_types=1);

function authenticationRateLimitSettings($scope) {
    $settings = [
        'login_ip' => ['limit' => 8, 'window' => 300, 'block' => 900],
        // Correct credentials bypass account throttles. The account scope only
        // suppresses further failures, so an attacker cannot lock out a user.
        'login_account' => ['limit' => 30, 'window' => 900, 'block' => 300],
        'recovery_ip' => ['limit' => 8, 'window' => 600, 'block' => 1800],
        'recovery_account' => ['limit' => 15, 'window' => 900, 'block' => 900],
    ];
    if (!isset($settings[$scope])) {
        throw new InvalidArgumentException('Unknown authentication rate-limit scope.');
    }
    return $settings[$scope];
}

function loginRateLimitSettings($scope) {
    $legacy_scopes = ['ip' => 'login_ip', 'account' => 'login_account'];
    return authenticationRateLimitSettings($legacy_scopes[$scope] ?? $scope);
}

function authenticationRateLimitKey($scope, $identifier) {
    return hash('sha256', (string) $scope . "\0" . (string) $identifier);
}

function authenticationRateLimitAccountIdentifier($username) {
    return strtolower(substr(trim((string) $username), 0, 255));
}

function requireLoginRateLimitSchema(mysqli $conn) {
    // Schema readiness is checked outside the request path.
}

function authenticationRateLimitIsBlocked(mysqli $conn, $scope, $identifier) {
    $stmt = $conn->prepare(
        'SELECT blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP() AS is_blocked
         FROM authentication_rate_limits
         WHERE key_hash = UNHEX(?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check authentication availability.');
    }
    $key = authenticationRateLimitKey($scope, $identifier);
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to check authentication availability.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && !empty($row['is_blocked']);
}

function authenticationSourceIsBlocked(mysqli $conn, $purpose) {
    $scope = $purpose . '_ip';
    return authenticationRateLimitIsBlocked(
        $conn,
        $scope,
        requestIpAddress() ?: 'unknown'
    );
}

function recordAuthenticationRateLimitFailure(mysqli $conn, $purpose, $username) {
    $identifiers = [
        $purpose . '_ip' => requestIpAddress() ?: 'unknown',
    ];
    $account_identifier = authenticationRateLimitAccountIdentifier($username);
    if ($account_identifier !== '') {
        $identifiers[$purpose . '_account'] = $account_identifier;
    }

    $states = [];
    foreach ($identifiers as $scope => $identifier) {
        $settings = authenticationRateLimitSettings($scope);
        $window = (int) $settings['window'];
        $limit = (int) $settings['limit'];
        $block = (int) $settings['block'];
        $key = authenticationRateLimitKey($scope, $identifier);
        $sql = "INSERT INTO authentication_rate_limits
                    (key_hash, scope, attempts, window_started_at, blocked_until, updated_at)
                VALUES (UNHEX(?), ?, 1, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE
                    blocked_until = CASE
                        WHEN window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND) THEN NULL
                        WHEN attempts + 1 >= {$limit} THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$block} SECOND)
                        ELSE blocked_until
                    END,
                    attempts = CASE
                        WHEN window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND) THEN 1
                        ELSE attempts + 1
                    END,
                    window_started_at = CASE
                        WHEN window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND) THEN UTC_TIMESTAMP()
                        ELSE window_started_at
                    END,
                    updated_at = UTC_TIMESTAMP()";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to record authentication availability.');
        }
        $stmt->bind_param('ss', $key, $scope);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to record authentication availability.');
        }
        $stmt->close();

        $status_stmt = $conn->prepare(
            'SELECT attempts, blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP() AS is_blocked
             FROM authentication_rate_limits WHERE key_hash = UNHEX(?)'
        );
        $status_stmt->bind_param('s', $key);
        $status_stmt->execute();
        $status = $status_stmt->get_result()->fetch_assoc();
        $status_stmt->close();
        $states[$scope] = [
            'attempts' => (int) ($status['attempts'] ?? 0),
            'blocked' => !empty($status['is_blocked']),
            'just_blocked' => !empty($status['is_blocked'])
                && (int) ($status['attempts'] ?? 0) === $limit,
        ];
    }

    return $states;
}

function clearAuthenticationRateLimits(mysqli $conn, $purpose, $username) {
    $keys = [authenticationRateLimitKey($purpose . '_ip', requestIpAddress() ?: 'unknown')];
    $account_identifier = authenticationRateLimitAccountIdentifier($username);
    if ($account_identifier !== '') {
        $keys[] = authenticationRateLimitKey($purpose . '_account', $account_identifier);
    }
    $stmt = $conn->prepare('DELETE FROM authentication_rate_limits WHERE key_hash = UNHEX(?)');
    if (!$stmt) {
        return false;
    }
    $success = true;
    foreach ($keys as $key) {
        $stmt->bind_param('s', $key);
        $success = $stmt->execute() && $success;
    }
    $stmt->close();

    // Successful logins provide a low-cost opportunity to bound stale state.
    // Keep this best-effort so cleanup can never prevent authentication.
    try {
        $conn->query(
            'DELETE FROM authentication_rate_limits
             WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        );
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to prune stale login rate-limit records', ['error' => $exception->getMessage()]);
    }

    return $success;
}

function loginRateLimitIsBlocked(mysqli $conn) {
    return authenticationSourceIsBlocked($conn, 'login');
}

function recordLoginRateLimitFailure(mysqli $conn, $username = '') {
    $states = recordAuthenticationRateLimitFailure($conn, 'login', $username);
    $ip_state = $states['login_ip'] ?? ['attempts' => 0, 'blocked' => false, 'just_blocked' => false];
    $account_blocked = !empty($states['login_account']['blocked']);
    return [
        'blocked' => !empty($ip_state['blocked']) || $account_blocked,
        'should_audit' => $ip_state['attempts'] <= 3 || !empty($ip_state['just_blocked']),
    ];
}

function clearLoginRateLimitForCurrentIp(mysqli $conn, $username = '') {
    return clearAuthenticationRateLimits($conn, 'login', $username);
}
