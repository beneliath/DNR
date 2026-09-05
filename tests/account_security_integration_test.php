<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Account security integration tests skipped (requires a disposable database).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/account_email_change_helpers.php';
function checkAccountSecurity(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function rejectedAccountSecurity(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    throw new LogicException($message);
}
putenv('DNR_MAIL_TRANSPORT=smtp');
$suffix = bin2hex(random_bytes(6));
$name = 'security-' . $suffix;
$old = $name . '@example.test';
$new = 'new-' . $old;
$password = 'AccountSecurity!123';
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, password, role, email, email_verified_at, account_status)
    VALUES (?, ?, 'reviewer', ?, UTC_TIMESTAMP(), 'active')");
$stmt->bind_param('sss', $name, $hash, $old);
$stmt->execute();
$id = (int) $conn->insert_id;
$otherId = 0;
$version = (int) fetchAuthenticationUserById($conn, $id)['auth_version'];
try {
    rejectedAccountSecurity(fn() => requestAccountEmailChange($conn, $id, $version, $new, '', ''), 'Session alone changed email');
    $issued = requestAccountEmailChange($conn, $id, $version, $new, $password, '');
    $row = $conn->query("SELECT email, pending_email, email_verified_at FROM users WHERE id = $id")->fetch_assoc();
    checkAccountSecurity($row['email'] === $old && $row['pending_email'] === $new && $row['email_verified_at'] !== null, 'Old verified recovery address must remain usable');
    checkAccountSecurity((int) $conn->query("SELECT COUNT(*) AS n FROM user_email_tokens WHERE user_id = $id AND purpose = 'security_notice'")->fetch_assoc()['n'] === 1, 'Old address notice missing');
    $recovery = issueUserEmailToken($conn, $id, 'recovery', $old);
    checkAccountSecurity(verifyAccountEmail($conn, $issued['token']), 'Pending email was not promoted');
    $row = $conn->query("SELECT email, pending_email, auth_version FROM users WHERE id = $id")->fetch_assoc();
    checkAccountSecurity($row['email'] === $new && $row['pending_email'] === null && (int) $row['auth_version'] === ++$version, 'Promotion must revoke sessions atomically');
    rejectedAccountSecurity(fn() => verifyAccountEmail($conn, $issued['token']), 'Verification replay accepted');
    checkAccountSecurity(findUserEmailToken($conn, 'recovery', $recovery['token']) === null, 'Old recovery token survived');
    checkAccountSecurity((int) $conn->query("SELECT COUNT(*) AS n FROM user_email_tokens WHERE user_id = $id AND purpose = 'security_notice' AND consumed_at IS NULL")->fetch_assoc()['n'] === 1, 'Notice must survive version revocation');
    $expired = requestAccountEmailChange($conn, $id, $version, 'expired-' . $old, $password, '');
    $conn->query('UPDATE user_email_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ' . (int) $expired['id']);
    rejectedAccountSecurity(fn() => verifyAccountEmail($conn, $expired['token']), 'Expired verification accepted');
    $otherName = 'other-' . $name;
    $otherEmail = $otherName . '@example.test';
    $stmt->bind_param('sss', $otherName, $hash, $otherEmail);
    $stmt->execute();
    $otherId = (int) $conn->insert_id;
    $otherVersion = (int) fetchAuthenticationUserById($conn, $otherId)['auth_version'];
    $sharedEmail = 'claimed-' . $old;
    $firstClaim = requestAccountEmailChange($conn, $id, $version, $sharedEmail, $password, '');
    $secondClaim = requestAccountEmailChange($conn, $otherId, $otherVersion, $sharedEmail, $password, '');
    checkAccountSecurity(verifyAccountEmail($conn, $firstClaim['token']), 'First verified email claim failed');
    ++$version;
    rejectedAccountSecurity(fn() => verifyAccountEmail($conn, $secondClaim['token']), 'An address claimed after issuance was accepted');
    checkAccountSecurity($conn->query("SELECT email FROM users WHERE id = $otherId")->fetch_assoc()['email'] === $otherEmail, 'A rejected collision changed the recovery address');
    $pending = requestAccountEmailChange($conn, $id, $version, 'pending-' . $old, $password, '');
    $conn->query("UPDATE users SET auth_version = auth_version + 1 WHERE id = $id");
    ++$version;
    rejectedAccountSecurity(fn() => verifyAccountEmail($conn, $pending['token']), 'Revoked pending verification accepted');
    checkAccountSecurity($conn->query("SELECT pending_email FROM users WHERE id = $id")->fetch_assoc()['pending_email'] === null, 'Revocation must cancel pending address');
    $secret = generateTotpSecret();
    rejectedAccountSecurity(fn() => enableTwoFactorForUser($conn, $id, $secret, 1, $version - 1), 'Revoked enrollment accepted');
    $codes = enableTwoFactorForUser($conn, $id, $secret, 1, $version);
    checkAccountSecurity(count($codes) === 10, 'Valid enrollment failed');
    ++$version;
    rejectedAccountSecurity(fn() => enableTwoFactorForUser($conn, $id, generateTotpSecret(), 2, $version - 1), 'Competing enrollment accepted');
    $code = createTotp($secret, $name)->now();
    rejectedAccountSecurity(fn() => requestAccountEmailChange($conn, $id, $version, 'mfa-' . $old, $password, ''), 'MFA account accepted password only');
    $mfa = requestAccountEmailChange($conn, $id, $version, 'mfa-' . $old, $password, $code);
    rejectedAccountSecurity(fn() => requestAccountEmailChange($conn, $id, $version, 'replay-' . $old, $password, $code), 'MFA replay accepted');
    checkAccountSecurity(verifyAccountEmail($conn, $mfa['token']), 'MFA-authorized change failed');
    ++$version;
    $conn->query("UPDATE users SET account_status = 'inactive', auth_version = auth_version + 1 WHERE id = $id");
    rejectedAccountSecurity(fn() => enableTwoFactorForUser($conn, $id, $secret, 2, $version), 'Deactivated enrollment accepted');
    $conn->query("UPDATE users SET account_status = 'active', auth_version = auth_version + 1 WHERE id = $id");
    rejectedAccountSecurity(fn() => enableTwoFactorForUser($conn, $id, $secret, 2, $version), 'Reactivated stale enrollment accepted');
    echo "Account security integration tests passed.\n";
} finally {
    if ($otherId > 0) $conn->query("DELETE FROM users WHERE id = $otherId");
    $conn->query("DELETE FROM users WHERE id = $id");
    $stmt->close();
}
