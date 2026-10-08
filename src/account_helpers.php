<?php
declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

function accountsEnabled(): bool {
    return getenv('DNR_ACCOUNTS_ENABLED') === '1';
}

/** Enable only after the shared-mail schema and worker grants are installed. */
function accountMailEnabled(): bool {
    return accountsEnabled() && getenv('DNR_ACCOUNT_MAIL_ENABLED') === '1';
}

function accountIsPrimary(): bool {
    return accountsEnabled() && (getenv('DNR_ACCOUNT_MODE') ?: 'primary') === 'primary';
}

function currentAccountKey(): string {
    $key = (string) (getenv('DNR_ACCOUNT_KEY') ?: 'shalom-in-messiah');
    if (!preg_match('/\A[a-z][a-z0-9-]{2,63}\z/D', $key)) {
        throw new RuntimeException('Invalid Account configuration.');
    }
    return $key;
}

/** No request parameter, cookie or user ID can choose the database. */
function currentAccountProfile(): array {
    static $cached = null;
    static $loadedAt = 0;
    if ($cached !== null && time() - $loadedAt < 10) return $cached;
    if (!accountsEnabled()) return ['account_key' => '', 'name' => '', 'settings' => [], 'version' => 0];
    $row = applicationDatabaseConnection()->query('SELECT * FROM account_profile WHERE id = 1')->fetch_assoc();
    if (!$row || !hash_equals(currentAccountKey(), (string) $row['account_key'])) {
        throw new RuntimeException('This deployment does not match its Account database.');
    }
    $row['settings'] = json_decode($row['settings'], true, 32, JSON_THROW_ON_ERROR);
    $loadedAt = time();
    return $cached = $row;
}

/** Identity eligibility survives a restricted preview; never use this for page access. */
function authenticatedSuperAdmin(): bool {
    return accountsEnabled() && !empty($_SESSION['is_superadmin'])
        && ($_SESSION['auth_complete'] ?? false) === true
        && ($_SESSION['authenticated_role'] ?? $_SESSION['role'] ?? '') === 'admin';
}

function isSuperAdmin(): bool {
    return authenticatedSuperAdmin()
        && !isset($_SESSION['_role_preview'])
        && ($_SESSION['role'] ?? '') === 'admin';
}

function requireSuperAdmin(): void {
    requireLogin();
    if (!isSuperAdmin()) { http_response_code(403); exit('Forbidden.'); }
}

function accountRoleLabel(array $user): string {
    return !empty($user['is_superadmin']) || !empty($user['platform_identity_id'])
        ? 'SuperAdmin' : (($user['role'] ?? '') === 'admin' ? 'Account Admin' : ucfirst($user['role'] ?? ''));
}

/** Account admins may never take over a platform identity by resetting it. */
function requireManageableAccountUser(mysqli $conn, int $id): void {
    if (!accountsEnabled()) return;
    $user = $conn->execute_query('SELECT is_superadmin, platform_identity_id FROM users WHERE id = ?', [$id])->fetch_assoc();
    if ($user && (!empty($user['is_superadmin']) || !empty($user['platform_identity_id']))) {
        throw new InvalidArgumentException('Manage this SuperAdmin identity through its own profile in the primary Account.');
    }
}

function accountPublicUrl(string $url): string {
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);
    $local = !applicationRequiresHttps() && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);
    if (!is_array($parts) || !isset($parts['host']) || isset($parts['user'], $parts['pass'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (!in_array($parts['path'] ?? '', ['', '/'], true)
            && !preg_match('~\A/a/[a-z][a-z0-9-]{2,63}\z~D', $parts['path'] ?? ''))
        || (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local))
        || preg_match('/[\x00-\x20\x7f]/', $url)) {
        throw new InvalidArgumentException('Account addresses must use HTTPS (localhost HTTP is allowed in development).');
    }
    return $url;
}

function accountPrimaryPublicUrl(): string {
    return accountPublicUrl((string) (getenv('DNR_PRIMARY_PUBLIC_URL') ?: getenv('DNR_PUBLIC_BASE_URL')));
}

function accountSignInUrl(): string {
    $url = accountPrimaryPublicUrl();
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
    return ($path === '' ? $url : substr($url, 0, -strlen($path))) . '/login.php';
}

function accountPublicPath(): string {
    if (!accountsEnabled()) return '';
    $path = rtrim((string) (parse_url((string) getenv('DNR_PUBLIC_BASE_URL'), PHP_URL_PATH) ?: ''), '/');
    if ($path !== '' && $path !== '/a/' . currentAccountKey()) throw new RuntimeException('Invalid Account path.');
    return $path;
}

/** Primary authentication includes the common sign-in; member cookies stay local. */
function accountCookiePath(bool $authentication = false): string {
    return !accountsEnabled() || ($authentication && accountIsPrimary()) ? '/' : accountPublicPath() . '/';
}

/** Restricted tunnel; TLS verification still authenticates the public origin. */
function accountControlCurlOptions(string $url): array {
    $proxy = (string) getenv('DNR_ACCOUNT_CONTROL_PROXY');
    if ($proxy === '') return [];
    $origin = parse_url(accountPrimaryPublicUrl());
    $target = parse_url($url);
    if ($proxy !== 'http://account-control:8080' || !is_array($target) || !is_array($origin)
        || ($target['scheme'] ?? '') !== 'https' || ($origin['scheme'] ?? '') !== 'https'
        || ($target['host'] ?? '') !== ($origin['host'] ?? '')
        || ($target['port'] ?? 443) !== ($origin['port'] ?? 443)) {
        throw new RuntimeException('Invalid Account control transport.');
    }
    return [CURLOPT_PROXY => $proxy, CURLOPT_HTTPPROXYTUNNEL => true, CURLOPT_NOPROXY => '',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
}

/** Abort during transfer, before an untrusted peer can buffer an oversized reply. */
function accountControlJsonResponse(CurlHandle $curl, int $depth = 16): array {
    $response = '';
    $limit = 131072;
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$response, $limit): int {
            if (strlen($chunk) > $limit - strlen($response)) return 0;
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    $complete = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($complete !== true || $status !== 200) {
        throw new RuntimeException('Account control response unavailable (HTTP ' . $status . ', transport ' . curl_errno($curl) . ').');
    }
    $data = json_decode($response, true, $depth, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid Account response.');
    return $data;
}

/** Bounded authenticated calls to the primary; never forward browser cookies. */
function platformCall(string $operation, array $payload = []): array {
    if (!accountsEnabled() || accountIsPrimary()) throw new RuntimeException('Member Account required.');
    $url = rtrim((string) getenv('DNR_PRIMARY_INTERNAL_URL'), '/') . '/platform_api.php';
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || !in_array($parts['scheme'] ?? '', applicationRequiresHttps() ? ['https'] : ['https', 'http'], true)) {
        throw new RuntimeException('The primary Account connection is unavailable.');
    }
    $key = configurationSecret('DNR_PLATFORM_API_KEY');
    if (strlen($key) < 64) throw new RuntimeException('Account connection credentials are unavailable.');
    $body = json_encode(['operation' => $operation, 'payload' => $payload], JSON_THROW_ON_ERROR);
    $timestamp = (string) time(); $nonce = bin2hex(random_bytes(32));
    $signature = hash_hmac('sha256', currentAccountKey() . "\n" . $timestamp . "\n" . $nonce . "\n" . $body, $key);
    $curl = curl_init($url);
    curl_setopt_array($curl, accountControlCurlOptions($url));
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-MOED-Account: ' . currentAccountKey(),
            'X-MOED-Time: ' . $timestamp, 'X-MOED-Nonce: ' . $nonce, 'X-MOED-Signature: ' . $signature]]);
    return accountControlJsonResponse($curl, 32);
}

function platformIdentityPayload(): array {
    return is_array($_SESSION['_platform_identity'] ?? null) ? $_SESSION['_platform_identity'] : [];
}

/** Issued only after consuming a primary-authorized, single-use sign-in ticket. */
function platformIssueIdentityGrant(string $accountKey, array $identity, ?int $now = null): string {
    return \Dnr\Security\ApplicationKey::seal(json_encode([
        'purpose' => 'platform-member-session', 'account_key' => $accountKey,
        'id' => (int) $identity['id'], 'auth_version' => (int) $identity['auth_version'],
        'expires_at' => ($now ?? time()) + 900,
    ], JSON_THROW_ON_ERROR));
}

function platformVerifyIdentityGrant(string $token, string $accountKey, array $identity, ?int $now = null): void {
    $grant = json_decode(\Dnr\Security\ApplicationKey::open($token), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($grant) || ($grant['purpose'] ?? '') !== 'platform-member-session'
        || ($grant['account_key'] ?? '') !== $accountKey
        || ($grant['id'] ?? null) !== (int) $identity['id']
        || ($grant['auth_version'] ?? null) !== (int) $identity['auth_version']
        || !is_int($grant['expires_at'] ?? null) || $grant['expires_at'] <= ($now ?? time())) {
        throw new RuntimeException('Return to Accounts and open this Account again.');
    }
}

/** A member can display this form, but only the primary browser session can use it. */
function platformIssueSwitchIntent(array $identity, string $target, ?int $now = null): string {
    return \Dnr\Security\ApplicationKey::seal(json_encode([
        'purpose' => 'platform-browser-switch', 'account_key' => $target,
        'id' => (int) $identity['id'], 'auth_version' => (int) $identity['auth_version'],
        'expires_at' => ($now ?? time()) + 900,
    ], JSON_THROW_ON_ERROR));
}

function platformVerifySwitchIntent(string $token, array $identity, string $target, ?int $now = null): void {
    $intent = json_decode(\Dnr\Security\ApplicationKey::open($token), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($intent) || ($intent['purpose'] ?? '') !== 'platform-browser-switch'
        || ($intent['account_key'] ?? '') !== $target
        || ($intent['id'] ?? null) !== (int) $identity['id']
        || ($intent['auth_version'] ?? null) !== (int) $identity['auth_version']
        || !is_int($intent['expires_at'] ?? null) || $intent['expires_at'] <= ($now ?? time())) {
        throw new RuntimeException('Account switch authorization expired.');
    }
}

function platformEligibleIdentity(mysqli $conn, int $id, int $version): array {
    $user = $conn->execute_query('SELECT id, username, role, is_superadmin, account_status, auth_version,
        two_factor_enabled, must_change_password, first_name, last_name FROM users WHERE id = ?', [$id])->fetch_assoc();
    if (!$user || !$user['is_superadmin'] || $user['role'] !== 'admin' || $user['account_status'] !== 'active'
        || !$user['two_factor_enabled'] || $user['must_change_password'] || (int) $user['auth_version'] !== $version) {
        throw new RuntimeException('SuperAdmin access could not be verified.');
    }
    return $user;
}

/** A stable directory order, independent of the Account being viewed. */
function platformAccountDirectory(mysqli $conn): array {
    $primary = ['account_key' => currentAccountKey(), 'name' => currentAccountProfile()['name'],
        'public_url' => accountPrimaryPublicUrl(), 'state' => 'ready', 'is_primary' => true];
    return [$primary, ...$conn->query("SELECT account_key, name, public_url, state, lifecycle_error
        FROM platform_accounts WHERE state <> 'deleted' ORDER BY name")->fetch_all(MYSQLI_ASSOC)];
}

/** Only the primary can seal this proof of password + fresh MFA verification. */
function platformIssueElevationGrant(string $accountKey, array $identity, int $expiresAt): array {
    return ['expires_at' => $expiresAt, 'token' => \Dnr\Security\ApplicationKey::seal(json_encode([
        'purpose' => 'platform-admin-elevation', 'account_key' => $accountKey,
        'id' => (int) $identity['id'], 'auth_version' => (int) $identity['auth_version'],
        'expires_at' => $expiresAt,
    ], JSON_THROW_ON_ERROR))];
}

function platformVerifyElevationGrant(string $token, string $accountKey, array $identity, ?int $now = null): int {
    $grant = json_decode(\Dnr\Security\ApplicationKey::open($token), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($grant) || ($grant['purpose'] ?? '') !== 'platform-admin-elevation'
        || ($grant['account_key'] ?? '') !== $accountKey
        || ($grant['id'] ?? null) !== (int) $identity['id']
        || ($grant['auth_version'] ?? null) !== (int) $identity['auth_version']
        || !is_int($grant['expires_at'] ?? null) || $grant['expires_at'] <= ($now ?? time())) {
        throw new RuntimeException('Fresh administrator verification is required.');
    }
    return $grant['expires_at'];
}

function platformIssueTicket(mysqli $conn, array $identity, string $accountKey): string {
    $account = $conn->execute_query("SELECT public_url FROM platform_accounts WHERE account_key = ? AND state = 'ready'", [$accountKey])->fetch_assoc();
    if (!$account) throw new InvalidArgumentException('This Account is not available.');
    $token = bin2hex(random_bytes(32));
    $conn->execute_query('DELETE FROM platform_access_tickets WHERE expires_at < UTC_TIMESTAMP()');
    $conn->execute_query('INSERT INTO platform_access_tickets (token_hash, account_key, user_id, auth_version, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND))',
        [hash('sha256', $token), $accountKey, $identity['id'], $identity['auth_version']]);
    logSecurityEvent($conn, 'superadmin_account_switch', (int) $identity['id'], (int) $identity['id']);
    return accountPublicUrl($account['public_url']) . '/account_signin.php?ticket=' . $token;
}

function platformCreateAccount(mysqli $conn, string $name, string $key, int $actor): void {
    $name = trim($name); $key = strtolower(trim($key));
    if ($name === '' || mb_strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/', $name)
        || !preg_match('/\A[a-z][a-z0-9-]{2,63}\z/D', $key) || $key === currentAccountKey()) {
        throw new InvalidArgumentException('Enter an Account name and a unique address label of 3–64 lowercase letters, numbers or hyphens.');
    }
    $conn->execute_query('INSERT INTO platform_accounts (account_key, name, api_key_encrypted, requested_by) VALUES (?, ?, ?, ?)',
        [$key, $name, \Dnr\Security\ApplicationKey::seal(bin2hex(random_bytes(32))), $actor]);
    logSecurityEvent($conn, 'platform_account_requested', $actor, $actor);
}
