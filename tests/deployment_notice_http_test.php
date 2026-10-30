<?php

// This runs only inside the isolated ingress fixture, never against a live app.
if (getenv('DNR_DEPLOYMENT_NOTICE_HTTP_TEST') !== '1') {
    echo "Deployment notice HTTP tests skipped (requires isolated ingress fixture).\n";
    exit;
}

function noticeHttp(string $method = 'GET', string $path = '/deployment_status.php', string $requestHeaders = ''): array
{
    $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 1, 'ignore_errors' => true, 'header' => $requestHeaders]]);
    $body = @file_get_contents('http://127.0.0.1' . $path, false, $context);
    return [$body, http_get_last_response_headers() ?: []];
}

function checkNoticeHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

for ($attempt = 0; $attempt < 50; $attempt++) {
    [$body] = noticeHttp();
    if ($body !== false) {
        break;
    }
    usleep(100000);
}
checkNoticeHttp($body !== false, 'Ingress did not start.');
$now = time();
$state = ['id' => str_repeat('a', 32), 'phase' => 'pending', 'started_at' => $now - 180,
    'not_before' => $now + 120, 'expires_at' => $now + 21600, 'commit' => 'private-commit'];
foreach (['preparing', 'pending', 'deploying', 'failed', 'complete', 'cancelled'] as $phase) {
    $state['phase'] = $phase;
    $state['countdown_started_at'] = $phase === 'preparing' ? null : $now - 180;
    $state['not_before'] = $phase === 'preparing' ? null : $now + 120;
    file_put_contents('/run/dnr/deployment/notice.json', json_encode($state));
    [$body, $headers] = noticeHttp();
    $payload = json_decode($body, true);
    $headerText = strtolower(implode("\n", $headers));
    checkNoticeHttp(str_contains($headers[0] ?? '', '200'), 'Notice must work without a web/database container.');
    checkNoticeHttp(str_contains($headerText, 'cache-control: no-store'), 'Status must not be cached.');
    checkNoticeHttp(!str_contains($headerText, 'set-cookie:'), 'Status must not create or refresh sessions.');
    checkNoticeHttp(!str_contains($body, 'private-commit'), 'Source details must not be exposed.');
    checkNoticeHttp(isset($payload['server_now']), 'Server time is required.');
    checkNoticeHttp($payload['deployment_complete'] === ($phase === 'complete'), 'Only the verified deployment may release waiting pages.');
    if (in_array($phase, ['complete', 'cancelled'], true)) {
        checkNoticeHttp($payload['notice'] === null, 'Terminal notices should disappear.');
    } else {
        checkNoticeHttp($payload['notice']['phase'] === $phase, 'Notice phase was lost.');
    }
}
[, $headers] = noticeHttp('POST');
checkNoticeHttp(str_contains($headers[0] ?? '', '405'), 'Public endpoint must reject mutations.');
$state['phase'] = 'deploying';
$state['expected_back_at'] = $now + 2700;
file_put_contents('/run/dnr/deployment/notice.json', json_encode($state));
foreach (['/login.php', '/a/test-account/engagement.php', '/direct/content.pdf'] as $path) {
    [$body, $headers] = noticeHttp('GET', $path);
    $headerText = strtolower(implode("\n", $headers));
    checkNoticeHttp(str_contains($headers[0] ?? '', '503'), 'Unavailable pages must return HTTP 503: ' . ($headers[0] ?? '') . ' ' . substr((string) $body, 0, 200));
    checkNoticeHttp(str_contains($body, 'Temporarily unavailable') && str_contains($body, 'Clock and workspace illustration') && str_contains($body, 'Estimated return')
        && !str_contains($body, 'aria-label="MOED logo"') && str_contains($body, '<svg'), 'Maintenance page must show the illustration and return estimate without a logo.');
    checkNoticeHttp(!str_contains($body, 'http-equiv="refresh"')
        && str_contains($body, 'data-maintenance-timer') && str_contains($body, 'data-ready-url="/ready.php"')
        && str_contains($body, 'data-status-url="/deployment_status.php"') && str_contains($body, 'data-wait-for-deployment="true"')
        && str_contains($body, 'src="/assets/js/maintenance-timer.js?v='), 'Maintenance page must load the animated timer and readiness check.');
    checkNoticeHttp(str_contains($headerText, 'cache-control: no-store') && !str_contains($headerText, 'set-cookie:'), 'Maintenance page must not cache or touch sessions.');
}
[$script, $scriptHeaders] = noticeHttp('GET', '/assets/js/maintenance-timer.js');
checkNoticeHttp(str_contains($scriptHeaders[0] ?? '', '200') && str_contains((string) $script, 'performance.now()')
    && str_contains((string) $script, 'window.setInterval(checkReady, 30000)'),
    'Ingress must serve the countdown script with a 30 second readiness check without the application.');
$timerVersion = substr(hash('sha256', (string) $script), 0, 12);
checkNoticeHttp(str_contains((string) $body, 'src="/assets/js/maintenance-timer.js?v=' . $timerVersion . '"'),
    'Maintenance page must version the timer script so browsers load new behavior after deployment.');
[$accountBody] = noticeHttp('GET', '/profile.php', 'X-Forwarded-Prefix: /a/test-account');
checkNoticeHttp(str_contains((string) $accountBody, 'src="/a/test-account/assets/js/maintenance-timer.js?v=' . $timerVersion . '"'), 'Account maintenance page must load its scoped timer script.');
checkNoticeHttp(str_contains((string) $accountBody, 'data-ready-url="/a/test-account/ready.php"'), 'Account maintenance page must check its own readiness.');
checkNoticeHttp(str_contains((string) $accountBody, 'data-status-url="/a/test-account/deployment_status.php"'), 'Completion must use the same Account route as readiness.');
$state['started_at'] = $now - 360;
$state['countdown_started_at'] = $now - 360;
$state['not_before'] = $now - 60;
$state['expected_back_at'] = $now - 30;
file_put_contents('/run/dnr/deployment/notice.json', json_encode($state));
[$body] = noticeHttp('GET', '/login.php');
checkNoticeHttp(str_contains((string) $body, 'class="timer timer-expired"')
    && str_contains((string) $body, 'data-remaining-seconds="0"')
    && str_contains((string) $body, '>0:00:00</div>')
    && str_contains((string) $body, 'The estimate has passed. Please check back soon.')
    && str_contains((string) $body, 'src="/assets/js/maintenance-timer.js?v=' . $timerVersion . '"'),
    'An elapsed estimate must keep a red flashing zero timer and continue checking readiness.');
$state['phase'] = 'failed';
file_put_contents('/run/dnr/deployment/notice.json', json_encode($state));
[$body] = noticeHttp('GET', '/login.php');
checkNoticeHttp(str_contains($body, 'taking longer than expected') && !str_contains($body, 'Estimated return'), 'Failed deployments must not promise an ETA.');
checkNoticeHttp(str_contains($body, 'data-wait-for-deployment="true"') && str_contains($body, '/assets/js/maintenance-timer.js?v=')
    && !str_contains($body, 'http-equiv="refresh"'), 'Failed deployments must poll safely even without a countdown.');
unlink('/run/dnr/deployment/notice.json');
[$body] = noticeHttp('GET', '/login.php');
checkNoticeHttp(str_contains($body, 'data-wait-for-deployment="false"') && str_contains($body, '/assets/js/maintenance-timer.js?v=')
    && !str_contains($body, 'http-equiv="refresh"'), 'An unplanned outage must also recover without a blind page refresh.');
echo "Deployment notice HTTP tests passed without application or database services.\n";
