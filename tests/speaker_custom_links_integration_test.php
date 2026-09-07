<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Speaker custom links integration skipped (disposable database required).\n"; exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/presentation_helpers.php';
require_once $source . '/presentation_qr_pdf.php';
function expectCustomLinksIntegration(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$conn->begin_transaction();
try {
    $input = ['name' => 'Custom Link Fixture', 'email' => 'custom-links@example.com', 'phone' => '+19494002892',
        'website_url' => 'https://example.com/website'];
    $speaker = saveSpeaker($conn, $input);
    $other = saveSpeaker($conn, array_replace($input, ['name' => 'Other Speaker']));
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Custom Links Test')");
    $org = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
        VALUES ($org,'Custom Links Test','2026-10-01','2026-10-02','conference','under_review')");
    $event = (int) $conn->insert_id;
    syncEngagementPresentations($conn, $event, normalizeEngagementPresentations([
        ['topic_title' => 'First', 'speaker_id' => $speaker], ['topic_title' => 'Second', 'speaker_id' => $speaker],
    ], '2026-10-01', '2026-10-02', $speaker));
    $pids = array_map('intval', array_column($conn->query("SELECT id FROM presentations WHERE engagement_id=$event ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'id'));
    $input['custom_links'] = [
        ['label' => 'Video <Channel>', 'url' => 'https://example.com/videos?a=1&b=2'],
        ['label' => 'Resource Library', 'url' => 'https://example.com/resources'],
    ];
    saveSpeaker($conn, $input, $speaker, 1);
    ensureSpeakerCustomShortLinks($conn, $speaker);
    $savedSpeaker = fetchSpeaker($conn, $speaker);
    $first = fetchPresentationShortLinks($conn, $pids[0]);
    $second = fetchPresentationShortLinks($conn, $pids[1]);
    expectCustomLinksIntegration(count($first) === 3 && count($second) === 3, 'Saving new profile links populates existing presentations.');
    $codes = array_column(array_merge($first, $second), 'code');
    expectCustomLinksIntegration(count(array_unique($codes)) === 6, 'Every presentation and custom link has a unique code.');
    expectCustomLinksIntegration($first[1]['custom_link_key'] === $second[1]['custom_link_key'], 'Profile identity is shared; published codes are independent.');
    expectCustomLinksIntegration(shortLinkLabel($first[1]) === 'Video <Channel>', 'Custom labels appear in tracking records.');
    foreach ($first as $link) expectCustomLinksIntegration(str_starts_with($link['qr_png'], "\x89PNG"), 'Custom QR images are persisted before commit.');
    expectCustomLinksIntegration(!ensurePresentationShortLinks($conn, $pids[0]), 'Repeated generation does not duplicate custom links.');
    $input['custom_links'] = array_reverse($savedSpeaker['custom_links']);
    $input['custom_links'][1]['label'] = 'Renamed Channel';
    $input['custom_links'][1]['url'] = 'https://example.com/new-videos';
    saveSpeaker($conn, $input, $speaker, 2);
    ensureSpeakerCustomShortLinks($conn, $speaker);
    expectCustomLinksIntegration(fetchPresentationShortLinks($conn, $pids[0]) === $first, 'Reordering, renaming and changing profile URLs preserve published links and images.');
    // A stale profile must not replace the current link collection.
    try { saveSpeaker($conn, array_replace($input, ['custom_links' => []]), $speaker, 2); throw new RuntimeException('Stale save accepted.'); }
    catch (InvalidArgumentException $expected) {}
    expectCustomLinksIntegration(count(fetchSpeaker($conn, $speaker)['custom_links']) === 2, 'Stale writes preserve profile links.');
    $foreign = $savedSpeaker['custom_links'][0];
    try { saveSpeaker($conn, array_replace($input, ['custom_links' => [$foreign]]), $other, 1); throw new RuntimeException('Foreign link key accepted.'); }
    catch (InvalidArgumentException $expected) {}
    // Existing helper callers that do not submit custom links preserve them.
    saveSpeaker($conn, array_diff_key($input, ['custom_links' => true]), $speaker, 3);
    expectCustomLinksIntegration(count(fetchSpeaker($conn, $speaker)['custom_links']) === 2, 'Omitted custom links are not erased.');
    $server = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'];
    recordShortLinkVisit($conn, (int) $first[1]['id'], $server);
    recordShortLinkVisit($conn, (int) $first[1]['id'], $server);
    recordShortLinkVisit($conn, (int) $second[1]['id'], $server);
    $start = gmdate('Y-m-d') . ' 00:00:00'; $end = gmdate('Y-m-d', strtotime('+1 day')) . ' 00:00:00';
    expectCustomLinksIntegration(shortLinkStats($conn, 'l.id=' . $first[1]['id'], $start, $end)['total'] === 2, 'Custom link visits are counted independently.');
    expectCustomLinksIntegration(shortLinkStats($conn, 'l.presentation_id=' . $pids[1], $start, $end)['total'] === 1, 'Visits remain scoped to the presentation.');
    $input['custom_links'] = [];
    saveSpeaker($conn, $input, $speaker, 4);
    ensureSpeakerCustomShortLinks($conn, $speaker);
    expectCustomLinksIntegration(fetchSpeaker($conn, $speaker)['custom_links'] === [] && fetchPresentationShortLinks($conn, $pids[0]) === $first, 'Removing all profile links retains published codes and analytics.');
    updateShortLink($conn, (int) $first[1]['id'], 1, 'https://example.com/explicit', false);
    $updated = fetchPresentationShortLinks($conn, $pids[0])[1];
    expectCustomLinksIntegration($updated['target_url'] === 'https://example.com/explicit' && !$updated['is_enabled'] && $updated['code'] === $first[1]['code'], 'Statistics can change or disable a custom destination without changing its code.');
    $input['custom_links'] = [['label' => 'Video <Channel>', 'url' => 'https://example.com/videos']];
    saveSpeaker($conn, $input, $speaker, 5);
    ensureSpeakerCustomShortLinks($conn, $speaker);
    expectCustomLinksIntegration(count(fetchPresentationShortLinks($conn, $pids[0])) === 4, 'A replacement profile row receives a new identity rather than reusing retired statistics.');
    $conn->query("UPDATE presentations SET speaker_id=$other WHERE id=" . $pids[0]);
    ensurePresentationShortLinks($conn, $pids[0]);
    expectCustomLinksIntegration((int) fetchPresentationShortLinks($conn, $pids[0])[1]['speaker_id'] === $speaker, 'Speaker reassignment retains original custom link attribution.');
    $exported = fetchPresentationQrPdfLinks($conn, $event);
    expectCustomLinksIntegration(count($exported) === 5 && !in_array($first[1]['code'], array_column($exported, 'code'), true), 'The PDF excludes codes belonging to a replaced speaker.');
    $conn->query('UPDATE presentations SET is_archived=1 WHERE id=' . $pids[1]);
    expectCustomLinksIntegration(count(fetchPresentationQrPdfLinks($conn, $event)) === 1, 'The PDF excludes archived presentations.');
    echo "Speaker custom links integration tests passed.\n";
} finally { $conn->rollback(); }
