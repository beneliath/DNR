<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/account_helpers.php';
require_once __DIR__ . '/../src/email_helpers.php';
require_once __DIR__ . '/../src/mattermost_integration_helpers.php';
require_once __DIR__ . '/../src/calendar_helpers.php';
require_once __DIR__ . '/../src/short_link_helpers.php';

function expectAccountUrl(string $actual, string $expected): void
{
    if ($actual !== $expected) throw new RuntimeException('Expected ' . $expected . '; got ' . $actual);
}

putenv('DNR_ACCOUNTS_ENABLED=1');
putenv('DNR_REQUIRE_HTTPS=0');
foreach ([
    ['primary', 'shalom-in-messiah', 'https://moed.example.com', '/a/shalom-in-messiah'],
    ['member', 'test-account', 'https://moed.example.com', '/a/test-account'],
    ['primary', 'shalom-in-messiah', 'http://localhost:8080', ''],
] as [$mode, $key, $origin, $path]) {
    putenv('DNR_ACCOUNT_MODE=' . $mode);
    putenv('DNR_ACCOUNT_KEY=' . $key);
    putenv('DNR_PUBLIC_BASE_URL=' . $origin . $path . '/');
    putenv('DNR_PRIMARY_PUBLIC_URL=' . $origin . ($path === '' ? '' : '/a/shalom-in-messiah'));
    expectAccountUrl(accountPublicPath(), $path);
    expectAccountUrl(accountSignInUrl(), $origin . '/login.php');
    expectAccountUrl(accountCookiePath(), $path . '/');
    expectAccountUrl(accountCookiePath(true), $mode === 'primary' ? '/' : $path . '/');
    expectAccountUrl(applicationPublicUrl('accept_invitation.php', ['token' => 'test']), $origin . $path . '/accept_invitation.php?token=test');
    expectAccountUrl(applicationPublicUrl('assets/images/logo.png'), $origin . $path . '/assets/images/logo.png');
    expectAccountUrl(mattermostPublicUrl('view_engagement.php', ['id' => 9]), $origin . $path . '/view_engagement.php?id=9');
    expectAccountUrl(calendarSubscriptionUrl(['SCRIPT_NAME' => '/calendar.php'], 'test'), $origin . $path . '/calendar.php?token=test');
    expectAccountUrl(shortLinkUrl('0123456789abcdef'), $origin . $path . '/surls/0123456789abcdef');
}
echo "Account email, Mattermost, calendar, short-link, login, and cookie paths passed.\n";
