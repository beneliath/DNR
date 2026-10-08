<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/account_login_helpers.php';
header('Cache-Control: no-store');
header('Content-Type: application/json');
if (!accountGatewayEnabled() || accountIsPrimary() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404); exit('{}');
}
try {
    $body = file_get_contents('php://input', false, null, 0, ACCOUNT_SERVICE_MAX_BODY_BYTES + 1);
    $time = (string) ($_SERVER['HTTP_X_MOED_TIME'] ?? '');
    $nonce = (string) ($_SERVER['HTTP_X_MOED_NONCE'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_MOED_SIGNATURE'] ?? '');
    if (!is_string($body) || strlen($body) > ACCOUNT_SERVICE_MAX_BODY_BYTES || !ctype_digit($time)
        || abs(time() - (int) $time) > 60 || !preg_match('/\A[a-f0-9]{64}\z/D', $nonce)) throw new RuntimeException('Invalid request.');
    $secret = configurationSecret('DNR_PLATFORM_API_KEY');
    if (strlen($secret) < 64) throw new RuntimeException('Invalid configuration.');
    $expected = hash_hmac('sha256', "primary\n" . currentAccountKey() . "\n" . $time . "\n" . $nonce . "\n" . $body, $secret);
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Invalid request.');
    $conn->execute_query('DELETE FROM account_service_nonces WHERE expires_at < UTC_TIMESTAMP()');
    $conn->execute_query('INSERT INTO account_service_nonces VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND))', [$nonce]);
    $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    $payload = $request['payload'] ?? [];
    if (($request['operation'] ?? '') === 'deliver_mail' && accountMailEnabled() && is_array($payload)) {
        require_once __DIR__ . '/inbound_email_helpers.php';
        require_once __DIR__ . '/account_mail_helpers.php';
        setDatabaseAuditContext($conn, null, 'Account Mail Gateway');
        echo json_encode(acceptAccountInboundMail($conn, $payload), JSON_THROW_ON_ERROR);
        exit;
    }
    if (strlen($body) > 16384) throw new RuntimeException('Invalid request.');
    if (($request['operation'] ?? '') === 'recover_password') {
        require_once __DIR__ . '/account_recovery_helpers.php';
        echo json_encode(acceptSharedPasswordRecovery($conn, $payload), JSON_THROW_ON_ERROR); exit;
    }
    if (($request['operation'] ?? '') === 'directory_receipt') {
        echo json_encode(accountDirectoryReceipt($conn, (string) ($payload['operation_id'] ?? '')), JSON_THROW_ON_ERROR); exit;
    }
    if (($request['operation'] ?? '') === 'directory_profile') {
        $profile = $conn->query('SELECT account_key,name,version FROM account_profile WHERE id=1')->fetch_assoc();
        echo json_encode(['account_key' => $profile['account_key'], 'name' => $profile['name'], 'version' => (int) $profile['version']], JSON_THROW_ON_ERROR); exit;
    }
    if (($request['operation'] ?? '') !== 'password' || !is_array($payload)) throw new RuntimeException('Invalid request.');
    $username = is_string($payload['username'] ?? null) ? $payload['username'] : '';
    $password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
    $user = fetchAuthenticationUserByUsername($conn, $username);
    $valid = \Dnr\Security\PasswordPolicy::verify($password, $user['password'] ?? '$2y$12$wTYbXn3kB2NAKPhZdVBniuzRdPySg8k3v67l4dxLCh7t3kGpifYI.');
    if (!$user || (int) $user['id'] !== (int) ($payload['user_id'] ?? 0) || !passwordAuthenticationIsAccepted($user, $valid)) {
        if ($user && $user['account_status'] === 'active' && empty($user['login_is_locked'])) recordAuthenticationFailure($conn, (int) $user['id'], 'password');
        echo '{"ok":false}'; exit;
    }
    resetAuthenticationFailures($conn, (int) $user['id'], 'password');
    $token = bin2hex(random_bytes(32));
    $conn->execute_query('DELETE FROM account_login_handoffs WHERE expires_at < UTC_TIMESTAMP()');
    $conn->execute_query('INSERT INTO account_login_handoffs (token_hash, user_id, auth_version, expires_at)
        VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND))', [hash('sha256', $token), (int) $user['id'], (int) $user['auth_version']]);
    echo json_encode(['ticket' => $token], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(403); echo '{"error":"Unavailable."}';
}
