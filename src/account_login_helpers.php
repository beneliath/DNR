<?php
declare(strict_types=1);

require_once __DIR__ . '/account_helpers.php';
require_once __DIR__ . '/account_directory_helpers.php';

// The importer accepts at most 16 MiB. JSON can expand a byte to six bytes;
// allow that plus headers/envelope, keeping other operations capped at 16 KiB.
const ACCOUNT_SERVICE_MAX_BODY_BYTES = 101711872;

function accountGatewayEnabled(): bool {
    return accountsEnabled() && getenv('DNR_ACCOUNT_GATEWAY_ENABLED') === '1';
}

function validateDirectoryUsername(string $username): void {
    if ($username !== trim($username) || $username === '' || mb_strlen($username) > 50
        || str_starts_with(strtolower($username), '_platform_')) {
        throw new InvalidArgumentException('That username is unavailable.');
    }
}

/** The directory is private; responses never identify another Account. */
function platformRegisterLogin(mysqli $conn, string $accountKey, int $userId, string $username): void {
    if ($userId < 1) throw new InvalidArgumentException('Invalid user.');
    validateDirectoryUsername($username);
    claimDirectoryUsername($conn, $accountKey, $userId, $username);
    $reserved = $conn->execute_query('SELECT account_key, user_id FROM platform_login_reservations WHERE username = ? FOR UPDATE', [$username])->fetch_assoc();
    if ($reserved && ($reserved['account_key'] !== $accountKey || (int) $reserved['user_id'] !== $userId)) throw new InvalidArgumentException('That username is unavailable.');
    $owner = $conn->execute_query('SELECT account_key, user_id FROM platform_login_routes WHERE username = ?', [$username])->fetch_assoc();
    if ($owner && ($owner['account_key'] !== $accountKey || (int) $owner['user_id'] !== $userId)) {
        throw new InvalidArgumentException('That username is unavailable.');
    }
    // Never release the existing username until the replacement has passed the
    // global unique constraint. A collision rolls back the Account's user edit.
    $existing = $conn->execute_query('SELECT username FROM platform_login_routes WHERE account_key = ? AND user_id = ?', [$accountKey, $userId])->fetch_assoc();
    try {
        if ($existing) {
            $conn->execute_query('UPDATE platform_login_routes SET username = ? WHERE account_key = ? AND user_id = ?', [$username, $accountKey, $userId]);
        } else {
            $conn->execute_query('INSERT INTO platform_login_routes (username, account_key, user_id) VALUES (?, ?, ?)', [$username, $accountKey, $userId]);
        }
        $conn->execute_query('DELETE FROM platform_login_claims WHERE account_key=? AND user_id=? AND username<>?', [$accountKey,$userId,$username]);
    } catch (mysqli_sql_exception $error) {
        if ((int) $error->getCode() !== 1062) throw $error;
        throw new InvalidArgumentException('That username is unavailable.');
    }
}

function registerAccountLogin(mysqli $conn, int $userId, string $username): void {
    if (!accountGatewayEnabled()) return;
    if (accountIsPrimary()) {
        platformRegisterLogin($conn, currentAccountKey(), $userId, $username);
        return;
    }
    stageAccountLogin($conn, $userId, $username);
}

function removeAccountLogin(mysqli $conn, int $userId): void {
    if (!accountGatewayEnabled()) return;
    if (accountIsPrimary()) {
        $conn->execute_query('DELETE FROM platform_login_routes WHERE account_key = ? AND user_id = ?', [currentAccountKey(), $userId]);
        $conn->execute_query('DELETE FROM platform_login_claims WHERE account_key = ? AND user_id = ?', [currentAccountKey(), $userId]);
    } else {
        stageAccountLogin($conn, $userId, null);
    }
}

/** Only the primary may address a member, using its configured, approved URL. */
function platformMemberCall(array $account, string $operation, array $payload): array {
    if (!accountIsPrimary() || !accountGatewayEnabled()) throw new RuntimeException('Shared sign-in is unavailable.');
    $url = accountPublicUrl($account['public_url']) . '/account_service.php';
    $internal = rtrim((string) getenv('DNR_GATEWAY_INTERNAL_URL'), '/');
    if ($internal !== '') {
        $parts = parse_url($internal);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])
            || !in_array($parts['scheme'] ?? '', applicationRequiresHttps() ? ['https'] : ['http', 'https'], true)) {
            throw new RuntimeException('Invalid gateway configuration.');
        }
        $url = $internal . (parse_url($account['public_url'], PHP_URL_PATH) ?: '') . '/account_service.php';
    }
    $body = json_encode(['operation' => $operation, 'payload' => $payload], JSON_THROW_ON_ERROR);
    if (strlen($body) > ACCOUNT_SERVICE_MAX_BODY_BYTES) throw new RuntimeException('Account message exceeds the delivery limit.');
    $time = (string) time(); $nonce = bin2hex(random_bytes(32));
    $key = \Dnr\Security\ApplicationKey::open($account['api_key_encrypted']);
    $signature = hash_hmac('sha256', "primary\n" . $account['account_key'] . "\n" . $time . "\n" . $nonce . "\n" . $body, $key);
    $curl = curl_init($url);
    curl_setopt_array($curl, accountControlCurlOptions($url));
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => applicationRequiresHttps() ? CURLPROTO_HTTPS : CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-MOED-Time: ' . $time,
            'X-MOED-Nonce: ' . $nonce, 'X-MOED-Signature: ' . $signature]]);
    return accountControlJsonResponse($curl);
}

function platformPasswordSignIn(mysqli $conn, string $username, string $password): ?string {
    if (!accountGatewayEnabled() || !accountIsPrimary()) return null;
    $account = $conn->execute_query("SELECT a.account_key, a.public_url, a.api_key_encrypted, r.user_id
        FROM platform_login_routes r JOIN platform_accounts a ON a.account_key = r.account_key
        WHERE r.username = ? AND a.state = 'ready'", [$username])->fetch_assoc();
    if (!$account) return null;
    try {
        $result = platformMemberCall($account, 'password', ['username' => $username, 'password' => $password,
            'user_id' => (int) $account['user_id']]);
        if (empty($result['ticket']) || !preg_match('/\A[a-f0-9]{64}\z/D', $result['ticket'])) return null;
        return accountPublicUrl($account['public_url']) . '/account_login.php?ticket=' . rawurlencode($result['ticket']);
    } catch (Throwable $error) {
        // The sign-in form must never expose directory or Account availability.
        return null;
    }
}
