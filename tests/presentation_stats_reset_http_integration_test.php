<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Presentation statistics reset HTTP tests skipped (disposable server required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/short_link_helpers.php';
require_once $source . '/two_factor_helpers.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Loopback server required.');
}
function expectStatsReset(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$request = static function (string $path, ?array $post = null, string $cookie = '') use ($base): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36']);
    if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException(curl_error($curl));
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($raw, 0, $length);
    preg_match('/^Set-Cookie:\s*(' . preg_quote(session_name(), '/') . '=[^;\r\n]+)/mi', $headers, $sessionMatch);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers,
        'body' => substr($raw, $length), 'cookie' => $sessionMatch[1] ?? $cookie];
};
$userIds = [];
$sessionIds = [];
$engagementId = 0;
$organizationId = 0;
try {
    $speakerId = (int) $conn->query('SELECT id FROM speakers ORDER BY id LIMIT 1')->fetch_assoc()['id'];
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Statistics reset HTTP fixture')");
    $organizationId = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id,event_title,event_type,confirmation_status,event_start_date,event_end_date)
        VALUES ($organizationId,'Statistics reset HTTP fixture','conference','under_review','2026-10-01','2026-10-02')");
    $engagementId = (int) $conn->insert_id;
    $conn->query("INSERT INTO presentations (engagement_id,speaker_id,topic_title,expected_attendance)
        VALUES ($engagementId,$speakerId,'Reset target',350)");
    $presentationId = (int) $conn->insert_id;
    $conn->query("INSERT INTO presentations (engagement_id,speaker_id,topic_title)
        VALUES ($engagementId,$speakerId,'Keep these statistics')");
    $otherId = (int) $conn->insert_id;
    $linkIds = [];
    foreach ([[$presentationId, 'website', 1], [$presentationId, 'notes', 0], [$otherId, 'website', 1]] as [$parentId, $type, $enabled]) {
        $code = bin2hex(random_bytes(8));
        $target = $type === 'notes' ? null : 'https://example.org/preserved';
        $stmt = $conn->prepare('INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type,target_url,is_enabled) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('siiissi', $code, $engagementId, $parentId, $speakerId, $type, $target, $enabled);
        $stmt->execute();
        $linkId = (int) $conn->insert_id;
        $linkIds[] = $linkId;
        storeShortLinkQrImages($conn, $linkId, $code);
        foreach (['2020-01-01 01:00:00', gmdate('Y-m-d H:00:00')] as $hour) {
            $stmt = $conn->prepare("INSERT INTO short_link_stats (link_id,visit_hour,browser,os,country,referrer,visits)
                VALUES (?,?,'Chrome','Windows','US','example.org',7)");
            $stmt->bind_param('is', $linkId, $hour);
            $stmt->execute();
        }
    }
    $pdf = "%PDF-1.4\nRetain this PDF\n%%EOF\n";
    applyPresentationNotesChange($conn, $presentationId, $engagementId, ['action' => 'replace',
        'asset' => ['data' => $pdf, 'filename' => 'preserved.pdf', 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf, true)]]);
    $snapshot = static fn(): array => [
        $conn->query("SELECT * FROM presentations WHERE engagement_id=$engagementId ORDER BY id")->fetch_all(MYSQLI_ASSOC),
        $conn->query("SELECT * FROM short_links WHERE engagement_id=$engagementId ORDER BY id")->fetch_all(MYSQLI_ASSOC),
        $conn->query("SELECT q.* FROM short_link_qr_images q JOIN short_links l ON l.id=q.link_id WHERE l.engagement_id=$engagementId ORDER BY q.link_id")->fetch_all(MYSQLI_ASSOC),
        $conn->query("SELECT * FROM presentation_notes WHERE presentation_id=$presentationId")->fetch_all(MYSQLI_ASSOC),
    ];
    $before = $snapshot();
    $targetVisits = static fn(): int => (int) $conn->query("SELECT COALESCE(SUM(v.visits),0) AS n FROM short_link_stats v JOIN short_links l ON l.id=v.link_id WHERE l.presentation_id=$presentationId")->fetch_assoc()['n'];
    $path = 'reset_presentation_stats.php?presentation_id=' . $presentationId;
    expectStatsReset($request($path)['status'] === 302 && $targetVisits() === 28, 'Anonymous requests must not reset statistics');
    foreach (['editor', 'reviewer', 'admin'] as $role) {
        $name = 'stats-reset-' . bin2hex(random_bytes(5));
        $password = 'StatisticsReset!' . bin2hex(random_bytes(8));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username,password,role) VALUES (?,?,?)');
        $stmt->bind_param('sss', $name, $hash, $role);
        $stmt->execute();
        $userId = (int) $conn->insert_id;
        $userIds[] = $userId;
        startSecureSession();
        $_SESSION = ['user_id' => $userId, 'username' => $name, 'role' => $role, 'authenticated_role' => $role,
            'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        $csrf = $_SESSION['_csrf_token'];
        $sessionIds[] = session_id();
        $cookie = session_name() . '=' . session_id();
        session_write_close();
        $post = ['csrf_token' => $csrf, 'action' => 'reset_statistics'];
        $view = $request('view_engagement.php?id=' . $engagementId, null, $cookie);
        expectStatsReset(str_contains($view['body'], $path) === ($role === 'admin'), 'Only admins see presentation reset controls: ' . $role);
        if ($role !== 'admin') {
            expectStatsReset($request($path, null, $cookie)['status'] === 403 && $request($path, $post, $cookie)['status'] === 403 && $targetVisits() === 28, 'Non-admin GET and POST must be denied');
            continue;
        }
        $gate = $request($path, $post, $cookie);
        expectStatsReset($gate['status'] === 302 && str_contains($gate['headers'], 'Location: admin_elevation.php?') && $targetVisits() === 28, 'An admin session alone must not reset statistics');
        $elevationPost = ['csrf_token' => $csrf, 'return' => $path, 'admin_password' => $password, 'admin_code' => ''];
        $noMfa = $request('admin_elevation.php', $elevationPost, $cookie);
        expectStatsReset($noMfa['status'] === 200 && str_contains($noMfa['body'], 'was not accepted'), 'Admins without configured 2FA cannot unlock');
        $secret = generateTotpSecret();
        enableTwoFactorForUser($conn, $userId, $secret, 0, 1);
        session_id(explode('=', $cookie, 2)[1]);
        startSecureSession();
        $_SESSION['auth_version'] = 2;
        session_write_close();
        $badPassword = $request('admin_elevation.php', array_replace($elevationPost, ['admin_password' => 'wrong', 'admin_code' => createTotp($secret, $name)->now()]), $cookie);
        expectStatsReset(str_contains($badPassword['body'], 'was not accepted') && $targetVisits() === 28, 'Wrong passwords cannot authorize a reset');
        $badCode = $request('admin_elevation.php', $elevationPost, $cookie);
        expectStatsReset(str_contains($badCode['body'], 'was not accepted') && $targetVisits() === 28, 'Password without a second factor cannot authorize a reset');
        $unlock = $request('admin_elevation.php', array_replace($elevationPost, ['admin_code' => createTotp($secret, $name)->now()]), $cookie);
        expectStatsReset($unlock['status'] === 302 && str_contains($unlock['headers'], 'Location: ' . $path), 'Password plus fresh TOTP must unlock the confirmation page');
        $cookie = $unlock['cookie'];
        $sessionIds[] = explode('=', $cookie, 2)[1];
        $confirmation = $request($path, null, $cookie);
        expectStatsReset($confirmation['status'] === 200 && str_contains($confirmation['body'], 'Reset Statistics to Zero') && $targetVisits() === 28, 'GET after elevation must only show confirmation');
        preg_match('/name="csrf_token" value="([^"]+)"/', $confirmation['body'], $csrfMatch);
        $post['csrf_token'] = $csrfMatch[1] ?? '';
        expectStatsReset($request($path, array_replace($post, ['csrf_token' => 'invalid']), $cookie)['status'] === 400 && $targetVisits() === 28, 'Elevated POST still requires valid CSRF');
        expectStatsReset($request('reset_presentation_stats.php?presentation_id[]=1', null, $cookie)['status'] === 400, 'Array-shaped presentation IDs must be rejected');
        expectStatsReset($request('reset_presentation_stats.php?presentation_id=2147483647', null, $cookie)['status'] === 404, 'Missing presentations must not affect existing statistics');
        session_id(explode('=', $cookie, 2)[1]);
        startSecureSession();
        $_SESSION['_admin_elevated_at'] = time() - 301;
        session_write_close();
        expectStatsReset($request($path, $post, $cookie)['status'] === 302 && $targetVisits() === 28, 'Expired elevation must require reauthentication without resetting');
        session_id(explode('=', $cookie, 2)[1]);
        startSecureSession();
        $_SESSION['_admin_elevated_at'] = time();
        session_write_close();
        $done = $request($path, $post, $cookie);
        expectStatsReset($done['status'] === 302 && str_contains($done['headers'], 'Location: short_links.php?presentation_id=' . $presentationId), 'Confirmed reset must return to presentation statistics');
        expectStatsReset($targetVisits() === 0, 'All dates and disabled link counts must reset to zero');
        expectStatsReset((int) $conn->query('SELECT SUM(visits) AS n FROM short_link_stats WHERE link_id=' . $linkIds[2])->fetch_assoc()['n'] === 14, 'Other presentations retain all statistics');
        expectStatsReset($snapshot() === $before, 'Reset must preserve presentations, links, destinations, QR bytes, notes, and timestamps');
        $audit = $conn->query("SELECT actor_user_id,entity_id FROM security_audit_log WHERE event_type='presentation_statistics_reset' AND entity_id=$presentationId")->fetch_all(MYSQLI_ASSOC);
        expectStatsReset(count($audit) === 1 && (int) $audit[0]['actor_user_id'] === $userId, 'The reset must create exactly one attributed audit event');
        $report = $request('short_links.php?presentation_id=' . $presentationId, null, $cookie);
        expectStatsReset(str_contains($report['body'], 'Presentation statistics reset to zero.') && str_contains($report['body'], 'No tracked visits in this period'), 'The report must show the successful reset and empty statistics');
        $webCode = $before[1][0]['code'];
        expectStatsReset($request('surls/' . $webCode)['status'] === 302 && $targetVisits() === 1, 'Existing public links must work and new visits must count after reset');
        try {
            resetPresentationShortLinkStats($conn, $presentationId, 2147483647);
            throw new LogicException('Reset unexpectedly accepted an invalid audit actor');
        } catch (mysqli_sql_exception $expected) {
            expectStatsReset($targetVisits() === 1, 'An audit failure must roll back the statistics deletion');
        }
    }
} finally {
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
echo "Presentation statistics reset HTTP integration tests passed.\n";
