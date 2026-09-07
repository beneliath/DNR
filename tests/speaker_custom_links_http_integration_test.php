<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Speaker custom link HTTP tests skipped (disposable server required).\n"; exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/presentation_helpers.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Loopback server required.');
function expectCustomLinkHttp(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$request = static function (string $path, ?array $post = null, string $cookie = '', bool $head = false) use ($base): array {
    $c = curl_init($base . '/' . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36']);
    if ($cookie !== '') curl_setopt($c, CURLOPT_COOKIE, $cookie);
    if ($post !== null) curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($post));
    if ($head) curl_setopt($c, CURLOPT_NOBODY, true);
    $response = curl_exec($c);
    expectCustomLinkHttp(is_string($response), 'HTTP request failed: ' . curl_error($c));
    $size = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
};
$input = ['name' => 'Custom HTTP Fixture', 'email' => 'custom-http@example.com', 'phone' => '+19494002892'];
$speaker = saveSpeaker($conn, $input);
$conn->query("INSERT INTO organizations (organization_name) VALUES ('Custom HTTP Fixture')"); $org = (int) $conn->insert_id;
$conn->query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
    VALUES ($org,'Custom HTTP Fixture','2026-10-01','2026-10-02','conference','under_review')"); $event = (int) $conn->insert_id;
$conn->begin_transaction();
syncEngagementPresentations($conn, $event, normalizeEngagementPresentations([
    ['speaker_id' => $speaker, 'topic_title' => 'Custom HTTP Presentation'],
    ['speaker_id' => $speaker, 'topic_title' => 'Second Custom Presentation'],
], '2026-10-01', '2026-10-02', $speaker));
$conn->commit();
$pids = array_map('intval', array_column($conn->query("SELECT id FROM presentations WHERE engagement_id=$event ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'id'));
$users = []; $sessions = [];
try {
    foreach (['editor', 'reviewer'] as $role) {
        $name = 'custom-http-' . bin2hex(random_bytes(5));
        $conn->execute_query('INSERT INTO users (username,password,role) VALUES (?,?,?)', [$name, password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT), $role]);
        $uid = (int) $conn->insert_id; $users[] = $uid;
        startSecureSession();
        $_SESSION = ['user_id' => $uid, 'username' => $name, 'role' => $role, 'authenticated_role' => $role,
            'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        $csrf = $_SESSION['_csrf_token']; $sessions[] = session_id(); $cookie = session_name() . '=' . session_id(); session_write_close();
        $post = $input + ['csrf_token' => $csrf, 'version' => 1, 'custom_links_present' => 1, 'custom_links' => [
            ['label' => 'Video <Channel>', 'url' => 'https://example.com/videos?a=1&b=2'],
            ['label' => 'Library', 'url' => 'https://example.com/library'],
        ]];
        $editUrl = 'edit_speaker.php?id=' . $speaker;
        if ($role === 'editor') {
            expectCustomLinkHttp($request($editUrl, array_replace($post, ['csrf_token' => 'bad']), $cookie)['status'] === 400, 'Custom link writes require CSRF protection.');
            $invalid = $request($editUrl, array_replace($post, ['custom_links' => [['label' => 'Keep this name', 'url' => 'javascript:alert(1)']]]), $cookie);
            expectCustomLinkHttp($invalid['status'] === 200 && str_contains($invalid['body'], 'Keep this name') && str_contains($invalid['body'], 'Enter a valid HTTP'), 'Validation retains custom names and rejects unsafe URLs.');
            expectCustomLinkHttp(fetchSpeaker($conn, $speaker)['version'] === 1 && !fetchPresentationShortLinks($conn, $pids[0]), 'Rejected saves create no profile changes or tracking links.');
            expectCustomLinkHttp($request($editUrl, $post, $cookie)['status'] === 302, 'An editor can save multiple custom links.');
            $links = fetchPresentationShortLinks($conn, $pids[0]);
            $second = fetchPresentationShortLinks($conn, $pids[1]);
            expectCustomLinkHttp(count($links) === 2 && count($second) === 2 && $links[0]['code'] !== $second[0]['code'], 'HTTP saves create independent codes on every existing presentation.');
            $view = $request('view_speaker.php?id=' . $speaker, null, $cookie);
            expectCustomLinkHttp(str_contains($view['body'], 'Video &lt;Channel&gt;') && str_contains($view['body'], 'https://example.com/videos?a=1&amp;b=2'), 'Profile links and labels are escaped.');
            $redirect = $request('surls/' . $links[0]['code']);
            expectCustomLinkHttp($redirect['status'] === 302 && str_contains($redirect['headers'], 'Location: https://example.com/videos?a=1&b=2'), 'The custom short link redirects to its saved destination.');
            $edit = $request($editUrl, null, $cookie);
            expectCustomLinkHttp(str_contains($edit['body'], 'value="' . $links[0]['custom_link_key'] . '"'), 'Edit form preserves stable custom identities.');
            expectCustomLinkHttp($request($editUrl, $post, $cookie)['status'] === 200 && count(fetchPresentationShortLinks($conn, $pids[0])) === 2, 'Stale saves cannot create duplicate links.');
        } else {
            expectCustomLinkHttp($request($editUrl, $post, $cookie)['status'] === 403, 'Reviewers cannot change custom profile links.');
        }
        $report = $request('short_links.php?id=' . $links[0]['id'], null, $cookie);
        expectCustomLinkHttp($report['status'] === 200 && str_contains($report['body'], 'Video &lt;Channel&gt; QR Code Statistics'), 'Custom names title the individual statistics report for ' . $role);
        preg_match('/<script[^>]*id="short-link-stats-data"[^>]*>(.*?)<\/script>/s', $report['body'], $match);
        expectCustomLinkHttp(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['total'] === 1, 'The report counts only this custom link.');
        $filtered = $request('short_links.php?presentation_id=' . $pids[0] . '&type=custom', null, $cookie);
        expectCustomLinkHttp($filtered['status'] === 200 && str_contains($filtered['body'], 'Library'), 'Presentation statistics support filtering custom links.');
        $presentation = $request('view_engagement.php?id=' . $event, null, $cookie);
        expectCustomLinkHttp(str_contains($presentation['body'], 'Video &lt;Channel&gt; QR Code Statistics') && str_contains($presentation['body'], 'Library QR Code Statistics'), 'Presentations expose each custom QR statistics card.');
        $qr = $request('short_link_qr.php?id=' . $links[0]['id'] . '&download=1', null, $cookie);
        expectCustomLinkHttp($qr['status'] === 200 && str_starts_with($qr['body'], "\x89PNG"), 'Custom QR downloads work for ' . $role);
        $pdfUrl = 'presentation_qr_pdf_view.php?presentation_id=' . $pids[0];
        expectCustomLinkHttp($request($pdfUrl)['status'] === 302, 'QR PDF requires authentication.');
        $pdf = $request($pdfUrl, null, $cookie);
        expectCustomLinkHttp($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-')
            && str_contains($pdf['headers'], 'Content-Disposition: inline;')
            && str_contains($pdf['headers'], 'Content-Type: application/pdf'), 'QR PDF opens inline for ' . $role);
        expectCustomLinkHttp(str_contains($pdf['body'], $links[0]['code']) && str_contains($pdf['body'], $links[1]['code'])
            && !str_contains($pdf['body'], $second[0]['code']), 'Presentation PDF contains only its own tracked codes.');
        $allPdf = $request('presentation_qr_pdf_view.php?engagement_id=' . $event, null, $cookie);
        expectCustomLinkHttp($allPdf['status'] === 200 && preg_match_all('/\/URI\s*\([^)]*\/surls\//', $allPdf['body']) === 4
            && str_contains($allPdf['body'], $second[0]['code']), 'Engagement PDF includes every presentation QR code.');
        expectCustomLinkHttp($request($pdfUrl, null, $cookie, true)['body'] === '', 'PDF HEAD requests do not send a body.');
        expectCustomLinkHttp($request($pdfUrl, [], $cookie)['status'] === 405, 'The read-only PDF route rejects POST.');
        foreach (['', '?presentation_id=bad', '?presentation_id[]=1', '?presentation_id=0', '?engagement_id=' . $event . '&presentation_id=' . $pids[0]] as $invalidScope) {
            expectCustomLinkHttp($request('presentation_qr_pdf_view.php' . $invalidScope, null, $cookie)['status'] === 400, 'Invalid PDF scope is rejected.');
        }
        expectCustomLinkHttp($request('presentation_qr_pdf_view.php?presentation_id=2147483647', null, $cookie)['status'] === 404, 'Missing presentation returns 404.');
        $afterPdf = (int) $conn->query('SELECT COALESCE(SUM(visits),0) AS total FROM short_link_stats WHERE link_id=' . $links[0]['id'])->fetch_assoc()['total'];
        expectCustomLinkHttp($afterPdf === 1, 'Viewing PDFs does not increment visit statistics.');
        expectCustomLinkHttp(str_contains($presentation['body'], $pdfUrl)
            && str_contains($presentation['body'], 'presentation_qr_pdf_view.php?engagement_id=' . $event), 'Both presentation and engagement PDF actions are exposed.');
        if ($role === 'editor') {
            $remove = $input + ['csrf_token' => $csrf, 'version' => 2, 'custom_links_present' => 1];
            expectCustomLinkHttp($request($editUrl, $remove, $cookie)['status'] === 302 && fetchSpeaker($conn, $speaker)['custom_links'] === [], 'Removing all rows persists an empty profile collection.');
            expectCustomLinkHttp(count(fetchPresentationShortLinks($conn, $pids[0])) === 2, 'Profile removal preserves published tracking records.');
        }
    }
    echo "Speaker custom link HTTP integration tests passed.\n";
} finally {
    foreach ($users as $uid) $conn->query('DELETE FROM users WHERE id=' . $uid);
    foreach ($sessions as $session) @unlink(session_save_path() . '/sess_' . $session);
    $conn->query('DELETE FROM engagements WHERE id=' . $event);
    $conn->query('DELETE FROM organizations WHERE id=' . $org);
}
