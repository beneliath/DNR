<?php

require_once __DIR__ . '/../src/functions.php';

function expectTrue($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_SESSION = [];

$token = generateCsrfToken();
expectTrue(strlen($token) === 64, 'CSRF token should contain 32 random bytes as hex.');
expectTrue(ctype_xdigit($token), 'CSRF token should be hexadecimal.');
expectTrue(generateCsrfToken() === $token, 'CSRF token should remain stable within a session.');
expectTrue(validateCsrfToken($token), 'The session CSRF token should validate.');
expectTrue(!validateCsrfToken(str_repeat('0', 64)), 'An incorrect CSRF token should fail.');
expectTrue(!validateCsrfToken(null), 'A missing CSRF token should fail.');

$_SESSION['role'] = 'editor';
expectTrue(hasRole(['admin', 'editor']), 'Editor should match an allowed role list.');
expectTrue(!hasRole(['admin']), 'Editor should not match the admin role.');
expectTrue(checkRole('editor'), 'Exact role checks should succeed.');
expectTrue(!checkRole(0), 'Role checks should use strict comparison.');
expectTrue(!canArchiveEntries('reviewer'), 'Reviewers must not archive or restore entries.');
expectTrue(!canDeleteEntries('reviewer'), 'Reviewers must not permanently delete entries.');
expectTrue(canArchiveEntries('editor'), 'Editors should be allowed to archive and restore entries.');
expectTrue(!canDeleteEntries('editor'), 'Editors must not permanently delete entries.');
expectTrue(canArchiveEntries('admin'), 'Administrators should be allowed to archive and restore entries.');
expectTrue(canDeleteEntries('admin'), 'Administrators should be allowed to permanently delete entries.');

$original_trusted_proxies = getenv('DNR_TRUSTED_PROXY_IPS');
$original_cloudflare_proxies = getenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
putenv('DNR_TRUSTED_PROXY_IPS=192.168.65.1');
putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS=172.18.0.0/16');
expectTrue(
    requestIpAddress([
        'REMOTE_ADDR' => '192.168.65.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.42, 192.168.65.1',
    ]) === '203.0.113.42',
    'A configured reverse proxy should supply the original client IP address.'
);
expectTrue(
    requestIpAddress([
        'REMOTE_ADDR' => '192.168.65.1',
        'HTTP_X_FORWARDED_FOR' => '172.18.0.14',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.42',
        'HTTP_CF_RAY' => 'a2bb06142d4369b9-DFW',
    ]) === '203.0.113.42',
    'A trusted Cloudflare tunnel should supply the original public client IP address.'
);
expectTrue(
    requestIpAddress([
        'REMOTE_ADDR' => '192.168.65.1',
        'HTTP_X_FORWARDED_FOR' => '192.168.1.56',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.99',
        'HTTP_CF_RAY' => 'a2bb06142d4369b9-DFW',
    ]) === '192.168.1.56',
    'Cloudflare headers must be ignored unless the forwarded peer is a trusted tunnel.'
);
expectTrue(
    requestIpAddress([
        'REMOTE_ADDR' => '198.51.100.20',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
    ]) === '198.51.100.20',
    'Forwarding headers from an untrusted peer must be ignored.'
);
expectTrue(
    requestUsesHttps([
        'REMOTE_ADDR' => '192.168.65.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]),
    'HTTPS forwarded by a configured reverse proxy should secure the session cookie.'
);
expectTrue(
    !requestUsesHttps([
        'REMOTE_ADDR' => '198.51.100.20',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]),
    'An untrusted client must not be able to spoof the HTTPS state.'
);
if ($original_trusted_proxies === false) {
    putenv('DNR_TRUSTED_PROXY_IPS');
} else {
    putenv('DNR_TRUSTED_PROXY_IPS=' . $original_trusted_proxies);
}
if ($original_cloudflare_proxies === false) {
    putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
} else {
    putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS=' . $original_cloudflare_proxies);
}

$_SESSION['user_id'] = 1;
$_SESSION['auth_complete'] = true;
expectTrue(!isLoggedIn(), 'A session without an authentication version must not be trusted.');
$_SESSION['auth_version'] = 1;
expectTrue(isLoggedIn(), 'A completed, versioned session should be recognized as logged in.');

expectTrue(
    isApplicationRootRequest([
        'REQUEST_URI' => '/',
        'SCRIPT_NAME' => '/index.php',
    ]),
    'The bare domain path should be recognized as the application root.'
);
expectTrue(
    isApplicationRootRequest([
        'REQUEST_URI' => '/dnr/?view=active',
        'SCRIPT_NAME' => '/dnr/index.php',
    ]),
    'A bare application path should be recognized when DNR is hosted in a subdirectory.'
);
expectTrue(
    !isApplicationRootRequest([
        'REQUEST_URI' => '/index.php',
        'SCRIPT_NAME' => '/index.php',
    ]),
    'An explicit index.php request must remain available for Add Engagement.'
);
expectTrue(
    !isApplicationRootRequest([
        'REQUEST_URI' => '/engagements.php',
        'SCRIPT_NAME' => '/index.php',
    ]),
    'A non-root page must not be treated as the application root.'
);

expectTrue(
    authenticationDestination(['role' => 'editor', 'must_change_password' => 1])
        === 'two_factor_settings.php?password_reset_required=1',
    'A temporary password must force the account to the password-change page.'
);
expectTrue(
    authenticationDestination(['role' => 'reviewer', 'must_change_password' => 0])
        === 'engagements.php',
    'A reviewer without a temporary password should land on Engagements.'
);
expectTrue(
    authenticationDestination(['role' => 'editor', 'must_change_password' => 0])
        === 'engagements.php',
    'An editor without a temporary password should land on Engagements.'
);
expectTrue(
    authenticationDestination(['role' => 'admin', 'must_change_password' => 0])
        === 'engagements.php',
    'An administrator without a temporary password should land on Engagements.'
);
expectTrue(
    twoFactorRecoveryCodesDestination(true) === 'engagements.php',
    'Initial two-factor enrollment should continue to Engagements.'
);
expectTrue(
    twoFactorRecoveryCodesDestination(false) === 'two_factor_settings.php',
    'Later two-factor enrollment should return to Account Security.'
);

expectTrue(validIsoDate('2026-08-17'), 'A real ISO date should validate.');
expectTrue(!validIsoDate('2026-02-30'), 'An impossible ISO date should be rejected.');
requireValidDateRange('2026-08-17', '2026-08-17');
try {
    requireValidDateRange('2026-08-18', '2026-08-17');
    expectTrue(false, 'An inverted event date range should throw.');
} catch (InvalidArgumentException $exception) {
    expectTrue(true, 'An inverted event date range was rejected.');
}
expectTrue(nullableNonNegativeAmount('0', 'travel') === 0.0, 'A zero amount must be preserved.');
expectTrue(nullableNonNegativeAmount('', 'travel') === null, 'A blank amount should remain unset.');
expectTrue(!nullableAmountsEqual(null, 0.0), 'Changing a blank amount to zero must be detected.');
expectTrue(nullableAmountsEqual('0.00', 0.0), 'Equivalent stored and submitted zero amounts should compare equally.');
try {
    nullableNonNegativeAmount('-1', 'travel');
    expectTrue(false, 'A negative amount should throw.');
} catch (InvalidArgumentException $exception) {
    expectTrue(true, 'A negative amount was rejected.');
}
expectTrue(normalizedHttpUrl('https://example.org/path') === 'https://example.org/path', 'HTTPS URLs should validate.');
expectTrue(normalizedHttpUrl('javascript:alert(1)') === null, 'Script URLs must be rejected.');
expectTrue(normalizeEventType('other', 'Retreat') === ['other', 'Retreat'], 'Custom event types should use the canonical other fields.');
try {
    normalizeEventType('Retreat', '');
    expectTrue(false, 'An arbitrary event type should throw.');
} catch (InvalidArgumentException $exception) {
    expectTrue(true, 'An arbitrary event type was rejected.');
}
expectTrue(loginRateLimitSettings('ip')['limit'] === 8, 'The per-IP login limit should remain bounded.');

$_SESSION = [
    '_pending_auth' => [
        'user_id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'issued_at' => time(),
    ],
];
expectTrue(!isLoggedIn(), 'A password-only pending session must not be treated as logged in.');

$_SESSION['two_factor_verified_at'] = time() - 60;
expectTrue(hasRecentTwoFactorVerification(), 'A recent second-factor verification should permit sensitive account changes.');
$_SESSION['two_factor_verified_at'] = time() - 600;
expectTrue(!hasRecentTwoFactorVerification(), 'An old second-factor verification should require reauthentication.');

$now = time();
$_SESSION = [
    '_password_recovery' => [
        'user_id' => 7,
        'auth_version' => 3,
        'stage' => 'verify',
        'issued_at' => $now - 60,
        'verified_at' => null,
        'attempts' => 0,
    ],
];
expectTrue(
    getPasswordRecovery('verify', $now)['user_id'] === 7,
    'A recent password recovery request should remain valid during verification.'
);
expectTrue(
    getPasswordRecovery('reset', $now) === null,
    'A verification-stage recovery request must not authorize a password reset.'
);

$_SESSION['_password_recovery'] = [
    'user_id' => 7,
    'auth_version' => 3,
    'stage' => 'reset',
    'issued_at' => $now - 60,
    'verified_at' => $now - 60,
    'attempts' => 0,
];
expectTrue(
    getPasswordRecovery('reset', $now)['auth_version'] === 3,
    'A recent second-factor proof should authorize the reset stage.'
);

$_SESSION['_password_recovery']['verified_at'] = $now - 301;
expectTrue(
    getPasswordRecovery('reset', $now) === null,
    'Password reset authorization must expire after five minutes.'
);

echo "Security helper tests passed.\n";
