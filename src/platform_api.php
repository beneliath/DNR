<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/account_login_helpers.php';
require_once __DIR__ . '/account_mail_helpers.php';
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (!accountIsPrimary() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(404); exit('{}'); }
try {
    $body = file_get_contents('php://input', false, null, 0, 16385);
    $key = (string) ($_SERVER['HTTP_X_MOED_ACCOUNT'] ?? '');
    $timestamp = (string) ($_SERVER['HTTP_X_MOED_TIME'] ?? '');
    $nonce = (string) ($_SERVER['HTTP_X_MOED_NONCE'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_MOED_SIGNATURE'] ?? '');
    if (!is_string($body) || strlen($body) > 16384 || !ctype_digit($timestamp)
        || abs(time() - (int) $timestamp) > 60 || !preg_match('/\A[a-f0-9]{64}\z/D', $nonce)) {
        throw new RuntimeException('Invalid request.');
    }
    $account = $conn->execute_query("SELECT api_key_encrypted FROM platform_accounts WHERE account_key = ? AND state = 'ready'", [$key])->fetch_assoc();
    if (!$account) throw new RuntimeException('Invalid request.');
    $secret = \Dnr\Security\ApplicationKey::open($account['api_key_encrypted']);
    $expected = hash_hmac('sha256', $key . "\n" . $timestamp . "\n" . $nonce . "\n" . $body, $secret);
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Invalid request.');
    $conn->execute_query('DELETE FROM platform_api_nonces WHERE expires_at < UTC_TIMESTAMP()');
    $conn->execute_query('INSERT INTO platform_api_nonces (account_key, nonce, expires_at)
        VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND))', [$key, $nonce]);
    $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    $payload = $request['payload'] ?? [];
    if (!is_array($payload)) throw new RuntimeException('Invalid request.');
    $operation = $request['operation'] ?? '';
    if ($operation === 'reserve_login' && accountGatewayEnabled()) {
        try { platformReserveLogin($conn, $key, $payload); $response = ['ok' => true]; }
        catch (InvalidArgumentException|mysqli_sql_exception $error) { $response = ['ok' => false]; }
    } elseif ($operation === 'reconcile_directory' && accountGatewayEnabled()) {
        $member = $conn->execute_query("SELECT * FROM platform_accounts WHERE account_key=? AND state='ready'", [$key])->fetch_assoc();
        reconcileAccountDirectory($conn, $member);
        $response = ['ok' => true];
    } elseif ($operation === 'redeem') {
        $token = $payload['ticket'] ?? '';
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token)) throw new RuntimeException('Invalid ticket.');
        $conn->begin_transaction();
        $ticket = $conn->execute_query('SELECT user_id, auth_version FROM platform_access_tickets
            WHERE token_hash = ? AND account_key = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE',
            [hash('sha256', $token), $key])->fetch_assoc();
        if (!$ticket) throw new RuntimeException('Invalid ticket.');
        $identity = platformEligibleIdentity($conn, (int) $ticket['user_id'], (int) $ticket['auth_version']);
        $conn->execute_query('UPDATE platform_access_tickets SET consumed_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
        $conn->commit();
        $response = $identity + ['grant' => platformIssueIdentityGrant($key, $identity)];
    } else {
        $identity = platformEligibleIdentity($conn, (int) ($payload['id'] ?? 0), (int) ($payload['auth_version'] ?? 0));
        platformVerifyIdentityGrant((string) ($payload['grant'] ?? ''), $key, $identity);
        setDatabaseAuditContext($conn, (int) $identity['id'], $identity['username']);
        $response = match ($operation) {
            'validate' => $identity,
            'mail_review_count' => ['count' => platformMailReviewCount($conn)],
            'list' => ['accounts' => array_map(static fn(array $account): array => $account + [
                'switch_intent' => platformIssueSwitchIntent($identity, $account['account_key']),
            ], platformAccountDirectory($conn))],
            'elevate' => (static function () use ($conn, $identity, $payload, $key): array {
                // The member key authenticates the service; the separate primary
                // grant proves sign-in. Sensitive actions still require fresh MFA.
                $_SESSION = ['user_id' => (int) $identity['id']];
                $ok = attemptAdminElevation($conn, (string) ($payload['password'] ?? ''), (string) ($payload['code'] ?? ''), false);
                return $ok ? ['ok' => true, 'elevation' => platformIssueElevationGrant($key, $identity, time() + 300)] : ['ok' => false];
            })(),
            'extend_elevation' => (static function () use ($identity, $payload, $key): array {
                $expiresAt = platformVerifyElevationGrant((string) ($payload['elevation_token'] ?? ''), $key, $identity);
                return ['elevation' => platformIssueElevationGrant($key, $identity, $expiresAt + 300)];
            })(),
            'create' => (static function () use ($conn, $identity, $payload, $key): array {
                platformVerifyElevationGrant((string) ($payload['elevation_token'] ?? ''), $key, $identity);
                try {
                    platformCreateAccount($conn, (string) ($payload['name'] ?? ''),
                        (string) ($payload['account_key'] ?? ''), (int) $identity['id']);
                    return ['ok' => true];
                } catch (InvalidArgumentException $error) {
                    return ['ok' => false, 'error' => $error->getMessage()];
                } catch (mysqli_sql_exception $error) {
                    if ($error->getCode() !== 1062) throw $error;
                    return ['ok' => false, 'error' => 'That address label is already in use. Choose a different label.'];
                }
            })(),
            default => throw new RuntimeException('Invalid request.'),
        };
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $conn->rollback();
    http_response_code(403);
    echo '{"error":"Account access unavailable."}';
}
