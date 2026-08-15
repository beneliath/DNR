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

$_SESSION['user_id'] = 1;
$_SESSION['auth_complete'] = true;
expectTrue(!isLoggedIn(), 'A session without an authentication version must not be trusted.');
$_SESSION['auth_version'] = 1;
expectTrue(isLoggedIn(), 'A completed, versioned session should be recognized as logged in.');

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
