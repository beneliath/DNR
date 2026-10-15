<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/two_factor_helpers.php';
require_once __DIR__ . '/../src/functions.php';
putenv('DNR_ACCOUNTS_ENABLED=0');

function expectUnlockReturn(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$exact = 'view_engagement.php?id=42&return_to=' . rawurlencode('engagements.php?search=one..two&cursor=' . str_repeat('a', 900)) . '#engagement-tasks';
expectUnlockReturn(safeAdminElevationReturnUrl($exact) === $exact, 'Long list context and fragments must survive validation.');
$urlFilter = 'audit_log.php?search=' . rawurlencode('https://example.org/a..b') . '#audit-retention';
expectUnlockReturn(safeAdminElevationReturnUrl($urlFilter) === $urlFilter, 'URLs and dots in search filters are data, not redirect paths.');
foreach ([null, [], '//outside.example/a.php', 'https://outside.example/a.php', '/users.php', '../users.php',
    'folder/users.php', 'users.php' . "\r\nX-Test: injected", 'admin_elevation.php?return=users.php',
    'delete_user.php', 'admin_lock.php', 'users.php?' . str_repeat('x', 6000)] as $unsafe) {
    expectUnlockReturn(safeAdminElevationReturnUrl($unsafe, 'dashboard.php') === 'dashboard.php', 'Unsafe, recursive, or action-only return must fall back.');
}

$_SERVER = ['REQUEST_METHOD' => 'POST', 'PHP_SELF' => '/dnr/tasks.php', 'HTTP_HOST' => 'app.example', 'HTTPS' => 'on',
    'HTTP_REFERER' => 'https://app.example/dnr/view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2'];
$_POST = ['_admin_unlock_return' => $exact];
expectUnlockReturn(adminElevationRequestReturnUrl('tasks.php') === $exact, 'Explicit browser context must take priority, including its tab fragment.');
$_POST = [];
expectUnlockReturn(adminElevationRequestReturnUrl('tasks.php') === 'view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2', 'A separate POST endpoint returns to its originating record.');
$_SERVER['HTTP_REFERER'] = 'https://app.example/dnr/edit_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2';
expectUnlockReturn(adminElevationRequestReturnUrl('edit_engagement.php?id=42#chron-log') === 'edit_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2#chron-log', 'Server action sections augment the full originating query.');
foreach (['https://outside.example/dnr/users.php', 'http://app.example/dnr/users.php',
    'https://app.example:444/dnr/users.php', 'https://app.example/another/users.php',
    'https://app.example/dnr/admin_elevation.php', 'https://app.example/dnr/delete_user.php'] as $referrer) {
    $_SERVER['HTTP_REFERER'] = $referrer;
    expectUnlockReturn(adminElevationRequestReturnUrl('users.php') === 'users.php', 'Only a screen in the same application origin and directory can be a referrer fallback.');
}
$_SERVER = ['REQUEST_METHOD' => 'GET', 'PHP_SELF' => '/bulk_delete.php', 'QUERY_STRING' => 'selection=tab-specific-token'];
expectUnlockReturn(adminElevationRequestReturnUrl('bulk_delete.php?selection=tab-specific-token') === 'bulk_delete.php?selection=tab-specific-token', 'Protected GET screens resume their own activity rather than a previous page.');
$_SERVER = ['REQUEST_METHOD' => 'POST', 'PHP_SELF' => '/contacts.php', 'QUERY_STRING' => 'status=archived&search=Test&per_page=25&cursor=next'];
expectUnlockReturn(adminElevationRequestReturnUrl('contacts.php?status=archived') === 'contacts.php?status=archived&search=Test&per_page=25&cursor=next', 'Request query survives when the browser omits the referrer.');

$previousEnvironment = [];
foreach (['DNR_ACCOUNTS_ENABLED', 'DNR_ACCOUNT_KEY', 'DNR_PUBLIC_BASE_URL'] as $key) {
    $previousEnvironment[$key] = getenv($key);
}
try {
    putenv('DNR_ACCOUNTS_ENABLED=1');
    putenv('DNR_ACCOUNT_KEY=test-account');
    putenv('DNR_PUBLIC_BASE_URL=https://app.example/a/test-account');
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'PHP_SELF' => '/tasks.php', 'HTTP_HOST' => 'app.example', 'HTTPS' => 'on',
        'HTTP_REFERER' => 'https://app.example/a/test-account/view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2'];
    $_POST = [];
    expectUnlockReturn(adminElevationRequestReturnUrl('tasks.php') === 'view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2', 'A stripped backend path must preserve the current account referrer.');
    foreach (['https://app.example/a/another-account/view_engagement.php?id=42',
        'https://app.example/view_engagement.php?id=42',
        'https://app.example/a/test-account-extra/view_engagement.php?id=42',
        'https://outside.example/a/test-account/view_engagement.php?id=42'] as $referrer) {
        $_SERVER['HTTP_REFERER'] = $referrer;
        expectUnlockReturn(adminElevationRequestReturnUrl('tasks.php') === 'tasks.php', 'Account referrer fallback must reject root, sibling accounts, and other origins.');
    }
} finally {
    foreach ($previousEnvironment as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
}
echo "Admin unlock return tests passed.\n";
