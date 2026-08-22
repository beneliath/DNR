<?php

declare(strict_types=1);

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

/** @return array{token: string, id: int, expires_at: string} */
function issueUserEmailToken(
    mysqli $conn,
    $user_id,
    $purpose,
    $email,
    $created_by = null,
    $lifetime_seconds = null
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

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $token_hash = userEmailTokenHash($token);
    if ($token_hash === null) {
        throw new RuntimeException('Unable to create an email token.');
    }
    $expires_at = gmdate('Y-m-d H:i:s', time() + $lifetime_seconds);

    $version_stmt = $conn->prepare('SELECT auth_version FROM users WHERE id = ?');
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

    $invalidate = $conn->prepare(
        'UPDATE user_email_tokens
         SET consumed_at = UTC_TIMESTAMP()
         WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL'
    );
    if (!$invalidate) {
        throw new RuntimeException('Unable to prepare email-token invalidation.');
    }
    $invalidate->bind_param('is', $user_id, $purpose);
    $invalidate->execute();
    $invalidate->close();

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

    return ['token' => $token, 'id' => $token_id, 'expires_at' => $expires_at];
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
    if ($base_url === ''
        || !filter_var($base_url, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string) parse_url($base_url, PHP_URL_SCHEME)), ['http', 'https'], true)
    ) {
        throw new RuntimeException('DNR_PUBLIC_BASE_URL must be configured before email links can be sent.');
    }
    $url = $base_url . '/' . ltrim((string) $path, '/');
    return $query === [] ? $url : $url . '?' . http_build_query($query);
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

function sendSmtpMessage($recipient, $subject, $body)
{
    $recipient = normalizeAccountEmail($recipient);
    $from = normalizeAccountEmail(getenv('DNR_MAIL_FROM') ?: '');
    $from_name = trim((string) (getenv('DNR_MAIL_FROM_NAME') ?: 'MOED'));
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
    $stream = @stream_socket_client($remote, $error_number, $error_message, 10, STREAM_CLIENT_CONNECT);
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
        $message = implode("\r\n", [
            'From: ' . $encoded_name . ' <' . $from . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $encoded_subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . gmdate(DATE_RFC2822),
            '',
            str_replace(["\r\n", "\r"], "\n", (string) $body),
        ]);
        $message = str_replace("\n", "\r\n", $message);
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
    $transport = strtolower(trim((string) (getenv('DNR_MAIL_TRANSPORT') ?: 'disabled')));
    if ($transport === 'log') {
        applicationLog('info', 'Account email accepted by development transport', [
            'recipient' => normalizeAccountEmail($recipient),
            'subject' => (string) $subject,
        ]);
        return true;
    }
    if ($transport !== 'smtp') {
        throw new RuntimeException('Email delivery is disabled. Configure DNR_MAIL_TRANSPORT=smtp.');
    }
    return sendSmtpMessage($recipient, $subject, $body);
}

function sendInvitationEmail($email, $username, $token)
{
    $url = applicationPublicUrl('accept_invitation.php', ['token' => $token]);
    return deliverAccountEmail(
        $email,
        'Your MOED account invitation',
        "You have been invited to MOED as {$username}.\n\n"
        . "Accept the invitation and choose your password:\n{$url}\n\n"
        . "This single-use link expires in seven days."
    );
}

function sendVerificationEmail($email, $username, $token)
{
    $url = applicationPublicUrl('verify_email.php', ['token' => $token]);
    return deliverAccountEmail(
        $email,
        'Verify your MOED email address',
        "Hello {$username},\n\nVerify this email address for your MOED account:\n{$url}\n\n"
        . "This single-use link expires in 24 hours."
    );
}

function sendPasswordRecoveryEmail($email, $username, $token)
{
    $url = applicationPublicUrl('recover_password.php', ['token' => $token]);
    return deliverAccountEmail(
        $email,
        'Reset your MOED password',
        "Hello {$username},\n\nUse this link to choose a new MOED password:\n{$url}\n\n"
        . "This single-use link expires in one hour. If you did not request it, ignore this email."
    );
}
