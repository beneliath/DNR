<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') { echo "Short links integration skipped (disposable database required).\n"; exit; }
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/presentation_helpers.php';
function expectLinks(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$conn->begin_transaction();
try {
    $speaker = saveSpeaker($conn, ['name'=>'QR Test','email'=>'qr-test@example.com','phone'=>'+19494002892','website_url'=>'https://example.com/original']);
    $other = saveSpeaker($conn, ['name'=>'Other QR Test','email'=>'qr-other@example.com','phone'=>'+19494002892','website_url'=>'https://example.com/original']);
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('QR transaction test')"); $org = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status) VALUES ($org,'QR event','2026-10-01','2026-10-02','conference','under_review')"); $event = (int) $conn->insert_id;
    $rows = normalizeEngagementPresentations([['topic_title'=>'First','speaker_id'=>$speaker],['topic_title'=>'Second','speaker_id'=>$speaker]],'2026-10-01','2026-10-02',$speaker,true);
    syncEngagementPresentations($conn,$event,$rows);
    $presentations = $conn->query("SELECT * FROM presentations WHERE engagement_id = $event ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $pid = (int) $presentations[0]['id']; $pid2 = (int) $presentations[1]['id'];
    $links = fetchPresentationShortLinks($conn,$pid); $second = fetchPresentationShortLinks($conn,$pid2);
    expectLinks(count($links) === 1 && $links[0]['code'] !== $second[0]['code'], 'Each presentation has a separate website link and no notes placeholder');
    $storedImages = $conn->query("SELECT q.* FROM short_link_qr_images q JOIN short_links l ON l.id=q.link_id WHERE l.engagement_id=$event ORDER BY q.link_id")->fetch_all(MYSQLI_ASSOC);
    expectLinks(count($storedImages) === 2, 'Both QR formats are stored for every link before presentation save commits');
    foreach ($storedImages as $images) {
        expectLinks(str_starts_with($images['png'], "\x89PNG") && str_contains($images['svg'], '<svg'), 'Stored PNG and SVG are valid images');
        expectLinks(hash('sha256',$images['png'],true) === $images['png_sha256'] && hash('sha256',$images['svg'],true) === $images['svg_sha256'], 'Stored image validators match their bytes');
    }
    expectLinks(!ensurePresentationShortLinks($conn,$pid), 'Repeated generation preserves links');
    $conn->query("UPDATE speakers SET website_url = 'https://example.com/new' WHERE id = $speaker");
    ensurePresentationShortLinks($conn,$pid);
    expectLinks(fetchPresentationShortLinks($conn,$pid)[0]['target_url'] === 'https://example.com/original','Profile edits preserve snapshots');
    $pdf = "%PDF-1.4\nQR notes\nstartxref\n0\n%%EOF\n";
    $asset = ['data'=>$pdf,'filename'=>'notes.pdf','size'=>strlen($pdf),'sha256'=>hash('sha256',$pdf,true)];
    applyPresentationAssetChanges($conn,$event,$pid,['speaker_notes'=>['action'=>'replace','asset'=>$asset]]);
    ensurePresentationShortLinks($conn,$pid);
    $links = fetchPresentationShortLinks($conn,$pid);
    expectLinks(count($links) === 2 && $links[1]['link_type'] === 'notes' && !empty($links[1]['qr_png']), 'First PDF upload creates the notes link and rendered images');
    $notesImages = $conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$links[1]['id'])->fetch_assoc();
    applyPresentationAssetChanges($conn,$event,$pid,['speaker_notes'=>['action'=>'remove']]);
    ensurePresentationShortLinks($conn,$pid);
    expectLinks(!fetchPresentationShortLinks($conn,$pid)[1]['has_notes'], 'Removed notes are marked unavailable for display');
    applyPresentationAssetChanges($conn,$event,$pid,['speaker_notes'=>['action'=>'replace','asset'=>$asset]]);
    ensurePresentationShortLinks($conn,$pid);
    expectLinks($conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$links[1]['id'])->fetch_assoc()===$notesImages, 'Re-uploaded notes reuse the same link and rendered images');
    $row = $conn->query("SELECT slide_deck_pdf FROM presentations WHERE id = $pid")->fetch_assoc();
    expectLinks($row['slide_deck_pdf'] === null,'Notes do not overwrite slides');
    $rows = normalizeEngagementPresentations([array_replace($presentations[0],['speaker_id'=>$other]),$presentations[1]],'2026-10-01','2026-10-02',$speaker,true);
    syncEngagementPresentations($conn,$event,$rows);
    expectLinks(count(fetchPresentationShortLinks($conn,$pid)) === 3,'Speaker replacement creates a separate website link without a notes placeholder');
    expectLinks($conn->query("SELECT pdf FROM presentation_notes WHERE presentation_id=$pid AND speaker_id=$speaker")->fetch_assoc()['pdf'] === $pdf,'Original notes remain attached to original speaker');
    $linkId = (int) $links[0]['id'];
    updateShortLink($conn,$linkId,1,'https://example.com/explicit',false);
    expectLinks(fetchPresentationShortLinks($conn,$pid)[0]['code'] === $links[0]['code'],'Explicit update preserves code');
    foreach ($storedImages as $images) {
        $saved = $conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$images['link_id'])->fetch_assoc();
        expectLinks($saved === $images, 'Repeated saves, speaker changes and destination edits preserve the original rendered bytes and timestamps');
    }
    try { updateShortLink($conn,$linkId,1,'https://example.com/stale',true); throw new RuntimeException('Stale update accepted'); } catch (InvalidArgumentException $expected) {}
    $server = ['REQUEST_METHOD'=>'GET','REMOTE_ADDR'=>'203.0.113.10','HTTP_USER_AGENT'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'];
    recordShortLinkVisit($conn,$linkId,$server); recordShortLinkVisit($conn,$linkId,$server);
    recordShortLinkVisit($conn,$linkId,array_replace($server,['REQUEST_METHOD'=>'HEAD']));
    expectLinks((int)$conn->query("SELECT SUM(visits) AS n FROM short_link_stats WHERE link_id=$linkId")->fetch_assoc()['n'] === 2,'Atomic counters and HEAD filtering');
    recordShortLinkVisit($conn, (int) $second[0]['id'], $server);
    $stats = shortLinkStats($conn,'l.presentation_id = '.$pid,gmdate('Y-m-d').' 00:00:00',gmdate('Y-m-d',strtotime('+1 day')).' 00:00:00');
    expectLinks($stats['total'] === 2 && $stats['browser'][0]['label'] === 'Chrome','Presentation analytics aggregate correctly');
    $countryInsert = $conn->prepare("INSERT INTO short_link_stats (link_id,visit_hour,browser,os,country,referrer,visits) VALUES (?,UTC_DATE(),'Chrome','Windows',?,'',1)");
    foreach (['US','CA','GB','FR','DE','AU','NZ','IE','ES','IT','NL','BE','CH','AT','SE','NO','DK','FI','PL','PT','BR'] as $country) {
        $countryInsert->bind_param('is',$linkId,$country); $countryInsert->execute();
    }
    $stats = shortLinkStats($conn,'l.presentation_id = '.$pid,gmdate('Y-m-d').' 00:00:00',gmdate('Y-m-d',strtotime('+1 day')).' 00:00:00');
    expectLinks(count($stats['country']) === 22 && array_sum(array_column($stats['country'],'total')) === 23,'Country map includes locations beyond the former top-20 cutoff');
    recordShortLinkVisit($conn,(int)$links[1]['id'],$server);
    $qrStats = shortLinkStats($conn,'l.id = '.$linkId,gmdate('Y-m-d').' 00:00:00',gmdate('Y-m-d',strtotime('+1 day')).' 00:00:00');
    expectLinks($qrStats['total'] === 23,'QR statistics exclude notes visits and the same speaker website at another presentation');
    echo "Short links integration tests passed.\n";
} finally { $conn->rollback(); }
