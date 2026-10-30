<?php

// Only the isolated ingress fixture mounts this script and mutable notice data.
if (getenv('DNR_DEPLOYMENT_NOTICE_HTTP_TEST') !== '1') exit;
function guardGet(string $path): array {
    $body = @file_get_contents('http://127.0.0.1' . $path, false,
        stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]));
    return [$body, http_get_last_response_headers() ?: []];
}
function guardCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$now = time();
$notice = ['id' => str_repeat('a', 32), 'phase' => 'deploying', 'started_at' => $now - 600,
    'not_before' => $now - 300, 'expected_back_at' => $now - 10, 'expires_at' => $now + 3600];
file_put_contents('/run/dnr/deployment/notice.json', json_encode($notice));
for ($attempt = 0; $attempt < 50; $attempt++) {
    [$body] = guardGet('/deployment_status.php');
    if ($body !== false) break;
    usleep(100000);
}
foreach (['', '/a/primary-account', '/a/member-account'] as $prefix) {
    foreach (['/login.php', '/audience_collect_public.php?key=synthetic', '/files/report.pdf', '/', '/unknown/path'] as $path) {
        [$body, $headers] = guardGet($prefix . $path);
        guardCheck(str_contains($headers[0] ?? '', '503'), 'Maintenance missing for ' . $prefix . $path);
        guardCheck(str_contains($body, 'data-status-url="' . $prefix . '/deployment_status.php"')
            && str_contains($body, 'timer-expired') && str_contains($body, 'Temporarily unavailable'), 'Invalid scoped maintenance page');
        $headerText = strtolower(implode("\n", $headers));
        guardCheck(str_contains($headerText, 'x-dnr-maintenance-guard: ' . str_repeat('a', 32))
            && str_contains($headerText, 'cache-control: no-store') && !str_contains($headerText, 'set-cookie:'), 'Guard must be identifiable, uncached and sessionless');
    }
    [$body, $headers] = guardGet($prefix . '/deployment_status.php');
    guardCheck(str_contains($headers[0], '200') && json_decode($body, true)['notice']['id'] === $notice['id'], 'Status missing for ' . $prefix);
    [$body, $headers] = guardGet($prefix . '/ready.php');
    guardCheck(str_contains($headers[0], '503') && json_decode($body, true)['status'] === 'not_ready', 'Guard must never announce readiness');
    [$body, $headers] = guardGet($prefix . '/assets/js/maintenance-timer.js?v=fixture');
    guardCheck(str_contains($headers[0], '200') && str_contains($body, 'window.setInterval(checkReady, 30000)'), 'Timer unavailable for ' . $prefix);
}
$notice['phase'] = 'complete';
file_put_contents('/run/dnr/deployment/notice.json', json_encode($notice));
[$body] = guardGet('/deployment_status.php');
guardCheck(json_decode($body, true)['deployment_complete'] === false, 'Only a restored app route may announce completion');
echo "Maintenance guard HTTP tests passed for root and both Account prefixes without an app/database.\n";
