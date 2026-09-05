<?php

declare(strict_types=1);

require_once __DIR__ . '/user_lifecycle_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';

/** @return array{token: string, id: int, expires_at: string, queued: bool} */
function requestAccountEmailChange(
    mysqli $conn,
    int $userId,
    int $authVersion,
    string $email,
    string $password,
    string $code
): array {
    $email = normalizeAccountEmail($email);
    $user = fetchAuthenticationUserById($conn, $userId);
    if (!$user || $user['account_status'] !== 'active' || (int) $user['auth_version'] !== $authVersion) {
        throw new InvalidArgumentException('Your sign-in expired. Sign in again before changing your email.');
    }
    $passwordValid = \Dnr\Security\PasswordPolicy::verify($password, $user['password']);
    if (!passwordAuthenticationIsAccepted($user, $passwordValid)) {
        if (empty($user['login_is_locked'])) recordAuthenticationFailure($conn, $userId, 'password');
        throw new InvalidArgumentException('The password or authentication code was not accepted.');
    }
    if (!empty($user['two_factor_enabled'])) {
        if (!empty($user['two_factor_is_locked']) || !verifyAndConsumeTotp($conn, $user, $code)) {
            recordAuthenticationFailure($conn, $userId, 'two_factor');
            throw new InvalidArgumentException('The password or authentication code was not accepted.');
        }
    }
    resetAuthenticationFailures($conn, $userId, 'password');

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT email, email_verified_at, auth_version, account_status FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $userId);
        $lock->execute();
        $current = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$current || $current['account_status'] !== 'active' || (int) $current['auth_version'] !== $authVersion) {
            throw new InvalidArgumentException('Authentication changed. Sign in and try again.');
        }
        if (strtolower(trim((string) $current['email'])) === $email) {
            throw new InvalidArgumentException('Enter a different email address.');
        }
        if (emailAddressBelongsToAnotherUser($conn, $email, $userId)) {
            throw new InvalidArgumentException('That email address belongs to another account.');
        }
        $update = $conn->prepare('UPDATE users SET pending_email = ? WHERE id = ?');
        $update->bind_param('si', $email, $userId);
        $update->execute();
        $update->close();
        $invalidate = $conn->prepare("UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
            WHERE user_id = ? AND purpose = 'verification' AND consumed_at IS NULL");
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();
        $issued = issueUserEmailToken($conn, $userId, 'verification', $email, $userId, null, true);
        if (!empty($current['email_verified_at']) && !empty($current['email'])) {
            // This notice deliberately survives authentication-version changes.
            issueUserEmailToken($conn, $userId, 'security_notice', $current['email'], $userId, null, true);
        }
        if (!logSecurityEvent($conn, 'account_email_change_requested', $userId, $userId)) {
            throw new RuntimeException('Unable to audit the email change request.');
        }
        $conn->commit();
        return $issued;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** Verify the current address or atomically promote a pending address. */
function verifyAccountEmail(mysqli $conn, string $token): bool
{
    $conn->begin_transaction();
    try {
        $verification = findUserEmailToken($conn, 'verification', $token, true);
        if (!$verification || $verification['account_status'] !== 'active') {
            throw new InvalidArgumentException('This verification link is invalid, expired, or already used.');
        }
        $email = normalizeAccountEmail($verification['token_email']);
        $changing = (string) ($verification['pending_email'] ?? '') === $email;
        if (!$changing && strtolower(trim((string) $verification['email'])) !== $email) {
            throw new InvalidArgumentException('The account email changed after this link was issued.');
        }
        $userId = (int) $verification['user_id'];
        if (emailAddressBelongsToAnotherUser($conn, $email, $userId)) {
            throw new InvalidArgumentException('That email address belongs to another account.');
        }
        // The unique generated verified_email index also arbitrates concurrent claims.
        $update = $conn->prepare($changing
            ? 'UPDATE users SET email = ?, email_verified_at = UTC_TIMESTAMP(), pending_email = NULL,
                auth_version = auth_version + 1, task_digest_enabled = 0 WHERE id = ?'
            : 'UPDATE users SET email = ?, email_verified_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->bind_param('si', $email, $userId);
        $update->execute();
        $update->close();
        if (!consumeUserEmailToken($conn, (int) $verification['token_id'])) {
            throw new RuntimeException('Unable to consume the email verification token.');
        }
        if ($changing) {
            $invalidate = $conn->prepare("UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
                WHERE user_id = ? AND purpose <> 'security_notice' AND consumed_at IS NULL");
            $invalidate->bind_param('i', $userId);
            $invalidate->execute();
            $invalidate->close();
        }
        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => $changing ? 'account_email_changed' : 'email_verified',
            'target_user_id' => $userId,
            'target_username' => (string) $verification['username'],
            'entity_type' => 'users', 'entity_id' => $userId,
            'entity_label' => (string) $verification['username'],
            'details' => 'Verified ' . $email,
        ])) throw new RuntimeException('Unable to audit email verification.');
        $conn->commit();
        return $changing;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
