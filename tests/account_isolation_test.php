<?php
declare(strict_types=1);
putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('K', 32)));
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/two_factor_helpers.php';
function expectAccount(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
putenv('DNR_ACCOUNTS_ENABLED=1');
putenv('DNR_ACCOUNT_MODE=primary');
putenv('DNR_ACCOUNT_KEY=account-alpha');
putenv('DNR_REQUIRE_HTTPS=1');
putenv('DNR_PRIMARY_PUBLIC_URL=https://moed.example.org/a/account-alpha');
putenv('DNR_ACCOUNT_CONTROL_PROXY=http://account-control:8080');
$transport = accountControlCurlOptions('https://moed.example.org/a/account-beta/account_service.php');
expectAccount($transport[CURLOPT_SSL_VERIFYPEER] && $transport[CURLOPT_SSL_VERIFYHOST] === 2
    && $transport[CURLOPT_HTTPPROXYTUNNEL], 'Control relay must preserve end-to-end TLS validation.');
foreach (['http://moed.example.org/a/account-alpha/platform_api.php', 'https://other.example.org/', 'https://moed.example.org:444/'] as $destination) {
    try { accountControlCurlOptions($destination); throw new LogicException('Unsafe relay target accepted.'); }
    catch (RuntimeException $error) { expectAccount(!$error instanceof LogicException, 'Unsafe relay target accepted.'); }
}
putenv('DNR_ACCOUNT_CONTROL_PROXY');
$_SESSION = ['auth_complete' => true, 'role' => 'admin', 'is_superadmin' => false];
expectAccount(!isSuperAdmin(), 'Account Admin must not inherit platform authority.');
$_SESSION['is_superadmin'] = true;
expectAccount(isSuperAdmin(), 'An authenticated platform administrator has platform authority.');
$_SESSION['authenticated_role'] = 'admin';
expectAccount(setRolePreview('admin') && activeRolePreview() === 'admin' && !isSuperAdmin()
    && authenticatedSuperAdmin() && checkRole('admin'), 'Administrator preview retains account administration but removes platform authority.');
expectAccount(safeRolePreviewReturnUrl('accounts.php', 'admin') === 'dashboard.php', 'Account Admin preview cannot return to the platform directory.');
expectAccount(setRolePreview('editor') && activeRolePreview() === 'editor' && !isSuperAdmin(), 'SuperAdmin can preview Editor.');
expectAccount(setRolePreview('superadmin') && activeRolePreview() === null && isSuperAdmin(), 'SuperAdmin can restore platform authority.');
$_SESSION['is_superadmin'] = false;
expectAccount(!setRolePreview('superadmin') && !isSuperAdmin(), 'An ordinary administrator cannot escalate through Preview Access.');
$_SESSION['_role_preview'] = 'admin';
expectAccount(activeRolePreview() === null, 'Revoked SuperAdmin eligibility invalidates Administrator preview state.');
expectAccount(setRolePreview('admin') && !isset($_SESSION['_role_preview']), 'An ordinary administrator can restore their assigned access.');
$_SESSION['is_superadmin'] = true;
$_SESSION['role'] = 'reviewer';
expectAccount(!isSuperAdmin(), 'Role preview must hide platform controls.');
$_SESSION['role'] = 'admin'; $_SESSION['auth_complete'] = false;
expectAccount(!isSuperAdmin(), 'Password-only sessions do not have platform authority.');
foreach (['http://example.org', 'https://example.org/account', 'https://example.org?token=x',
          'https://user@example.org', "https://example.org\r\nLocation: evil", '//example.org'] as $url) {
    try { accountPublicUrl($url); throw new LogicException('Unsafe Account origin accepted.'); }
    catch (InvalidArgumentException $expected) {}
}
expectAccount(accountPublicUrl('https://account.example.org/') === 'https://account.example.org', 'HTTPS origins normalize.');
expectAccount(accountPublicUrl('https://moed.example.org/a/account-alpha') === 'https://moed.example.org/a/account-alpha', 'Account paths support a shared hostname.');
putenv('DNR_REQUIRE_HTTPS=0');
expectAccount(accountPublicUrl('http://localhost:8081') === 'http://localhost:8081', 'Local previews can use HTTP.');
expectAccount(!passwordAuthenticationIsAccepted(['account_status' => 'active', 'platform_identity_id' => 5], true),
    'A platform proxy must never be able to use password login.');
expectAccount(passwordAuthenticationIsAccepted(['account_status' => 'active'], true), 'Ordinary login remains available.');
putenv('DNR_ACCOUNT_KEY=../account-beta');
try { currentAccountKey(); throw new LogicException('Unsafe Account key accepted.'); }
catch (RuntimeException $expected) { expectAccount(!$expected instanceof LogicException, 'Unsafe Account key was accepted.'); }
$base = \Dnr\Config\DeploymentConfig::load(__DIR__ . '/../deployments/moed/application.yaml', []);
$alpha = $base->withAccountOverrides(['DNR_CALENDAR_NAME' => 'Alpha', 'DNR_TIMEZONE' => 'Europe/London',
    'DNR_BRAND_DISPLAY_NAME' => 'Unexpected', 'DNR_MAIL_FROM_NAME' => 'Unexpected']);
$beta = $base->withAccountOverrides(['DNR_CALENDAR_NAME' => 'Beta']);
expectAccount($alpha->string('brand.calendar_name') === 'Alpha' && $beta->string('brand.calendar_name') === 'Beta'
    && $base->string('brand.calendar_name') !== 'Alpha', 'Account overrides must not mutate another Account configuration.');
expectAccount($alpha->string('brand.display_name') === $base->string('brand.display_name')
    && $alpha->string('brand.mail_name') === $base->string('brand.mail_name'), 'Account settings cannot override shared application or sender names.');
$identity = ['id' => 12, 'auth_version' => 3];
$proof = platformIssueIdentityGrant('account-beta', $identity, 1000);
platformVerifyIdentityGrant($proof, 'account-beta', $identity, 1899);
$switch = platformIssueSwitchIntent($identity, 'account-gamma', 1000);
platformVerifySwitchIntent($switch, $identity, 'account-gamma', 1899);
foreach (['', substr($proof, 0, -8) . 'AAAAAAAA', $switch,
    platformIssueIdentityGrant('account-other', $identity, 1000),
    platformIssueIdentityGrant('account-beta', ['id'=>13,'auth_version'=>3], 1000),
    platformIssueIdentityGrant('account-beta', ['id'=>12,'auth_version'=>2], 1000),
    platformIssueIdentityGrant('account-beta', $identity, 0)] as $token) {
    try { platformVerifyIdentityGrant($token, 'account-beta', $identity, 1899); throw new LogicException('Invalid sign-in proof accepted.'); }
    catch (RuntimeException $error) { expectAccount(!$error instanceof LogicException, $error->getMessage()); }
}
foreach ([$proof, platformIssueSwitchIntent($identity, 'account-beta', 1000),
    platformIssueSwitchIntent(['id'=>13,'auth_version'=>3], 'account-gamma', 1000),
    platformIssueSwitchIntent(['id'=>12,'auth_version'=>2], 'account-gamma', 1000),
    platformIssueSwitchIntent($identity, 'account-gamma', 0)] as $token) {
    try { platformVerifySwitchIntent($token, $identity, 'account-gamma', 1899); throw new LogicException('Invalid switch intent accepted.'); }
    catch (RuntimeException $error) { expectAccount(!$error instanceof LogicException, $error->getMessage()); }
}
$grant = platformIssueElevationGrant('account-beta', $identity, 1300);
expectAccount(platformVerifyElevationGrant($grant['token'], 'account-beta', $identity, 1299) === 1300,
    'A verified elevation grant remains usable until its deadline.');
foreach ([['account-gamma', $identity, 1299], ['account-beta', ['id' => 13, 'auth_version' => 3], 1299],
    ['account-beta', ['id' => 12, 'auth_version' => 4], 1299], ['account-beta', $identity, 1300]] as [$key, $actor, $now]) {
    try {
        platformVerifyElevationGrant($grant['token'], $key, $actor, $now);
        throw new LogicException('An unrelated or expired elevation grant was accepted.');
    } catch (RuntimeException $expected) {
        expectAccount(!$expected instanceof LogicException, $expected->getMessage());
    }
}
foreach (['', substr($grant['token'], 0, -8) . 'AAAAAAAA', \Dnr\Security\ApplicationKey::seal('{"purpose":"other"}')] as $token) {
    try {
        platformVerifyElevationGrant($token, 'account-beta', $identity, 1299);
        throw new LogicException('An invalid elevation grant was accepted.');
    } catch (RuntimeException $expected) {
        expectAccount(!$expected instanceof LogicException, $expected->getMessage());
    }
}
echo "Account authority, identity, origin, proxy-login, configuration, and platform elevation tests passed.\n";
