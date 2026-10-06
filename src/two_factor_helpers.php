<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

function loadDnrComposerAutoloader() {
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $paths = [
        dirname(__DIR__) . '/vendor/autoload.php',
        '/opt/dnr/vendor/autoload.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            $loaded = true;
            return;
        }
    }

    throw new RuntimeException('Application dependencies are unavailable. Rebuild the application container.');
}

loadDnrComposerAutoloader();

final class DnrSystemClock implements \Psr\Clock\ClockInterface {
    public function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

function twoFactorSchemaAvailable(mysqli $conn) {
    return true;
}

function requireTwoFactorSchema(mysqli $conn) {
    // Schema readiness is verified by deployment health checks.
}

function twoFactorRequiredForRole($role) {
    // Keep a single policy for existing and future account types.
    return true;
}

function twoFactorEncryptionKey() {
    return \Dnr\Security\ApplicationKey::bytes();
}

function encryptTwoFactorSecret($secret) {
    return \Dnr\Security\ApplicationKey::seal((string) $secret);
}

function decryptTwoFactorSecret($encrypted) {
    return \Dnr\Security\ApplicationKey::open((string) $encrypted);
}

function createTotp($secret, $username) {
    return \OTPHP\TOTP::createFromSecret($secret, new DnrSystemClock())
        ->withLabel((string) $username)
        ->withIssuer(deploymentConfig()->string('brand.totp_issuer'));
}

function generateTotpSecret() {
    return \OTPHP\TOTP::generate(new DnrSystemClock(), 20)->getSecret();
}

function createTotpQrDataUri($secret, $username) {
    $totp = createTotp($secret, $username);
    $builder = new \Endroid\QrCode\Builder\Builder(
        writer: new \Endroid\QrCode\Writer\SvgWriter(),
        writerOptions: [
            \Endroid\QrCode\Writer\SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
        ],
        data: $totp->getProvisioningUri(),
        size: 280,
        margin: 12,
    );

    return $builder->build()->getDataUri();
}

function normalizeTotpCode($code) {
    return preg_replace('/\D+/', '', (string) $code);
}

function matchingTotpStep($secret, $username, $code, $last_used_step = null, $timestamp = null) {
    $normalized = normalizeTotpCode($code);

    if (strlen($normalized) !== 6) {
        return null;
    }

    $timestamp = $timestamp ?? time();
    $period = 30;
    $current_step = intdiv((int) $timestamp, $period);
    $totp = createTotp($secret, $username);

    foreach ([$current_step - 1, $current_step, $current_step + 1] as $step) {
        if ($step < 0 || ($last_used_step !== null && $step <= (int) $last_used_step)) {
            continue;
        }

        if (hash_equals($totp->at($step * $period), $normalized)) {
            return $step;
        }
    }

    return null;
}

function fetchAuthenticationUserByUsername(mysqli $conn, $username) {
    $stmt = $conn->prepare(
        "SELECT id, username, password, must_change_password, role, account_status, auth_version, two_factor_enabled,
                totp_secret_encrypted, totp_confirmed_at, totp_last_used_step,
                (login_locked_until IS NOT NULL AND login_locked_until > UTC_TIMESTAMP()) AS login_is_locked,
                (two_factor_locked_until IS NOT NULL AND two_factor_locked_until > UTC_TIMESTAMP()) AS two_factor_is_locked
         FROM users
         WHERE username = ?"
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows === 1 ? $result->fetch_assoc() : null;
}

function fetchAuthenticationUserById(mysqli $conn, $user_id) {
    $stmt = $conn->prepare(
        "SELECT id, username, password, must_change_password, role, account_status, auth_version, two_factor_enabled,
                totp_secret_encrypted, totp_confirmed_at, totp_last_used_step,
                (login_locked_until IS NOT NULL AND login_locked_until > UTC_TIMESTAMP()) AS login_is_locked,
                (two_factor_locked_until IS NOT NULL AND two_factor_locked_until > UTC_TIMESTAMP()) AS two_factor_is_locked
         FROM users
         WHERE id = ?"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows === 1 ? $result->fetch_assoc() : null;
}

function passwordAuthenticationIsAccepted(?array $user, bool $password_valid): bool {
    // A valid second factor lets the real owner recover from an attacker-
    // induced password lock without turning the account threshold into a
    // distributed password-guessing bypass. Accounts without 2FA remain
    // locked and can use the verified-email password-recovery flow.
    return $user !== null
        && ($user['account_status'] ?? null) === 'active'
        && $password_valid
        && (empty($user['login_is_locked']) || !empty($user['two_factor_enabled']));
}

function recordAuthenticationFailure(mysqli $conn, $user_id, $factor) {
    $columns = [
        'password' => ['login_failed_attempts', 'login_locked_until'],
        'two_factor' => ['two_factor_failed_attempts', 'two_factor_locked_until'],
    ];

    if (!isset($columns[$factor])) {
        throw new InvalidArgumentException('Unknown authentication factor.');
    }

    [$attempts_column, $locked_column] = $columns[$factor];
    $sql = "UPDATE users
            SET {$locked_column} = CASE
                    WHEN {$attempts_column} >= 4 THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
                    ELSE {$locked_column}
                END,
                {$attempts_column} = CASE
                    WHEN {$attempts_column} >= 4 THEN 0
                    ELSE {$attempts_column} + 1
                END
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

function resetAuthenticationFailures(mysqli $conn, $user_id, $factor) {
    $columns = [
        'password' => ['login_failed_attempts', 'login_locked_until'],
        'two_factor' => ['two_factor_failed_attempts', 'two_factor_locked_until'],
    ];

    if (!isset($columns[$factor])) {
        throw new InvalidArgumentException('Unknown authentication factor.');
    }

    [$attempts_column, $locked_column] = $columns[$factor];
    $stmt = $conn->prepare(
        "UPDATE users SET {$attempts_column} = 0, {$locked_column} = NULL WHERE id = ?"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
}

function verifyAndConsumeTotp(mysqli $conn, array $user, $code) {
    if (empty($user['two_factor_enabled']) || empty($user['totp_secret_encrypted'])) {
        return false;
    }

    $secret = decryptTwoFactorSecret($user['totp_secret_encrypted']);
    $step = matchingTotpStep(
        $secret,
        $user['username'],
        $code,
        $user['totp_last_used_step'] === null ? null : (int) $user['totp_last_used_step']
    );

    if ($step === null) {
        return false;
    }

    $user_id = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE users
         SET totp_last_used_step = ?, two_factor_failed_attempts = 0, two_factor_locked_until = NULL
         WHERE id = ? AND (totp_last_used_step IS NULL OR totp_last_used_step < ?)'
    );
    $stmt->bind_param('iii', $step, $user_id, $step);
    $stmt->execute();
    return $stmt->affected_rows === 1;
}

function generateRecoveryCodes($count = 10) {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $codes = [];

    for ($code_index = 0; $code_index < $count; $code_index++) {
        $raw = '';
        for ($character_index = 0; $character_index < 12; $character_index++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = implode('-', str_split($raw, 4));
    }

    return $codes;
}

function normalizeRecoveryCode($code) {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $code));
}

function recoveryCodeLookupHash($code) {
    $normalized = normalizeRecoveryCode($code);
    if (strlen($normalized) !== 12) {
        return null;
    }
    return hash_hmac(
        'sha256',
        "dnr-recovery-code-v1\0" . $normalized,
        twoFactorEncryptionKey(),
        true
    );
}

function replaceRecoveryCodes(mysqli $conn, $user_id, array $codes) {
    $delete = $conn->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?');
    $delete->bind_param('i', $user_id);
    $delete->execute();

    $insert = $conn->prepare(
        'INSERT INTO user_recovery_codes (user_id, code_lookup_hash, key_id) VALUES (?, ?, ?)'
    );

    foreach ($codes as $code) {
        $hash = recoveryCodeLookupHash($code);
        $key_id = \Dnr\Security\ApplicationKey::activeId();
        $insert->bind_param('iss', $user_id, $hash, $key_id);
        $insert->execute();
    }
}

function enableTwoFactorForUser(mysqli $conn, $user_id, $secret, $first_step, int $expected_auth_version) {
    $encrypted = encryptTwoFactorSecret($secret);
    $codes = generateRecoveryCodes();
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            'UPDATE users
             SET two_factor_enabled = 1,
                 totp_secret_encrypted = ?,
                 totp_confirmed_at = UTC_TIMESTAMP(),
                 totp_last_used_step = ?,
                 auth_version = auth_version + 1,
                 two_factor_failed_attempts = 0,
                 two_factor_locked_until = NULL
             WHERE id = ? AND auth_version = ? AND account_status = \'active\''
        );
        $stmt->bind_param('siii', $encrypted, $first_step, $user_id, $expected_auth_version);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Authentication changed after enrollment began.');
        }
        $stmt->close();
        replaceRecoveryCodes($conn, $user_id, $codes);
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return $codes;
}

function disableTwoFactorForUser(mysqli $conn, $user_id) {
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            'UPDATE users
             SET two_factor_enabled = 0,
                 totp_secret_encrypted = NULL,
                 totp_confirmed_at = NULL,
                 totp_last_used_step = NULL,
                 auth_version = auth_version + 1,
                 two_factor_failed_attempts = 0,
                 two_factor_locked_until = NULL
             WHERE id = ?'
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();

        $delete = $conn->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?');
        $delete->bind_param('i', $user_id);
        $delete->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function consumeRecoveryCode(mysqli $conn, $user_id, $code) {
    $normalized = normalizeRecoveryCode($code);
    if (strlen($normalized) !== 12) return false;
    $hashes = \Dnr\Security\ApplicationKey::lookupHashes("dnr-recovery-code-v1\0" . $normalized);
    $row = null;
    foreach ($hashes as $key_id => $lookup_hash) {
        $legacy_id = \Dnr\Security\ApplicationKey::legacyId();
        $row = $conn->execute_query('SELECT id FROM user_recovery_codes
            WHERE user_id = ? AND code_lookup_hash = ? AND used_at IS NULL
              AND (key_id = ? OR (key_id IS NULL AND ? = ?)) LIMIT 1',
            [$user_id, $lookup_hash, $key_id, $key_id, $legacy_id])->fetch_assoc();
        if ($row) break;
    }
    if (!$row) {
        return false;
    }

    $code_id = (int) $row['id'];
    $consume = $conn->prepare(
        'UPDATE user_recovery_codes SET used_at = UTC_TIMESTAMP()
         WHERE id = ? AND used_at IS NULL'
    );
    $consume->bind_param('i', $code_id);
    $consume->execute();
    $accepted = $consume->affected_rows === 1;
    $consume->close();
    return $accepted;
}

function countUnusedRecoveryCodes(mysqli $conn, $user_id) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS code_count FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL'
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['code_count'];
}

function hasRecentAdminElevation($maximum_age_seconds = 300) {
    return adminElevationExpiresAt($maximum_age_seconds) !== null;
}

function adminElevationExpiresAt($maximum_age_seconds = 300, ?int $now = null): ?int {
    $now ??= time();
    $elevated_at = $_SESSION['_admin_elevated_at'] ?? null;
    $expires_at = $elevated_at;
    if (is_int($elevated_at)) {
        $expires_at = $elevated_at + $maximum_age_seconds;
        $extended = $_SESSION['_admin_elevation_expires_at'] ?? null;
        if ($maximum_age_seconds === 300 && is_int($extended)) $expires_at = max($expires_at, $extended);
    }
    if (!is_int($elevated_at) || $elevated_at > $now || $now >= $expires_at) {
        return null;
    }
    return $expires_at;
}

function extendAdminElevation(?int $now = null): ?int {
    $expires_at = adminElevationExpiresAt(300, $now);
    if ($expires_at === null) return null;
    $_SESSION['_admin_elevation_expires_at'] = $expires_at + 300;
    return $_SESSION['_admin_elevation_expires_at'];
}

function attemptAdminElevation(mysqli $conn, $password, $code) {
    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $user = $user_id > 0 ? fetchAuthenticationUserById($conn, $user_id) : null;
    if (!$user || $user['role'] !== 'admin') {
        return false;
    }

    if (!\Dnr\Security\PasswordPolicy::verify($password, $user['password'])) {
        if (empty($user['login_is_locked'])) {
            recordAuthenticationFailure($conn, $user_id, 'password');
        }
        logSecurityEvent($conn, 'admin_elevation_failed', $user_id, $user_id);
        return false;
    }
    if (empty($user['two_factor_enabled']) || !empty($user['two_factor_is_locked'])) {
        logSecurityEvent($conn, 'admin_elevation_failed', $user_id, $user_id);
        return false;
    }

    resetAuthenticationFailures($conn, $user_id, 'password');
    $submitted_code = trim((string) $code);
    $is_totp = preg_match('/^[0-9]{6}$/', $submitted_code) === 1;
    $verified = $is_totp
        ? verifyAndConsumeTotp($conn, $user, $submitted_code)
        : consumeRecoveryCode($conn, $user_id, $submitted_code);
    if (!$verified) {
        recordAuthenticationFailure($conn, $user_id, 'two_factor');
        logSecurityEvent($conn, 'admin_elevation_failed', $user_id, $user_id);
        return false;
    }

    resetAuthenticationFailures($conn, $user_id, 'two_factor');
    session_regenerate_id(true);
    $_SESSION['_admin_elevated_at'] = time();
    unset($_SESSION['_admin_elevation_expires_at']);
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    logSecurityEvent($conn, 'admin_elevation_succeeded', $user_id, $user_id);
    return true;
}

function safeAdminElevationReturnUrl($return_url, $fallback = 'users.php') {
    $return_url = is_scalar($return_url) ? trim((string) $return_url) : '';
    $parts = parse_url($return_url);
    if ($return_url === ''
        || strlen($return_url) > 6000
        || preg_match('/[\x00-\x1F\x7F]/', $return_url)
        || $parts === false || isset($parts['scheme']) || isset($parts['host'])
        || !preg_match('/\A[A-Za-z0-9_-]+\.php\z/', $parts['path'] ?? '')
        || in_array($parts['path'], ['admin_lock.php', 'admin_extend.php', 'delete_user.php',
            'reset_user_2fa.php', 'user_lifecycle.php', 'logout.php'], true)
    ) {
        return $fallback;
    }
    if ($parts['path'] === 'admin_elevation.php') return $fallback;
    return $return_url;
}

/** Preserve the originating screen even when a POST uses a separate action endpoint. */
function adminElevationRequestReturnUrl($return_url) {
    $fallback = safeAdminElevationReturnUrl($return_url);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $explicit = safeAdminElevationReturnUrl($_POST['_admin_unlock_return'] ?? null, '');
        if ($explicit !== '') return $explicit;

        $referrer = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $origin = parse_url((requestUsesHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
        $directory = dirname((string) ($_SERVER['PHP_SELF'] ?? '/'));
        if (is_array($referrer) && is_array($origin)
            && ($referrer['scheme'] ?? '') === ($origin['scheme'] ?? '')
            && strcasecmp($referrer['host'] ?? '', $origin['host'] ?? '') === 0
            && ($referrer['port'] ?? (($referrer['scheme'] ?? '') === 'https' ? 443 : 80))
                === ($origin['port'] ?? (($origin['scheme'] ?? '') === 'https' ? 443 : 80))
            && dirname($referrer['path'] ?? '') === $directory
        ) {
            $source = safeAdminElevationReturnUrl(basename($referrer['path'])
                . (isset($referrer['query']) ? '?' . $referrer['query'] : '')
                . (isset($referrer['fragment']) ? '#' . $referrer['fragment'] : ''), '');
            if ($source !== '') {
                // HTTP referrers omit fragments; the route can still identify the action's section.
                $fragment = parse_url($fallback, PHP_URL_FRAGMENT);
                if ($fragment !== null && !str_contains($source, '#')
                    && parse_url($source, PHP_URL_PATH) === parse_url($fallback, PHP_URL_PATH)
                ) $source .= '#' . $fragment;
                return $source;
            }
        }
    }
    if (basename((string) ($_SERVER['PHP_SELF'] ?? '')) === parse_url($fallback, PHP_URL_PATH)
        && !empty($_SERVER['QUERY_STRING'])
    ) {
        $current = basename($_SERVER['PHP_SELF']) . '?' . $_SERVER['QUERY_STRING'];
        $fragment = parse_url($fallback, PHP_URL_FRAGMENT);
        if ($fragment !== null) $current .= '#' . $fragment;
        return safeAdminElevationReturnUrl($current, $fallback);
    }
    return $fallback;
}

function requireRecentAdminElevation($return_url = 'users.php') {
    if (hasRecentAdminElevation()) {
        return;
    }
    $_SESSION['_admin_elevation_error'] = 'Confirm your password and a fresh authentication code before using sensitive administrator actions.';
    header('Location: admin_elevation.php?' . http_build_query([
        'return' => adminElevationRequestReturnUrl($return_url),
    ]));
    exit();
}

function logSecurityEvent(mysqli $conn, $event_type, $target_user_id = null, $actor_user_id = null) {
    return recordAuditEvent($conn, [
        'event_category' => 'security',
        'event_type' => $event_type,
        'target_user_id' => $target_user_id,
        'actor_user_id' => $actor_user_id,
    ]);
}

?>
