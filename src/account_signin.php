<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (!accountsEnabled() || accountIsPrimary()) { http_response_code(404); exit('Not found.'); }
try {
    $ticket = \Dnr\Http\RequestInput::string($_GET, 'ticket');
    $identity = platformCall('redeem', ['ticket' => $ticket]);
    $platformId = (int) ($identity['id'] ?? 0);
    if ($platformId < 1) throw new RuntimeException('Invalid identity.');
    // This local actor preserves normal audit/FK semantics. It has no usable
    // password or MFA secret and cannot authenticate through the login form.
    $conn->execute_query("INSERT INTO users (username, password, role, platform_identity_id, two_factor_enabled,
        first_name, last_name, account_status, activated_at) VALUES (?, ?, 'admin', ?, 1, ?, ?, 'active', UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name)",
        ['_platform_' . $platformId, password_hash(bin2hex(random_bytes(64)), PASSWORD_DEFAULT),
            $platformId, $identity['first_name'], $identity['last_name']]);
    $proxy = $conn->execute_query('SELECT * FROM users WHERE platform_identity_id = ?', [$platformId])->fetch_assoc();
    if (!$proxy || $proxy['account_status'] !== 'active') throw new RuntimeException('Account access unavailable.');
    $_SESSION = [];
    completeAuthentication($conn, $proxy, true);
    $_SESSION['_platform_identity'] = ['id' => $platformId, 'auth_version' => (int) $identity['auth_version'],
        'grant' => $identity['grant']];
    $_SESSION['is_superadmin'] = true;
    $_SESSION['_account_key'] = currentAccountKey();
    header('Location: dashboard.php');
} catch (Throwable $error) {
    http_response_code(403);
    echo 'This Account sign-in link is invalid or expired. Return to Accounts and try again.';
}
