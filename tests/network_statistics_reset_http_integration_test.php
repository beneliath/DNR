<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Network statistics reset HTTP tests skipped (disposable server required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/network_diagnostics_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Loopback server required.');
}

function expectNetworkReset(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$request = static function (string $path, ?array $post = null, string $cookie = '', ?string $method = null) use ($base): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 Network Reset Integration Test']);
    if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    if ($method !== null) curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException(curl_error($curl));
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $length);
    preg_match('/^Set-Cookie:\s*(' . preg_quote(session_name(), '/') . '=[^;\r\n]+)/mi', $headers, $sessionMatch);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers,
        'body' => substr($raw, $length), 'cookie' => $sessionMatch[1] ?? $cookie];
};
$sampleCount = static fn(): int => (int) $conn->query('SELECT COUNT(*) AS n FROM network_performance_samples')->fetch_assoc()['n'];
expectNetworkReset($sampleCount() === 0, 'Use an empty disposable telemetry table for reset coverage.');
$userIds = [];
$sessionIds = [];
$organizationId = 0;
$engagementId = 0;
$path = 'network_diagnostics.php';
try {
    $speakerId = (int) $conn->query('SELECT id FROM speakers ORDER BY id LIMIT 1')->fetch_assoc()['id'];
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Network reset fixture')");
    $organizationId = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements
        (organization_id,event_title,event_type,confirmation_status,event_start_date,event_end_date)
        VALUES ($organizationId,'Network reset fixture','conference','under_review','2026-10-01','2026-10-02')");
    $engagementId = (int) $conn->insert_id;
    $conn->query("INSERT INTO presentations (engagement_id,speaker_id,topic_title)
        VALUES ($engagementId,$speakerId,'Preserve QR statistics')");
    $presentationId = (int) $conn->insert_id;
    $code = bin2hex(random_bytes(8));
    $conn->query("INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type,target_url)
        VALUES ('$code',$engagementId,$presentationId,$speakerId,'website','https://example.org')");
    $linkId = (int) $conn->insert_id;
    $conn->query("INSERT INTO short_link_stats (link_id,visit_hour,browser,os,country,referrer,visits)
        VALUES ($linkId,'2026-09-01 12:00:00','Chrome','Windows','US','example.org',7)");
    foreach (['IPv4', 'IPv6'] as $family) {
        foreach ([0, 2] as $daysAgo) {
            $conn->query("INSERT INTO network_performance_samples
                (address_family,page_path,ttfb_ms,dom_content_loaded_ms,load_ms,
                 image_count,image_max_ms,contact_image_count,contact_image_max_ms,recorded_at)
                VALUES ('$family','contacts.php',40,100,200,2,90,1,70,UTC_TIMESTAMP(6) - INTERVAL $daysAgo DAY)");
        }
    }
    expectNetworkReset($request($path, ['action' => 'reset_statistics'])['status'] === 302
        && $sampleCount() === 4, 'Anonymous requests cannot reset statistics.');

    foreach (['editor', 'reviewer', 'admin'] as $role) {
        $name = 'network-reset-' . bin2hex(random_bytes(5));
        $password = 'NetworkReset!' . bin2hex(random_bytes(8));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username,password,role) VALUES (?,?,?)');
        $stmt->bind_param('sss', $name, $hash, $role);
        $stmt->execute();
        $userId = (int) $conn->insert_id;
        $userIds[] = $userId;
        $secret = generateTotpSecret();
        enableTwoFactorForUser($conn, $userId, $secret, 0, 1);
        startSecureSession();
        $_SESSION = ['user_id' => $userId, 'username' => $name, 'role' => $role,
            'authenticated_role' => $role, 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        completeIntegrationTestMfaSession();
        $csrf = $_SESSION['_csrf_token'];
        $sessionIds[] = session_id();
        $cookie = session_name() . '=' . session_id();
        session_write_close();
        $post = ['csrf_token' => $csrf, 'action' => 'reset_statistics'];

        if ($role !== 'admin') {
            expectNetworkReset($request($path, null, $cookie)['status'] === 403
                && $request($path, $post, $cookie)['status'] === 403 && $sampleCount() === 4,
                'Non-admin GET and POST requests must be denied: ' . $role);
            continue;
        }

        $locked = $request($path, null, $cookie);
        expectNetworkReset($locked['status'] === 200
            && str_contains($locked['body'], 'admin_elevation.php?return=network_diagnostics.php')
            && !str_contains($locked['body'], 'class="network-statistics-reset-form"')
            && !str_contains($locked['body'], 'data-admin-unlock ')
            && $sampleCount() === 4, 'The locked page must offer unlocking without clearing data.');
        $gate = $request($path, $post, $cookie);
        expectNetworkReset($gate['status'] === 302
            && str_contains($gate['headers'], 'Location: admin_elevation.php?return=network_diagnostics.php')
            && $sampleCount() === 4, 'Direct POST cannot bypass administrator elevation.');

        $unlock = $request('admin_elevation.php', ['csrf_token' => $csrf, 'return' => $path,
            'admin_password' => $password, 'admin_code' => createTotp($secret, $name)->now()], $cookie);
        expectNetworkReset($unlock['status'] === 302 && str_contains($unlock['headers'], 'Location: ' . $path),
            'A password and fresh second factor unlock the network page.');
        $cookie = $unlock['cookie'];
        $sessionIds[] = explode('=', $cookie, 2)[1];
        $unlocked = $request($path, null, $cookie);
        expectNetworkReset($unlocked['status'] === 200 && $sampleCount() === 4
            && str_contains($unlocked['body'], 'class="network-statistics-reset-form"')
            && str_contains($unlocked['body'], 'data-confirm="Clear all recorded IPv4 and IPv6')
            && str_contains($unlocked['body'], 'data-admin-unlock-timer')
            && str_contains($unlocked['body'], 'assets/js/admin-unlock.min.js'),
            'Unlocking must only show the reset confirmation control and shared countdown.');
        preg_match('/data-expires-at="([0-9]+)" data-server-now="([0-9.]+)"/', $unlocked['body'], $timer);
        $remaining = (float) ($timer[1] ?? 0) - (float) ($timer[2] ?? 0);
        expectNetworkReset($remaining > 280 && $remaining <= 300, 'The unlock countdown must start within five minutes.');
        preg_match('/name="csrf_token" value="([^"]+)"/', $unlocked['body'], $csrfMatch);
        $post['csrf_token'] = $csrfMatch[1] ?? '';
        foreach ([['csrf_token' => 'invalid'], ['csrf_token' => ''], ['action' => 'other'], ['action' => ['reset_statistics']]] as $invalid) {
            expectNetworkReset($request($path, array_replace($post, $invalid), $cookie)['status'] === 400
                && $sampleCount() === 4, 'Invalid CSRF and action payloads must not reset statistics.');
        }
        expectNetworkReset($request($path . '?action=reset_statistics', null, $cookie)['status'] === 200
            && $sampleCount() === 4, 'GET must never reset statistics, even while unlocked.');
        expectNetworkReset($request($path, $post, $cookie, 'PUT')['status'] === 405
            && $sampleCount() === 4, 'Unsupported methods must not reset statistics.');

        session_id(explode('=', $cookie, 2)[1]);
        startSecureSession();
        $_SESSION['_admin_elevated_at'] = time() - 300;
        session_write_close();
        expectNetworkReset($request($path, $post, $cookie)['status'] === 302 && $sampleCount() === 4,
            'Elevation expires after five minutes and is enforced by the server.');
        session_id(explode('=', $cookie, 2)[1]);
        startSecureSession();
        $_SESSION['_admin_elevated_at'] = time();
        session_write_close();

        $unrelatedBefore = $conn->query('SELECT * FROM short_link_stats WHERE link_id=' . $linkId)->fetch_all(MYSQLI_ASSOC);
        $done = $request($path, $post, $cookie);
        expectNetworkReset($done['status'] === 303 && str_contains($done['headers'], 'Location: ' . $path)
            && $sampleCount() === 0, 'A valid reset clears both families, including samples outside the 24-hour view.');
        expectNetworkReset($conn->query('SELECT * FROM short_link_stats WHERE link_id=' . $linkId)->fetch_all(MYSQLI_ASSOC) === $unrelatedBefore,
            'Reset must preserve QR and short-link statistics.');
        $audit = $conn->query("SELECT actor_user_id,details FROM security_audit_log
            WHERE event_type='network_statistics_reset' AND actor_user_id=$userId")->fetch_all(MYSQLI_ASSOC);
        expectNetworkReset(count($audit) === 1 && str_contains($audit[0]['details'], 'removed 4 samples'),
            'A reset creates exactly one attributed audit event with the number of cleared samples.');
        $report = $request($path, null, $cookie);
        expectNetworkReset(str_contains($report['body'], 'Network traffic statistics cleared.')
            && str_contains($report['body'], 'data-admin-unlock-timer'), 'The page shows success and the remaining unlock countdown.');
        expectNetworkReset(!str_contains($request($path, null, $cookie)['body'], 'Network traffic statistics cleared.'),
            'Refreshing must not repeat the reset or its success message.');
        $summary = json_decode($request('network_performance.php', null, $cookie)['body'], true, 512, JSON_THROW_ON_ERROR);
        expectNetworkReset($summary['sample_count'] === 0 && $summary['pages'] === []
            && $summary['families']['IPv4']['contact_sample_count'] === 0
            && $summary['families']['IPv6']['contact_sample_count'] === 0,
            'The refreshed summary clears page and contact-image statistics for both families.');

        $sample = normalizeNetworkPerformanceSample(['page_path' => 'contacts.php', 'ttfb_ms' => 10,
            'dom_content_loaded_ms' => 20, 'load_ms' => 30, 'image_count' => 1, 'image_total_ms' => 5,
            'image_max_ms' => 5, 'contact_image_count' => 1, 'contact_image_total_ms' => 5, 'contact_image_max_ms' => 5]);
        storeNetworkPerformanceSample($conn, $sample, 'IPv6', null);
        expectNetworkReset(fetchNetworkPerformanceSummary($conn)['families']['IPv6']['sample_count'] === 1,
            'New traffic continues accumulating after reset.');
        try {
            resetNetworkPerformanceStatistics($conn, 2147483647);
            throw new LogicException('Reset unexpectedly accepted an invalid audit actor.');
        } catch (mysqli_sql_exception $expected) {
            expectNetworkReset($sampleCount() === 1, 'An audit failure must roll back deletion.');
        }
    }
} finally {
    $conn->query('DELETE FROM network_performance_samples');
    if ($engagementId > 0) $conn->query('DELETE FROM engagements WHERE id=' . $engagementId);
    if ($organizationId > 0) $conn->query('DELETE FROM organizations WHERE id=' . $organizationId);
    foreach ($userIds as $userId) $conn->query('DELETE FROM users WHERE id=' . $userId);
    foreach (array_unique($sessionIds) as $id) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        session_id($id);
        session_start();
        session_destroy();
    }
}
echo "Network statistics reset HTTP integration tests passed.\n";
