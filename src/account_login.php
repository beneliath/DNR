<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/account_login_helpers.php';
startSecureSession();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (!accountGatewayEnabled() || accountIsPrimary()) { http_response_code(404); exit('Not found.'); }
try {
    $token = \Dnr\Http\RequestInput::string($_GET, 'ticket');
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) throw new RuntimeException('Invalid sign-in.');
    $conn->begin_transaction();
    $ticket = $conn->execute_query('SELECT user_id, auth_version FROM account_login_handoffs
        WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE', [hash('sha256', $token)])->fetch_assoc();
    if (!$ticket) throw new RuntimeException('Invalid sign-in.');
    $user = fetchAuthenticationUserById($conn, (int) $ticket['user_id']);
    if (!passwordAuthenticationIsAccepted($user, true) || (int) $user['auth_version'] !== (int) $ticket['auth_version']) throw new RuntimeException('Invalid sign-in.');
    $conn->execute_query('UPDATE account_login_handoffs SET consumed_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
    $conn->commit();
    beginPendingAuthentication($user);
    header('Location: ' . (!empty($user['two_factor_enabled']) ? 'verify_2fa.php' : 'setup_2fa.php'));
} catch (Throwable $error) {
    $conn->rollback(); http_response_code(403);
    echo 'This sign-in link is invalid or expired. Please sign in again.';
}
