<?php
declare(strict_types=1);

/** An atomic unique key covers both committed and reserved usernames. */
function claimDirectoryUsername(mysqli $conn, string $key, int $userId, string $username): void {
    $conn->execute_query('INSERT IGNORE INTO platform_login_claims (username,account_key,user_id) VALUES (?,?,?)', [$username,$key,$userId]);
    $claim = $conn->execute_query('SELECT account_key,user_id FROM platform_login_claims WHERE username=? FOR UPDATE', [$username])->fetch_assoc();
    if (!$claim || $claim['account_key'] !== $key || (int) $claim['user_id'] !== $userId) throw new InvalidArgumentException('That username is unavailable.');
}

/** Local callers must have begun the transaction containing the user change. */
function stageAccountLogin(mysqli $conn, int $userId, ?string $username): void {
    $id = bin2hex(random_bytes(32));
    // Insert BEFORE contacting primary. A locking read by the reconciler waits
    // for this transaction, so an absent row proves rollback, never slow commit.
    $conn->execute_query('INSERT INTO account_directory_outbox (operation_id, user_id, username) VALUES (?, ?, ?)', [$id, $userId, $username]);
    $result = platformCall('reserve_login', ['operation_id' => $id, 'user_id' => $userId, 'username' => $username]);
    if (empty($result['ok'])) throw new InvalidArgumentException('That username is unavailable or an earlier change is still being synchronized.');
}

function platformReserveLogin(mysqli $conn, string $key, array $payload): void {
    $id = (string) ($payload['operation_id'] ?? '');
    $userId = (int) ($payload['user_id'] ?? 0);
    $username = $payload['username'] ?? null;
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $id) || $userId < 1) throw new InvalidArgumentException('Invalid operation.');
    if ($username !== null) validateDirectoryUsername($username);
    if ((int) $conn->query("SELECT GET_LOCK('moed-login-directory', 3)")->fetch_row()[0] !== 1) throw new RuntimeException('Directory busy.');
    $conn->begin_transaction();
    try {
        $existing = $conn->execute_query('SELECT * FROM platform_login_reservations WHERE operation_id=? FOR UPDATE', [$id])->fetch_assoc();
        if ($existing) {
            if ($existing['account_key'] !== $key || (int) $existing['user_id'] !== $userId || $existing['username'] !== $username) throw new InvalidArgumentException('Invalid replay.');
        } else {
            if ($username !== null) {
                claimDirectoryUsername($conn, $key, $userId, $username);
                $owner = $conn->execute_query('SELECT account_key,user_id FROM platform_login_routes WHERE username=? FOR UPDATE', [$username])->fetch_assoc();
                if ($owner && ($owner['account_key'] !== $key || (int) $owner['user_id'] !== $userId)) throw new InvalidArgumentException('Username unavailable.');
            }
            $conn->execute_query('INSERT INTO platform_login_reservations (operation_id,account_key,user_id,username) VALUES (?,?,?,?)', [$id,$key,$userId,$username]);
        }
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
    finally { $conn->query("SELECT RELEASE_LOCK('moed-login-directory')"); }
}

/** Authoritative commit receipt. Locking reads cannot mistake an in-flight insert for rollback. */
function accountDirectoryReceipt(mysqli $conn, string $id): array {
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $id)) throw new InvalidArgumentException('Invalid operation.');
    $conn->query('SET SESSION innodb_lock_wait_timeout=2');
    $conn->begin_transaction();
    try {
        $row = $conn->execute_query('SELECT user_id, username FROM account_directory_outbox WHERE operation_id=? FOR UPDATE', [$id])->fetch_assoc();
        $conn->commit();
        return ['committed' => $row !== null, 'change' => $row];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

/** No row is released on timeout, network failure, or an ambiguous response. */
function reconcileAccountDirectory(mysqli $conn, array $account): void {
    $key = $account['account_key'];
    $pending = $conn->execute_query('SELECT * FROM platform_login_reservations WHERE account_key=? ORDER BY created_at LIMIT 50', [$key])->fetch_all(MYSQLI_ASSOC);
    foreach ($pending as $reservation) {
        $receipt = platformMemberCall($account, 'directory_receipt', ['operation_id' => $reservation['operation_id']]);
        if (!is_bool($receipt['committed'] ?? null)) throw new RuntimeException('Invalid receipt.');
        if ($receipt['committed'] && ((int) ($receipt['change']['user_id'] ?? 0) !== (int) $reservation['user_id']
            || ($receipt['change']['username'] ?? null) !== $reservation['username'])) throw new RuntimeException('Invalid receipt.');
        $conn->begin_transaction();
        try {
            $locked = $conn->execute_query('SELECT operation_id FROM platform_login_reservations WHERE operation_id=? FOR UPDATE', [$reservation['operation_id']])->fetch_assoc();
            if ($locked && $receipt['committed']) {
                if ($reservation['username'] === null) {
                    $conn->execute_query('DELETE FROM platform_login_routes WHERE account_key=? AND user_id=?', [$key, $reservation['user_id']]);
                    $conn->execute_query('DELETE FROM platform_login_claims WHERE account_key=? AND user_id=?', [$key, $reservation['user_id']]);
                } else {
                    platformRegisterLogin($conn, $key, (int) $reservation['user_id'], $reservation['username']);
                }
            }
            if ($locked && !$receipt['committed']) {
                $conn->execute_query('DELETE c FROM platform_login_claims c LEFT JOIN platform_login_routes r ON r.username=c.username WHERE c.account_key=? AND c.user_id=? AND c.username=? AND r.username IS NULL', [$key,$reservation['user_id'],$reservation['username']]);
            }
            $conn->execute_query('DELETE FROM platform_login_reservations WHERE operation_id=?', [$reservation['operation_id']]);
            $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        // Receipts remain durable: deleting them could make a delayed parallel
        // reconciler interpret a committed change as rollback. IDs are never reused.
    }
    // The private profile is the durable source of truth; versions prevent a
    // delayed response from overwriting a newer name. No cross-Account input.
    $profile = platformMemberCall($account, 'directory_profile', []);
    if (($profile['account_key'] ?? '') !== $key || !is_string($profile['name'] ?? null) || !is_int($profile['version'] ?? null)
        || trim($profile['name']) === '' || mb_strlen($profile['name']) > 160) throw new RuntimeException('Invalid profile.');
    $conn->execute_query('UPDATE platform_accounts SET name=?, profile_version=? WHERE account_key=? AND profile_version<?',
        [$profile['name'], $profile['version'], $key, $profile['version']]);
}

/** Best effort after commit; the operator worker retries any interrupted handoff. */
function flushAccountDirectory(): void {
    if (!accountGatewayEnabled() || accountIsPrimary()) return;
    try { platformCall('reconcile_directory', []); }
    catch (Throwable $error) { applicationLog('warning', 'Account directory synchronization pending'); }
}
