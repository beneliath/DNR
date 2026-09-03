<?php

putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('K', 32)));
require_once __DIR__ . '/../src/two_factor_helpers.php';

function expectTwoFactor($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$lockedActiveUser = ['account_status' => 'active', 'login_is_locked' => 1, 'two_factor_enabled' => 0];
expectTwoFactor(
    !passwordAuthenticationIsAccepted($lockedActiveUser, true)
        && passwordAuthenticationIsAccepted(
            ['account_status' => 'active', 'login_is_locked' => 1, 'two_factor_enabled' => 1],
            true
        ),
    'a locked account should require a configured second factor or verified-email recovery.'
);
expectTwoFactor(
    !passwordAuthenticationIsAccepted($lockedActiveUser, false)
        && !passwordAuthenticationIsAccepted(['account_status' => 'disabled'], true)
        && !passwordAuthenticationIsAccepted(null, true),
    'wrong passwords, disabled accounts, and unknown accounts must remain rejected.'
);

$secret = generateTotpSecret();
expectTwoFactor(strlen($secret) >= 32, 'A TOTP secret should have sufficient entropy.');

$encrypted = encryptTwoFactorSecret($secret);
expectTwoFactor($encrypted !== $secret, 'The stored value must not expose the TOTP secret.');
expectTwoFactor(decryptTwoFactorSecret($encrypted) === $secret, 'Encrypted TOTP secrets should round-trip.');

$timestamp = 1_700_000_000;
$totp = createTotp($secret, 'test-user');
$code = $totp->at($timestamp);
$expected_step = intdiv($timestamp, 30);
expectTwoFactor(
    matchingTotpStep($secret, 'test-user', $code, null, $timestamp) === $expected_step,
    'A current TOTP should validate.'
);
expectTwoFactor(
    matchingTotpStep($secret, 'test-user', $code, $expected_step, $timestamp) === null,
    'An already used TOTP step must not validate again.'
);
expectTwoFactor(
    matchingTotpStep($secret, 'test-user', '000000', null, $timestamp) === null,
    'An incorrect TOTP should fail.'
);

$codes = generateRecoveryCodes(10);
expectTwoFactor(count($codes) === 10, 'Ten recovery codes should be generated.');
expectTwoFactor(count(array_unique($codes)) === 10, 'Recovery codes should be unique.');
foreach ($codes as $recovery_code) {
    expectTwoFactor(
        preg_match('/^[2-9A-HJ-NP-Z]{4}(?:-[2-9A-HJ-NP-Z]{4}){2}$/', $recovery_code) === 1,
        'Recovery codes should use the expected readable format.'
    );
    expectTwoFactor(strlen(normalizeRecoveryCode($recovery_code)) === 12, 'Recovery code normalization should remove separators.');
}

$qr_data_uri = createTotpQrDataUri($secret, 'test-user');
expectTwoFactor(
    str_starts_with($qr_data_uri, 'data:image/svg+xml;base64,'),
    'Enrollment QR codes should be generated locally as SVG data URIs.'
);

echo "Two-factor helper tests passed.\n";
