<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/account_helpers.php';
require_once __DIR__ . '/../src/email_helpers.php';
require_once __DIR__ . '/../src/mattermost_integration_helpers.php';
require_once __DIR__ . '/../src/calendar_helpers.php';
require_once __DIR__ . '/../src/short_link_helpers.php';
require_once __DIR__ . '/../src/audience_store.php';

function expectAccountUrl(string $actual, string $expected): void
{
    if ($actual !== $expected) throw new RuntimeException('Expected ' . $expected . '; got ' . $actual);
}

putenv('DNR_ACCOUNTS_ENABLED=1');
putenv('DNR_REQUIRE_HTTPS=0');
foreach ([
    ['primary', 'shalom-in-messiah', 'https://moed.example.com', '/a/shalom-in-messiah'],
    ['member', 'test-account', 'https://moed.example.com', '/a/test-account'],
    ['member', 'aleph', 'https://moed.example.com', '/a/aleph'],
    ['primary', 'shalom-in-messiah', 'http://localhost:8080', ''],
] as [$mode, $key, $origin, $path]) {
    putenv('DNR_ACCOUNT_MODE=' . $mode);
    putenv('DNR_ACCOUNT_KEY=' . $key);
    putenv('DNR_PUBLIC_BASE_URL=' . $origin . $path . '/');
    putenv('DNR_PRIMARY_PUBLIC_URL=' . $origin . ($path === '' ? '' : '/a/shalom-in-messiah'));
    expectAccountUrl(accountPublicPath(), $path);
    expectAccountUrl(currentAccountPublicUrl(), $origin . $path);
    expectAccountUrl(accountSignInUrl(), $origin . '/login.php');
    expectAccountUrl(accountCookiePath(), $path . '/');
    expectAccountUrl(accountCookiePath(true), $mode === 'primary' ? '/' : $path . '/');
    expectAccountUrl(applicationPublicUrl('accept_invitation.php', ['token' => 'test']), $origin . $path . '/accept_invitation.php?token=test');
    expectAccountUrl(applicationPublicUrl('assets/images/logo.png'), $origin . $path . '/assets/images/logo.png');
    expectAccountUrl(mattermostPublicUrl('view_engagement.php', ['id' => 9]), $origin . $path . '/view_engagement.php?id=9');
    expectAccountUrl(calendarSubscriptionUrl(['SCRIPT_NAME' => '/calendar.php'], 'test'), $origin . $path . '/calendar.php?token=test');
    expectAccountUrl(shortLinkUrl('0123456789abcdef'), $origin . $path . '/surls/0123456789abcdef');
    if (str_starts_with($origin, 'https://')) {
        $snapshot = ['base'=>currentAccountPublicUrl(), 'type'=>'plain-text',
            'identity'=>['LIST:COMPANY'=>'Fixture', 'LIST:ADDRESS'=>'123 Fixture Street'],
            'draft'=>['subject'=>'Account URL fixture', 'from_name'=>'Fixture', 'from_email'=>'sender@example.test', 'text'=>'Account URL fixture']];
        $message = audienceDispatchMessage($snapshot, ['EMAIL'=>'recipient@example.test'], str_repeat('a', 64), '<url@example.test>');
        expectAccountUrl($message['unsubscribe'], $origin . $path . '/audience_subscription.php?token=' . str_repeat('a', 64));
        if (!str_contains($message['text'], $message['unsubscribe'] . '&preferences=1')) throw new RuntimeException('Preferences must stay in the recipient Account.');
        $snapshot['base'] = accountPrimaryPublicUrl();
        $legacyMessage = audienceDispatchMessage($snapshot, ['EMAIL'=>'recipient@example.test'], str_repeat('a', 64), '<legacy-url@example.test>');
        expectAccountUrl($legacyMessage['unsubscribe'], $message['unsubscribe']);
        $sesSettings = ['result_json'=>'{}', 'check_state'=>'unconfigured', 'subscription_arn'=>'', 'version'=>1,
            'region'=>'us-east-1', 'from_email'=>'', 'configuration_set'=>'', 'topic_arn'=>'', 'access_key_tail'=>'',
            'error_message'=>'', 'checked_at'=>null, 'aws_account_id'=>'', 'last_event_at'=>null];
        $csrf = ''; $h = static fn($value)=>htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $url = static fn(array $query)=>'audience.php?' . http_build_query($query);
        $canConfigureDelivery = false; $sesInboxCounts = []; $_GET['tab'] = 'events';
        ob_start();
        require __DIR__ . '/../src/templates/audience/delivery-ses.php';
        $deliveryPage = ob_get_clean();
        if (!str_contains($deliveryPage, $origin . $path . '/audience_ses_events.php')) throw new RuntimeException('The displayed SNS endpoint must stay in the current Account.');
    }
}
putenv('DNR_PUBLIC_BASE_URL');
try { currentAccountPublicUrl(); throw new RuntimeException('A missing current Account URL must not fall back to the primary Account.'); }
catch (InvalidArgumentException) {}
echo "Account email, Mattermost, calendar, short-link, login, and cookie paths passed.\n";
