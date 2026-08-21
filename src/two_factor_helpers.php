<?php

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

    throw new RuntimeException('Application dependencies are unavailable. Rebuild the DNR container.');
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
    return $role === 'admin';
}

function twoFactorEncryptionKey() {
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $key_file = getenv('DNR_2FA_ENCRYPTION_KEY_FILE');
    $encoded = null;

    if (is_string($key_file) && $key_file !== '' && is_readable($key_file)) {
        $encoded = trim((string) file_get_contents($key_file));
    }

    if (!$encoded) {
        $encoded = getenv('DNR_2FA_ENCRYPTION_KEY');
    }
    $decoded = is_string($encoded) && $encoded !== '' ? base64_decode($encoded, true) : false;

    if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException(
            'The DNR 2FA encryption key must be a base64-encoded 32-byte key.'
        );
    }

    $key = $decoded;
    return $key;
}

function encryptTwoFactorSecret($secret) {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($secret, $nonce, twoFactorEncryptionKey());
    return base64_encode($nonce . $ciphertext);
}

function decryptTwoFactorSecret($encrypted) {
    $payload = base64_decode((string) $encrypted, true);

    if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('The stored two-factor secret is invalid.');
    }

    $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $secret = sodium_crypto_secretbox_open($ciphertext, $nonce, twoFactorEncryptionKey());

    if (!is_string($secret)) {
        throw new RuntimeException('The stored two-factor secret could not be decrypted.');
    }

    return $secret;
}

function createTotp($secret, $username) {
    return \OTPHP\TOTP::createFromSecret($secret, new DnrSystemClock())
        ->withLabel((string) $username)
        ->withIssuer('DNR');
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
        "SELECT id, username, password, must_change_password, role, auth_version, two_factor_enabled,
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
        "SELECT id, username, password, must_change_password, role, auth_version, two_factor_enabled,
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
        'INSERT INTO user_recovery_codes (user_id, code_lookup_hash) VALUES (?, ?)'
    );

    foreach ($codes as $code) {
        $hash = recoveryCodeLookupHash($code);
        $insert->bind_param('is', $user_id, $hash);
        $insert->execute();
    }
}

function enableTwoFactorForUser(mysqli $conn, $user_id, $secret, $first_step) {
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
             WHERE id = ?'
        );
        $stmt->bind_param('sii', $encrypted, $first_step, $user_id);
        $stmt->execute();
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
    $lookup_hash = recoveryCodeLookupHash($code);
    if ($lookup_hash === null) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT id FROM user_recovery_codes
         WHERE user_id = ? AND code_lookup_hash = ? AND used_at IS NULL
         LIMIT 1'
    );
    $stmt->bind_param('is', $user_id, $lookup_hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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
    $elevated_at = $_SESSION['_admin_elevated_at'] ?? null;
    return is_int($elevated_at) && (time() - $elevated_at) <= $maximum_age_seconds;
}

function attemptAdminElevation(mysqli $conn, $password, $code) {
    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $user = $user_id > 0 ? fetchAuthenticationUserById($conn, $user_id) : null;
    if (!$user || $user['role'] !== 'admin') {
        return false;
    }

    if (!empty($user['login_is_locked'])
        || !password_verify((string) $password, (string) $user['password'])) {
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
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    logSecurityEvent($conn, 'admin_elevation_succeeded', $user_id, $user_id);
    return true;
}

function safeAdminElevationReturnUrl($return_url) {
    $return_url = is_scalar($return_url) ? trim((string) $return_url) : '';
    if ($return_url === ''
        || strlen($return_url) > 512
        || str_contains($return_url, '..')
        || str_contains($return_url, '//')
        || str_contains($return_url, '\\')
        || preg_match('/[\x00-\x1F\x7F]/', $return_url)
        || !preg_match('/\A[A-Za-z0-9_\/-]+\.php(?:\?[^#]*)?(?:#.*)?\z/', $return_url)
    ) {
        return 'users.php';
    }
    return $return_url;
}

function requireRecentAdminElevation($return_url = 'users.php') {
    if (hasRecentAdminElevation()) {
        return;
    }
    $_SESSION['_admin_elevation_error'] = 'Confirm your password and a fresh authentication code before using sensitive administrator actions.';
    header('Location: admin_elevation.php?' . http_build_query([
        'return' => safeAdminElevationReturnUrl($return_url),
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
