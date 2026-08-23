<?php

require_once __DIR__ . '/../src/functions.php';

function expectTrue($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_SESSION = [];

$docker_route_fixture = "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\n"
    . "eth0\t00000000\t010012AC\t0003\t0\t0\t0\t00000000\n"
    . "eth0\t000012AC\t00000000\t0001\t0\t0\t0\t0000FFFF\n";
expectTrue(
    dockerGatewayAddress($docker_route_fixture) === '172.18.0.1',
    'The Docker gateway token should resolve the default IPv4 route from /proc/net/route.'
);
expectTrue(
    isAddressInTrustedNetworks('172.18.0.1', 'docker-gateway', '172.18.0.1'),
    'The resolved Docker gateway should be accepted as the immediate reverse-proxy hop.'
);
expectTrue(
    !isAddressInTrustedNetworks('172.18.0.2', 'docker-gateway', '172.18.0.1')
        && dockerGatewayAddress("Iface\tDestination\tGateway\tFlags\neth0\t00000000\tINVALID\t0003\n") === null,
    'Other container-network addresses and malformed routes must not become trusted proxies.'
);

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
expectTrue(
    organizationArchiveDependencyMessage(['contacts' => 2, 'engagements' => 1])
        === 'This organization cannot be archived while it has 2 active contacts and 1 active engagement. Archive those related records first, or move them to another organization.',
    'Organization archive blockers should identify each active dependency with correct pluralization.'
);
expectTrue(
    organizationArchiveDependencyMessage(['contacts' => 0, 'engagements' => 0]) === '',
    'Organizations without active dependencies should not receive an archive blocker message.'
);

$original_trusted_proxies = getenv('DNR_TRUSTED_PROXY_IPS');
$original_cloudflare_proxies = getenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
putenv('DNR_TRUSTED_PROXY_IPS');
expectTrue(
    isTrustedProxyAddress('192.168.65.1'),
    'Docker Desktop published-port requests should use the known working trusted-proxy default.'
);
putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
expectTrue(
    isTrustedCloudflareProxyAddress('172.18.0.16'),
    'Cloudflare tunnel container address changes should remain inside the trusted proxy network.'
);
putenv('DNR_TRUSTED_PROXY_IPS=192.168.65.1');
putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS=172.18.0.0/24');
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
        'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 203.0.113.42, 192.168.65.1',
    ]) === '203.0.113.42',
    'A client-supplied leftmost forwarding value must not override the nearest untrusted address.'
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
        'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 172.18.0.14',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.42',
        'HTTP_CF_RAY' => 'a2bb06142d4369b9-DFW',
    ]) === '203.0.113.42',
    'A trusted Cloudflare hop must ignore an untrusted value prepended to its forwarding chain.'
);
expectTrue(
    requestIpAddress([
        'REMOTE_ADDR' => '192.168.65.1',
        'HTTP_X_FORWARDED_FOR' => '172.18.0.16',
        'HTTP_CF_CONNECTING_IP' => '2001:db8:1234::42',
        'HTTP_CF_RAY' => 'a2bb06142d4369b9-DFW',
    ]) === '2001:db8:1234::42',
    'A reassigned Cloudflare tunnel hop should preserve an IPv6 client address.'
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

$_SESSION['_two_factor_enrollment'] = [
    'user_id' => 1,
    'secret' => 'logged-in-enrollment-secret',
    'mode' => 'enroll',
    'issued_at' => time(),
];
expectTrue(
    getPendingAuthentication() === null
        && isset($_SESSION['_two_factor_enrollment']),
    'Checking for pending login must preserve a logged-in user\'s active 2FA enrollment.'
);

$_SESSION['_pending_auth'] = [
    'user_id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'auth_version' => 1,
    'issued_at' => time() - 601,
];
expectTrue(
    getPendingAuthentication() === null
        && !isset($_SESSION['_pending_auth'], $_SESSION['_two_factor_enrollment']),
    'An expired pending login should still clear its associated 2FA enrollment.'
);
$_SESSION = [
    'user_id' => 1,
    'auth_version' => 1,
    'auth_complete' => true,
];

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
        === 'dashboard.php',
    'A reviewer without a temporary password should land on the daily dashboard.'
);
expectTrue(
    authenticationDestination(['role' => 'editor', 'must_change_password' => 0])
        === 'dashboard.php',
    'An editor without a temporary password should land on the daily dashboard.'
);
expectTrue(
    authenticationDestination(['role' => 'admin', 'must_change_password' => 0])
        === 'dashboard.php',
    'An administrator without a temporary password should land on the daily dashboard.'
);
expectTrue(
    twoFactorRecoveryCodesDestination(true) === 'dashboard.php',
    'Initial two-factor enrollment should continue to the daily dashboard.'
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
expectTrue(
    normalizePhoneNumber('+1', '3125550199') === '+13125550199',
    'U.S. telephone numbers should be stored in canonical E.164 format.'
);
expectTrue(
    normalizePhoneNumber('+1', '+1 (312) 555-0199') === '+13125550199',
    'A pasted U.S. country code should not be duplicated.'
);
expectTrue(
    normalizePhoneNumber('+44', '020 7946 0958') === '+442079460958',
    'A valid international national number should normalize according to its country metadata.'
);
expectTrue(
    normalizePhoneNumber('+972', '02-531-8100') === '+97225318100',
    'National trunk prefixes and variable-length international numbers should normalize correctly.'
);
expectTrue(normalizePhoneNumber('+1', '') === '', 'An optional blank telephone number should remain blank.');
expectTrue(
    phoneNumberInputParts('312.555.0199') === ['+1', '(312) 555-0199'],
    'Legacy U.S. values should populate the country and local controls separately.'
);
expectTrue(
    formatPhoneNumberForDisplay('1-312-555-0199') === '+1 312-555-0199',
    'Legacy telephone numbers should be normalized when displayed.'
);
expectTrue(
    str_contains(phoneCountryPicker('phone_country_code'), 'data-country-code="+1"')
        && str_contains(phoneCountryPicker('phone_country_code'), '🇺🇸')
        && str_contains(phoneCountryPicker('phone_country_code'), 'United States / Canada'),
    'New telephone controls should default to the U.S. +1 country selection.'
);
try {
    normalizePhoneNumber('+1', '555-0199');
    expectTrue(false, 'An incomplete telephone number should throw.');
} catch (InvalidArgumentException $exception) {
    expectTrue(true, 'An incomplete telephone number was rejected.');
}
try {
    normalizePhoneNumber('+44', '+1 312-555-0199');
    expectTrue(false, 'A number from a different selected country should throw.');
} catch (InvalidArgumentException $exception) {
    expectTrue(true, 'A mismatched country calling code was rejected.');
}
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
