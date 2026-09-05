<?php

// This runs only inside the isolated ingress fixture, never against a live app.
if (getenv('DNR_DEPLOYMENT_NOTICE_HTTP_TEST') !== '1') {
    echo "Deployment notice HTTP tests skipped (requires isolated ingress fixture).\n";
    exit;
}

function noticeHttp(string $method = 'GET'): array
{
    $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 1, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1/deployment_status.php', false, $context);
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
    if (in_array($phase, ['complete', 'cancelled'], true)) {
        checkNoticeHttp($payload['notice'] === null, 'Terminal notices should disappear.');
    } else {
        checkNoticeHttp($payload['notice']['phase'] === $phase, 'Notice phase was lost.');
    }
}
[, $headers] = noticeHttp('POST');
checkNoticeHttp(str_contains($headers[0] ?? '', '405'), 'Public endpoint must reject mutations.');
echo "Deployment notice HTTP tests passed without application or database services.\n";
