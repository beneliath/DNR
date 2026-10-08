<?php
declare(strict_types=1);
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/account_login_helpers.php';

/** Same response for missing users, unverified email, and unavailable Accounts. */
function queueSharedPasswordRecovery(mysqli $conn, string $username, string $email): void {
    if (trim($username) === '' || mb_strlen($username) > 50 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $conn->execute_query('INSERT INTO platform_recovery_requests (operation_id,username,email) VALUES (?,?,?)',
        [bin2hex(random_bytes(32)), trim($username), $email]);
}

/** Called only locally or by the authenticated primary; receipt and mail commit together. */
function acceptSharedPasswordRecovery(mysqli $conn, array $payload): array {
    $id = (string) ($payload['operation_id'] ?? '');
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) throw new InvalidArgumentException('Invalid recovery request.');
    $conn->begin_transaction();
    try {
        $conn->execute_query('INSERT IGNORE INTO account_recovery_receipts (operation_id) VALUES (?)', [$id]);
        if ($conn->affected_rows === 1) {
            $user = $conn->execute_query("SELECT id, email FROM users WHERE id=? AND username=? AND verified_email=LOWER(?)
                AND account_status='active' AND email_verified_at IS NOT NULL AND platform_identity_id IS NULL FOR UPDATE",
                [(int) ($payload['user_id'] ?? 0), (string) ($payload['username'] ?? ''), (string) ($payload['email'] ?? '')])->fetch_assoc();
            if ($user) {
                issueUserEmailToken($conn, (int) $user['id'], 'recovery', $user['email'], null, null, true);
                logSecurityEvent($conn, 'password_recovery_email_queued', (int) $user['id']);
            }
        }
        $conn->commit();
        return ['ok' => true];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

function processSharedPasswordRecovery(mysqli $conn): void {
    if (!accountGatewayEnabled() || !accountIsPrimary()) return;
    // Expire requests, never issue a surprise reset email days after an outage.
    $conn->query('DELETE FROM platform_recovery_requests WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE');
    $jobs = $conn->query('SELECT * FROM platform_recovery_requests WHERE retry_after IS NULL OR retry_after<=UTC_TIMESTAMP() ORDER BY created_at LIMIT 20')->fetch_all(MYSQLI_ASSOC);
    foreach ($jobs as $job) {
        try {
            $route = $conn->execute_query('SELECT account_key,user_id FROM platform_login_routes WHERE username=?', [$job['username']])->fetch_assoc();
            if (!$route && $conn->execute_query('SELECT 1 FROM platform_login_reservations WHERE username=?', [$job['username']])->fetch_row()) {
                throw new RuntimeException('Directory change pending.');
            }
            if ($route) {
                $payload = ['operation_id'=>$job['operation_id'], 'user_id'=>(int)$route['user_id'], 'username'=>$job['username'], 'email'=>$job['email']];
                if ($route['account_key'] === currentAccountKey()) acceptSharedPasswordRecovery($conn, $payload);
                else {
                    $account = $conn->execute_query("SELECT * FROM platform_accounts WHERE account_key=? AND state='ready'", [$route['account_key']])->fetch_assoc();
                    if ($account) {
                        $response = platformMemberCall($account, 'recover_password', $payload);
                        if (($response['ok'] ?? false) !== true) throw new RuntimeException('Recovery pending.');
                    }
                }
            }
            $conn->execute_query('DELETE FROM platform_recovery_requests WHERE operation_id=?', [$job['operation_id']]);
        } catch (Throwable $error) {
            $conn->execute_query('UPDATE platform_recovery_requests SET attempts=attempts+1,retry_after=UTC_TIMESTAMP()+INTERVAL 60 SECOND WHERE operation_id=?', [$job['operation_id']]);
        }
    }
}
