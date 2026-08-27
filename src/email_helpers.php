<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

function normalizeAccountEmail($email)
{
    $email = strtolower(trim((string) $email));
    if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return $email;
}

function userEmailTokenHash($token)
{
    if (!is_string($token) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
        return null;
    }
    return hash('sha256', $token, true);
}

function userEmailTokenLifetime($purpose)
{
    return match ((string) $purpose) {
        'invitation' => 7 * 24 * 60 * 60,
        'verification' => 24 * 60 * 60,
        'recovery' => 60 * 60,
        default => throw new InvalidArgumentException('Invalid email-token purpose.'),
    };
}

/** @return array{token: string, id: int, expires_at: string, queued: bool} */
function issueUserEmailToken(
    mysqli $conn,
    $user_id,
    $purpose,
    $email,
    $created_by = null,
    $lifetime_seconds = null,
    bool $caller_manages_transaction = false
) {
    $user_id = (int) $user_id;
    $created_by = $created_by === null ? null : (int) $created_by;
    $purpose = (string) $purpose;
    $email = normalizeAccountEmail($email);
    $lifetime_seconds = $lifetime_seconds === null
        ? userEmailTokenLifetime($purpose)
        : max(300, (int) $lifetime_seconds);
    if ($user_id < 1) {
        throw new InvalidArgumentException('An email-token owner is required.');
    }

    $transport = accountMailTransport();
    $owns_transaction = !$caller_manages_transaction;
    if ($owns_transaction) {
        $conn->begin_transaction();
    }

    try {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token_hash = userEmailTokenHash($token);
        if ($token_hash === null) {
            throw new RuntimeException('Unable to create an email token.');
        }
        $expires_at = gmdate('Y-m-d H:i:s', time() + $lifetime_seconds);

        $version_stmt = $conn->prepare(
            'SELECT auth_version, username FROM users WHERE id = ? FOR UPDATE'
        );
        if (!$version_stmt) {
            throw new RuntimeException('Unable to prepare the email-token owner lookup.');
        }
        $version_stmt->bind_param('i', $user_id);
        $version_stmt->execute();
        $owner = $version_stmt->get_result()->fetch_assoc();
        $version_stmt->close();
        if (!$owner) {
            throw new InvalidArgumentException('The email-token owner is unavailable.');
        }
        $auth_version = (int) $owner['auth_version'];

        $stmt = $conn->prepare(
            'INSERT INTO user_email_tokens
                (user_id, purpose, email, auth_version, token_hash, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare an email token.');
        }
        $stmt->bind_param(
            'ississi',
            $user_id,
            $purpose,
            $email,
            $auth_version,
            $token_hash,
            $expires_at,
            $created_by
        );
        $stmt->execute();
        $token_id = (int) $conn->insert_id;
        $stmt->close();

        $message = accountTokenEmailMessage(
            $purpose,
            $email,
            (string) $owner['username'],
            $token
        );
        queueAccountEmail(
            $conn,
            $token_id,
            $user_id,
            $purpose,
            $message['recipient'],
            $message['subject'],
            $message['body']
        );
        if ($transport === 'log') {
            deliverAccountEmail($message['recipient'], $message['subject'], $message['body']);
            completeQueuedAccountEmail($conn, $token_id, $user_id, $purpose);
        }

        if ($owns_transaction) {
            $conn->commit();
        }
        return [
            'token' => $token,
            'id' => $token_id,
            'expires_at' => $expires_at,
            'queued' => $transport === 'smtp',
        ];
    } catch (Throwable $exception) {
        if ($owns_transaction) {
            $conn->rollback();
        }
        throw $exception;
    }
}

function findUserEmailToken(mysqli $conn, $purpose, $token, $lock = false)
{
    $token_hash = userEmailTokenHash($token);
    if ($token_hash === null) {
        return null;
    }
    $purpose = (string) $purpose;
    $sql =
        'SELECT token.id AS token_id, token.user_id, token.email AS token_email,
                token.expires_at, token.auth_version AS token_auth_version,
                user.username, user.email, user.email_verified_at,
                user.password, user.role, user.auth_version, user.must_change_password,
                user.two_factor_enabled, user.account_status
         FROM user_email_tokens token
         INNER JOIN users user ON user.id = token.user_id
         WHERE token.purpose = ? AND token.token_hash = ?
           AND token.consumed_at IS NULL AND token.expires_at > UTC_TIMESTAMP()
           AND token.auth_version = user.auth_version
         LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare email-token lookup.');
    }
    $stmt->bind_param('ss', $purpose, $token_hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function consumeUserEmailToken(mysqli $conn, $token_id)
{
    $token_id = (int) $token_id;
    $stmt = $conn->prepare(
        'UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
         WHERE id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare email-token consumption.');
    }
    $stmt->bind_param('i', $token_id);
    $stmt->execute();
    $consumed = $stmt->affected_rows === 1;
    $stmt->close();
    return $consumed;
}

function applicationPublicUrl($path, array $query = [])
{
    $base_url = rtrim(trim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: '')), '/');
    $scheme = strtolower((string) parse_url($base_url, PHP_URL_SCHEME));
    $requires_https = function_exists('applicationRequiresHttps')
        ? applicationRequiresHttps()
        : filter_var(getenv('DNR_REQUIRE_HTTPS') ?: '0', FILTER_VALIDATE_BOOL);
    if ($base_url === ''
        || !filter_var($base_url, FILTER_VALIDATE_URL)
        || !in_array($scheme, ['http', 'https'], true)
        || parse_url($base_url, PHP_URL_USER) !== null
        || parse_url($base_url, PHP_URL_PASS) !== null
        || parse_url($base_url, PHP_URL_QUERY) !== null
        || parse_url($base_url, PHP_URL_FRAGMENT) !== null
        || ($requires_https && $scheme !== 'https')
    ) {
        throw new RuntimeException('DNR_PUBLIC_BASE_URL must be configured before email links can be sent.');
    }
    $url = $base_url . '/' . ltrim((string) $path, '/');
    return $query === [] ? $url : $url . '?' . http_build_query($query);
}

function accountMailTransport(): string
{
    $transport = strtolower(trim((string) (getenv('DNR_MAIL_TRANSPORT') ?: 'disabled')));
    if (!in_array($transport, ['smtp', 'log'], true)) {
        throw new RuntimeException('Email delivery is disabled. Configure DNR_MAIL_TRANSPORT=smtp.');
    }
    return $transport;
}

/** @return array{recipient: string, subject: string, body: string} */
function accountTokenEmailMessage(string $purpose, string $email, string $username, string $token): array
{
    $email = normalizeAccountEmail($email);
    $brandName = applicationBrandName();
    if ($purpose === 'invitation') {
        return [
            'recipient' => $email,
            'subject' => 'Your ' . $brandName . ' account invitation',
            'body' => "You have been invited to {$brandName} as {$username}.\n\n"
                . "Accept the invitation and choose your password:\n"
                . applicationPublicUrl('accept_invitation.php', ['token' => $token]) . "\n\n"
                . 'This single-use link expires in seven days.',
        ];
    }
    if ($purpose === 'verification') {
        return [
            'recipient' => $email,
            'subject' => 'Verify your ' . $brandName . ' email address',
            'body' => "Hello {$username},\n\nVerify this email address for your {$brandName} account:\n"
                . applicationPublicUrl('verify_email.php', ['token' => $token]) . "\n\n"
                . 'This single-use link expires in 24 hours.',
        ];
    }
    if ($purpose === 'recovery') {
        return [
            'recipient' => $email,
            'subject' => 'Reset your ' . $brandName . ' password',
            'body' => "Hello {$username},\n\nUse this link to choose a new {$brandName} password:\n"
                . applicationPublicUrl('recover_password.php', ['token' => $token]) . "\n\n"
                . 'This single-use link expires in one hour. If you did not request it, ignore this email.',
        ];
    }
    throw new InvalidArgumentException('Invalid email-token purpose.');
}

function queueAccountEmail(
    mysqli $conn,
    int $tokenId,
    int $userId,
    string $purpose,
    string $recipient,
    string $subject,
    string $body
): void {
    $json = json_encode([
        'recipient' => normalizeAccountEmail($recipient),
        'subject' => $subject,
        'body' => $body,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $ciphertext = \Dnr\Security\ApplicationKey::seal($json);
    $stmt = $conn->prepare(
        'INSERT INTO email_outbox
            (token_id, user_id, purpose, payload_ciphertext)
         VALUES (?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare durable email delivery.');
    }
    $stmt->bind_param('iiss', $tokenId, $userId, $purpose, $ciphertext);
    $stmt->execute();
    $stmt->close();
}

function completeQueuedAccountEmail(
    mysqli $conn,
    int $tokenId,
    int $userId,
    string $purpose
): void {
    $complete = $conn->prepare(
        "UPDATE email_outbox
         SET status = 'sent', sent_at = UTC_TIMESTAMP(), processing_started_at = NULL,
             payload_ciphertext = NULL, last_error = NULL
         WHERE token_id = ? AND status IN ('pending', 'processing', 'retry')"
    );
    if (!$complete) {
        throw new RuntimeException('Unable to prepare email completion.');
    }
    $complete->bind_param('i', $tokenId);
    $complete->execute();
    if ($complete->affected_rows !== 1) {
        $complete->close();
        throw new RuntimeException('The queued email can no longer be completed.');
    }
    $complete->close();

    $invalidate = $conn->prepare(
        'UPDATE user_email_tokens
         SET consumed_at = UTC_TIMESTAMP()
         WHERE user_id = ? AND purpose = ? AND id < ? AND consumed_at IS NULL'
    );
    if (!$invalidate) {
        throw new RuntimeException('Unable to prepare superseded email-token invalidation.');
    }
    $invalidate->bind_param('isi', $userId, $purpose, $tokenId);
    $invalidate->execute();
    $invalidate->close();
}

/**
 * @return array{id: int, token_id: int, user_id: int, purpose: string,
 *   payload_ciphertext: string, expires_at: string}|null
 */
function claimQueuedAccountEmail(mysqli $conn, int $leaseSeconds = 600): ?array
{
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    $conn->begin_transaction();
    try {
        $discard = $conn->query(
            "UPDATE email_outbox outbox
             INNER JOIN user_email_tokens token ON token.id = outbox.token_id
             INNER JOIN users user ON user.id = token.user_id
             SET outbox.status = 'failed', outbox.payload_ciphertext = NULL,
                 outbox.processing_started_at = NULL,
                 outbox.last_error = 'Queued account link is no longer valid.'
             WHERE outbox.attempts < 8
               AND outbox.payload_ciphertext IS NOT NULL
               AND (
                    (outbox.status IN ('pending', 'retry')
                        AND outbox.next_attempt_at <= UTC_TIMESTAMP())
                    OR (outbox.status = 'processing'
                        AND outbox.processing_started_at <= DATE_SUB(
                            UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND
                        ))
               )
               AND (
                    token.user_id <> outbox.user_id
                    OR token.purpose <> outbox.purpose
                    OR token.consumed_at IS NOT NULL
                    OR token.expires_at <= UTC_TIMESTAMP()
                    OR token.auth_version <> user.auth_version
                    OR (outbox.purpose = 'invitation' AND user.account_status <> 'invited')
                    OR (outbox.purpose IN ('verification', 'recovery')
                        AND user.account_status <> 'active')
               )"
        );
        if ($discard === false) {
            throw new RuntimeException('Unable to discard invalid queued account email.');
        }

        $result = $conn->query(
            "SELECT outbox.id, outbox.token_id, outbox.user_id, outbox.purpose,
                    outbox.payload_ciphertext, token.expires_at
             FROM email_outbox outbox
             INNER JOIN user_email_tokens token ON token.id = outbox.token_id
             INNER JOIN users user ON user.id = token.user_id
             WHERE outbox.attempts < 8
               AND outbox.payload_ciphertext IS NOT NULL
               AND token.user_id = outbox.user_id
               AND token.purpose = outbox.purpose
               AND token.consumed_at IS NULL
               AND token.expires_at > UTC_TIMESTAMP()
               AND token.auth_version = user.auth_version
               AND (
                    (outbox.purpose = 'invitation' AND user.account_status = 'invited')
                    OR (outbox.purpose IN ('verification', 'recovery')
                        AND user.account_status = 'active')
               )
               AND (
                    (outbox.status IN ('pending', 'retry')
                        AND outbox.next_attempt_at <= UTC_TIMESTAMP())
                    OR (outbox.status = 'processing'
                        AND outbox.processing_started_at <= DATE_SUB(
                            UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND
                        ))
             )
             ORDER BY outbox.next_attempt_at, outbox.id
             LIMIT 1 FOR UPDATE OF outbox SKIP LOCKED"
        );
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) {
            $conn->commit();
            return null;
        }
        $id = (int) $row['id'];
        $claim = $conn->prepare(
            "UPDATE email_outbox
             SET status = 'processing', attempts = attempts + 1,
                 processing_started_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = ?"
        );
        $claim->bind_param('i', $id);
        $claim->execute();
        $claim->close();
        $conn->commit();
        return [
            'id' => $id,
            'token_id' => (int) $row['token_id'],
            'user_id' => (int) $row['user_id'],
            'purpose' => (string) $row['purpose'],
            'payload_ciphertext' => (string) $row['payload_ciphertext'],
            'expires_at' => (string) $row['expires_at'],
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array{recipient: string, subject: string, body: string} */
function decryptQueuedAccountEmail(string $ciphertext): array
{
    $decoded = json_decode(
        \Dnr\Security\ApplicationKey::open($ciphertext),
        true,
        4,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($decoded)
        || !is_string($decoded['recipient'] ?? null)
        || !is_string($decoded['subject'] ?? null)
        || !is_string($decoded['body'] ?? null)
    ) {
        throw new RuntimeException('The queued email payload is invalid.');
    }
    return [
        'recipient' => normalizeAccountEmail($decoded['recipient']),
        'subject' => $decoded['subject'],
        'body' => $decoded['body'],
    ];
}

function failQueuedAccountEmail(
    mysqli $conn,
    int $outboxId,
    int $tokenId,
    Throwable $exception,
    bool $permanent = false
): void {
    $error = mb_substr($exception->getMessage(), 0, 255, 'UTF-8');
    $stmt = $conn->prepare(
        "UPDATE email_outbox
         SET status = IF(? OR attempts >= 8, 'failed', 'retry'),
             processing_started_at = NULL, last_error = ?,
             payload_ciphertext = IF(? OR attempts >= 8, NULL, payload_ciphertext),
             next_attempt_at = TIMESTAMPADD(
                 MINUTE, LEAST(1440, CAST(POW(2, attempts) AS UNSIGNED)), UTC_TIMESTAMP()
             )
         WHERE id = ? AND token_id = ? AND status = 'processing'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare queued email failure handling.');
    }
    $permanentFlag = $permanent ? 1 : 0;
    $stmt->bind_param('isiii', $permanentFlag, $error, $permanentFlag, $outboxId, $tokenId);
    $stmt->execute();
    $stmt->close();
}

function mailConfigurationSecret($name)
{
    if (function_exists('configurationSecret')) {
        return configurationSecret($name);
    }
    $file = trim((string) (getenv($name . '_FILE') ?: ''));
    if ($file !== '') {
        $value = @file_get_contents($file);
        return $value === false ? '' : trim($value);
    }
    return trim((string) (getenv($name) ?: ''));
}

function smtpReadResponse($stream, array $accepted_codes)
{
    $response = '';
    while (($line = fgets($stream, 4096)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $accepted_codes, true)) {
        throw new RuntimeException('SMTP server rejected a mail-delivery step with status ' . $code . '.');
    }
    return $response;
}

function smtpCommand($stream, $command, array $accepted_codes)
{
    if (fwrite($stream, (string) $command . "\r\n") === false) {
        throw new RuntimeException('Unable to write to the SMTP server.');
    }
    return smtpReadResponse($stream, $accepted_codes);
}

function smtpNormalizeLineEndings($value)
{
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    return str_replace("\n", "\r\n", $value);
}

function smtpTlsStreamContext($host)
{
    $host = trim((string) $host);
    $peer_name = trim((string) (getenv('DNR_SMTP_PEER_NAME') ?: $host));
    if (
        $peer_name === ''
        || strlen($peer_name) > 253
        || preg_match('/\A[A-Za-z0-9._:-]+\z/', $peer_name) !== 1
    ) {
        throw new RuntimeException('The configured SMTP TLS peer name is invalid.');
    }

    $ssl_options = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'peer_name' => $peer_name,
    ];
    $ca_file = trim((string) (getenv('DNR_SMTP_CA_FILE') ?: ''));
    if ($ca_file !== '') {
        if (!is_file($ca_file) || !is_readable($ca_file)) {
            throw new RuntimeException('The configured SMTP CA file is unavailable.');
        }
        $ssl_options['cafile'] = $ca_file;
    }

    return stream_context_create(['ssl' => $ssl_options]);
}

function sendSmtpMessage($recipient, $subject, $body)
{
    $recipient = normalizeAccountEmail($recipient);
    $from = normalizeAccountEmail(getenv('DNR_MAIL_FROM') ?: '');
    $from_name = deploymentConfig()->string('brand.mail_name');
    if (preg_match('/[\r\n]/', $from_name . (string) $subject) === 1) {
        throw new InvalidArgumentException('Mail headers contain invalid characters.');
    }
    $host = trim((string) (getenv('DNR_SMTP_HOST') ?: ''));
    $encryption = strtolower(trim((string) (getenv('DNR_SMTP_ENCRYPTION') ?: 'starttls')));
    $port = (int) (getenv('DNR_SMTP_PORT') ?: ($encryption === 'tls' ? 465 : 587));
    if ($host === '' || $port < 1 || $port > 65535 || !in_array($encryption, ['none', 'starttls', 'tls'], true)) {
        throw new RuntimeException('SMTP delivery is not fully configured.');
    }

    $remote = ($encryption === 'tls' ? 'tls://' : 'tcp://') . $host . ':' . $port;
    $stream_context = smtpTlsStreamContext($host);
    $stream = @stream_socket_client(
        $remote,
        $error_number,
        $error_message,
        10,
        STREAM_CLIENT_CONNECT,
        $stream_context
    );
    if (!is_resource($stream)) {
        throw new RuntimeException('Unable to connect to the configured SMTP server.');
    }
    stream_set_timeout($stream, 10);
    try {
        smtpReadResponse($stream, [220]);
        $hostname = gethostname() ?: 'localhost';
        smtpCommand($stream, 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $hostname), [250]);
        if ($encryption === 'starttls') {
            smtpCommand($stream, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to establish SMTP transport encryption.');
            }
            smtpCommand($stream, 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $hostname), [250]);
        }

        $username = trim((string) (getenv('DNR_SMTP_USERNAME') ?: ''));
        $password = mailConfigurationSecret('DNR_SMTP_PASSWORD');
        if ($username !== '' || $password !== '') {
            if ($username === '' || $password === '') {
                throw new RuntimeException('Both SMTP username and password are required.');
            }
            if ($encryption === 'none') {
                throw new RuntimeException('SMTP credentials require TLS encryption.');
            }
            smtpCommand($stream, 'AUTH PLAIN ' . base64_encode("\0{$username}\0{$password}"), [235]);
        }

        smtpCommand($stream, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($stream, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($stream, 'DATA', [354]);
        $encoded_subject = '=?UTF-8?B?' . base64_encode((string) $subject) . '?=';
        $encoded_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
        $message = smtpNormalizeLineEndings(implode("\n", [
            'From: ' . $encoded_name . ' <' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encoded_subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . gmdate(DATE_RFC2822),
            '',
            (string) $body,
        ]));
        $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
        if (fwrite($stream, $message . "\r\n.\r\n") === false) {
            throw new RuntimeException('Unable to send the SMTP message body.');
        }
        smtpReadResponse($stream, [250]);
        smtpCommand($stream, 'QUIT', [221]);
    } finally {
        fclose($stream);
    }
    return true;
}

function deliverAccountEmail($recipient, $subject, $body)
{
    $transport = accountMailTransport();
    if ($transport === 'log') {
        applicationLog('info', 'Account email accepted by development transport', [
            'recipient' => normalizeAccountEmail($recipient),
            'subject' => (string) $subject,
        ]);
        return true;
    }
    return sendSmtpMessage($recipient, $subject, $body);
}

function sendInvitationEmail($email, $username, $token)
{
    $url = applicationPublicUrl('accept_invitation.php', ['token' => $token]);
    $brandName = applicationBrandName();
    return deliverAccountEmail(
        $email,
        'Your ' . $brandName . ' account invitation',
        "You have been invited to {$brandName} as {$username}.\n\n"
        . "Accept the invitation and choose your password:\n{$url}\n\n"
        . "This single-use link expires in seven days."
    );
}

function sendVerificationEmail($email, $username, $token)
{
    $url = applicationPublicUrl('verify_email.php', ['token' => $token]);
    $brandName = applicationBrandName();
    return deliverAccountEmail(
        $email,
        'Verify your ' . $brandName . ' email address',
        "Hello {$username},\n\nVerify this email address for your {$brandName} account:\n{$url}\n\n"
        . "This single-use link expires in 24 hours."
    );
}

function sendPasswordRecoveryEmail($email, $username, $token)
{
    $url = applicationPublicUrl('recover_password.php', ['token' => $token]);
    $brandName = applicationBrandName();
    return deliverAccountEmail(
        $email,
        'Reset your ' . $brandName . ' password',
        "Hello {$username},\n\nUse this link to choose a new {$brandName} password:\n{$url}\n\n"
        . "This single-use link expires in one hour. If you did not request it, ignore this email."
    );
}
