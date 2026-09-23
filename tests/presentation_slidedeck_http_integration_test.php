<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') { echo "Short link HTTP tests skipped (disposable server required).\n"; exit; }
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once __DIR__ . '/integration_auth_helpers.php';
require_once $source . '/presentation_helpers.php';
require_once $source . '/presentation_slidedeck_helpers.php';
require_once $source . '/presentation_qr_pdf.php';
require_once __DIR__ . '/presentation_slidedeck_fixture.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080','/');
if (!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','ingress'],true)) throw new RuntimeException('Loopback server required.');
function expectLinkHttp(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$request=static function(string $path,?array $post=null,string $cookie='',array $extra=[],bool $head=false)use($base):array{
 $c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>90,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36']);
 if($cookie!=='')curl_setopt($c,CURLOPT_COOKIE,$cookie);if($post!==null)curl_setopt($c,CURLOPT_POSTFIELDS,count(array_filter($post,fn($v)=>$v instanceof CURLFile)) ? $post : http_build_query($post));if($extra)curl_setopt($c,CURLOPT_HTTPHEADER,$extra);if($head)curl_setopt($c,CURLOPT_NOBODY,true);
 $r=curl_exec($c);expectLinkHttp(is_string($r),'HTTP request failed: '.curl_error($c));$size=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);
 return ['status'=>(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE),'headers'=>substr($r,0,$size),'body'=>substr($r,$size)];
};
$speaker=saveSpeaker($conn,['name'=>'HTTP QR Fixture '.bin2hex(random_bytes(3)),'email'=>'qr-http@example.com','phone'=>'+19494002892','website_url'=>'https://example.com/path?a=1&b=2']);
$conn->query("INSERT INTO organizations(organization_name) VALUES ('HTTP QR Fixture ".bin2hex(random_bytes(4))."')");$org=(int)$conn->insert_id;
$conn->query("INSERT INTO engagements(organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status) VALUES ($org,'HTTP QR Fixture','2026-10-01','2026-10-01','conference','under_review')");$event=(int)$conn->insert_id;
$conn->begin_transaction();syncEngagementPresentations($conn,$event,normalizeEngagementPresentations([['speaker_id'=>$speaker,'topic_title'=>'HTTP QR Fixture']],'2026-10-01','2026-10-01',$speaker));$conn->commit();
$pid=(int)$conn->query("SELECT id FROM presentations WHERE engagement_id=$event")->fetch_assoc()['id'];
$links=fetchPresentationShortLinks($conn,$pid);$web=$links[0];$userIds=[];$sessionIds=[];
$path = tempnam(sys_get_temp_dir(), 'slidedeck-http-');
writeTestSlidedeck($path);
try {
    expectLinkHttp(count($links) === 1, 'No slidedeck placeholder before upload.');
    $name = 'ppt-editor-' . bin2hex(random_bytes(5));
    $conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')", [$name, password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]);
    $uid = (int) $conn->insert_id; $userIds[] = $uid;
    startSecureSession(); $_SESSION = ['user_id'=>$uid,'username'=>$name,'role'=>'editor','authenticated_role'=>'editor','auth_version'=>1,'auth_complete'=>true,'_csrf_token'=>bin2hex(random_bytes(32))];
    completeIntegrationTestMfaSession(); $sessionIds[] = session_id(); $cookie = session_name().'='.session_id(); session_write_close();
    $editForm = static function () use ($request, $event, $cookie): array {
        $response = $request('edit_engagement.php?id='.$event, null, $cookie);
        expectLinkHttp($response['status'] === 200, 'Edit form available.');
        $doc = new DOMDocument(); @$doc->loadHTML($response['body']); $xpath = new DOMXPath($doc); $form = [];
        foreach ($xpath->query('//form[@id="engagement-edit-form"]//input | //form[@id="engagement-edit-form"]//select | //form[@id="engagement-edit-form"]//textarea') as $control) {
            $key = $control->getAttribute('name'); $type = $control->getAttribute('type');
            if ($control->hasAttribute('disabled') || $xpath->query('ancestor::template', $control)->length > 0) continue;
            if ($key === '' || in_array($type, ['file','submit','button'], true) || in_array($type, ['checkbox','radio'], true) && !$control->hasAttribute('checked')) continue;
            if ($control->tagName === 'textarea') $value = $control->textContent;
            elseif ($control->tagName === 'select') {
                $value = '';
                foreach ($control->getElementsByTagName('option') as $index => $option) {
                    if ($index === 0 || $option->hasAttribute('selected')) $value = $option->getAttribute('value');
                    if ($option->hasAttribute('selected')) break;
                }
            } else $value = $control->getAttribute('value');
            $form[$key] = $value;
        }
        $input = $xpath->query('//input[@type="file" and contains(@name,"[ppt_slidedeck]")]')->item(0);
        expectLinkHttp($input instanceof DOMElement, 'PowerPoint upload field available.');
        $form['save_engagement'] = '1';
        return [$form, $input->getAttribute('name')];
    };
    $upload = static function () use ($request, $event, $cookie, $path, $editForm): array {
        [$form, $field] = $editForm();
        $form[$field] = new CURLFile($path, 'application/octet-stream', 'audience.pptx');
        return $request('edit_engagement.php?id='.$event, $form, $cookie);
    };
    // Exercise a real 331 MB upload through ingress, with no request larger than 10 MB plus framing.
    [$chunkForm, $chunkField] = $editForm();
    preg_match('/presentations\[(\d+)\]/', $chunkField, $chunkRow);
    $chunkBase = ['engagement_id' => $event, 'row' => $chunkRow[1], 'csrf_token' => $chunkForm['csrf_token']];
    $startPost = $chunkBase + ['action' => 'start', 'name' => 'large.pptx', 'size' => 331 * 1024 * 1024];
    expectLinkHttp($request('presentation_chunk.php', $startPost)['status'] === 302, 'Chunk endpoint requires authentication.');
    expectLinkHttp($request('presentation_chunk.php', array_replace($startPost, ['csrf_token' => 'bad']), $cookie)['status'] === 400, 'Chunk endpoint requires CSRF.');
    $started = $request('presentation_chunk.php', $startPost, $cookie);
    expectLinkHttp($started['status'] === 200, 'Chunk upload starts.');
    $chunkToken = json_decode($started['body'], true, 512, JSON_THROW_ON_ERROR)['token'];
    $largePath = tempnam(sys_get_temp_dir(), 'chunk-http-large-');
    $chunkPath = tempnam(sys_get_temp_dir(), 'chunk-http-piece-');
    try {
        writeSizedTestSlidedeck($largePath, 331 * 1024 * 1024);
        $largeInput = fopen($largePath, 'rb');
        try {
            for ($offset = 0; $offset < 331 * 1024 * 1024; $offset += 10 * 1024 * 1024) {
                $piece = fopen($chunkPath, 'wb');
                $length = stream_copy_to_stream($largeInput, $piece, 10 * 1024 * 1024);
                fclose($piece);
                $chunkPost = $chunkBase + ['action' => 'chunk', 'token' => $chunkToken, 'offset' => $offset,
                    'chunk' => new CURLFile($chunkPath, 'application/octet-stream', 'chunk')];
                $sent = $request('presentation_chunk.php', $chunkPost, $cookie);
                expectLinkHttp($sent['status'] === 200, 'Chunk accepted: ' . $sent['body']);
                expectLinkHttp(json_decode($sent['body'], true)['offset'] === $offset + $length, 'Chunk acknowledged.');
                if ($offset === 0) {
                    expectLinkHttp($request('presentation_chunk.php', $chunkPost, $cookie)['status'] === 200, 'Lost-response retry is idempotent.');
                    expectLinkHttp($request('presentation_chunk.php', array_replace($chunkPost, ['row' => '99999']), $cookie)['status'] === 400, 'Token cannot be moved to another presentation.');
                }
            }
        } finally { fclose($largeInput); }
        $pdfPath = tempnam(sys_get_temp_dir(), 'chunk-http-pdf-');
        try {
            $pdfHandle = fopen($pdfPath, 'wb');
            fwrite($pdfHandle, "%PDF-1.4\n"); ftruncate($pdfHandle, 25 * 1024 * 1024);
            $pdfTail = "\nstartxref\n0\n%%EOF\n";
            fseek($pdfHandle, 25 * 1024 * 1024 - strlen($pdfTail)); fwrite($pdfHandle, $pdfTail); fclose($pdfHandle);
            $pdfStart = $request('presentation_chunk.php', $chunkBase + ['action' => 'start', 'asset_key' => 'speaker_notes', 'name' => 'notes.pdf', 'size' => 25 * 1024 * 1024], $cookie);
            expectLinkHttp($pdfStart['status'] === 200, 'PDF upload starts.');
            $pdfToken = json_decode($pdfStart['body'], true)['token'];
            $pdfInput = fopen($pdfPath, 'rb');
            try {
                for ($offset = 0; $offset < 25 * 1024 * 1024; $offset += 10 * 1024 * 1024) {
                    $piece = fopen($chunkPath, 'wb'); stream_copy_to_stream($pdfInput, $piece, 10 * 1024 * 1024); fclose($piece);
                    $pdfSent = $request('presentation_chunk.php', $chunkBase + ['action' => 'chunk', 'token' => $pdfToken, 'offset' => $offset,
                        'chunk' => new CURLFile($chunkPath, 'application/octet-stream', 'chunk')], $cookie);
                    expectLinkHttp($pdfSent['status'] === 200, 'PDF chunk accepted.');
                }
            } finally { fclose($pdfInput); }
            $pdfChecksum = hash_file('sha256', $pdfPath);
        } finally { @unlink($pdfPath); }
        $chunkForm['presentations[' . $chunkRow[1] . '][pdf_upload_token]'] = $pdfToken;
        $chunkForm['presentations[' . $chunkRow[1] . '][ppt_upload_token]'] = $chunkToken;
        $chunkSaved = $request('edit_engagement.php?id=' . $event, $chunkForm, $cookie);
        expectLinkHttp($chunkSaved['status'] === 302, '331 MB staged upload saves through the engagement form.');
        $largeStored = $conn->execute_query('SELECT * FROM presentation_slidedecks WHERE presentation_id = ?', [$pid])->fetch_assoc();
        expectLinkHttp((int) $largeStored['size'] === 331 * 1024 * 1024, 'Large deck size persisted.');
        expectLinkHttp(hash_file('sha256', persistentFilePath($largeStored['storage_key'])) === hash_file('sha256', $largePath), 'HTTP chunk upload preserves every byte.');
        expectLinkHttp((int) $largeStored['uploaded_by'] === $uid, 'Chunked upload records the authenticated uploader.');
        $notesStored = $conn->execute_query('SELECT storage_key FROM presentation_notes WHERE presentation_id = ?', [$pid])->fetch_assoc();
        expectLinkHttp(hash_file('sha256', persistentFilePath($notesStored['storage_key'])) === $pdfChecksum, 'PDF and PowerPoint save together with their original checksums.');

        $rangeResponse = $request('presentation_asset.php?id=' . $pid . '&type=slidedeck', null, $cookie, ['Range: bytes=-1024']);
        expectLinkHttp($rangeResponse['status'] === 206 && strlen($rangeResponse['body']) === 1024, 'Large deck supports ranged downloads.');
        expectLinkHttp($request('presentation_chunk.php', $chunkBase + ['action' => 'cancel', 'token' => $chunkToken], $cookie)['status'] === 400, 'Saved staging token has been consumed.');
    } finally { @unlink($largePath); @unlink($chunkPath); }
    if (getenv('DNR_CHUNK_UPLOAD_ONLY') === '1') {
        echo "Chunked PowerPoint/PDF HTTP integration passed.\n";
        return;
    }
    $saved = $upload();
    expectLinkHttp($saved['status'] === 302, 'Valid PPTX uploads through the real form.');
    $links = fetchPresentationShortLinks($conn, $pid);
    $deck = array_values(array_filter($links, fn($link) => $link['link_type'] === 'slidedeck'))[0];
    $originalQr = $deck['qr_png']; $originalCode = $deck['code'];
    $stored = $conn->execute_query('SELECT * FROM presentation_slidedecks WHERE presentation_id = ?', [$pid])->fetch_assoc();
    expectLinkHttp((int) $stored['uploaded_by'] === $uid && $stored['uploaded_by_username_snapshot'] === $name, 'Server records the uploader.');
    expectLinkHttp(file_get_contents(persistentFilePath($stored['storage_key'])) === file_get_contents($path), 'PowerPoint bytes are on the persistent filesystem.');
    expectLinkHttp($conn->query("SHOW COLUMNS FROM presentation_slidedecks WHERE Type LIKE '%blob%'")->num_rows === 0, 'The database stores no PowerPoint BLOB.');
    $metadata = persistentFileMetadata($conn, $stored['storage_key']);
    expectLinkHttp($metadata['checksum'] === hash_file('sha256', $path), 'The backup registry includes the uploaded file.');
    $short = 'surls/'.$deck['code']; $download = $short.'/ppt-slidedeck';
    $count = fn() => (int) $conn->query('SELECT COALESCE(SUM(visits),0) FROM short_link_stats WHERE link_id='.(int)$deck['id'])->fetch_row()[0];
    $response = $request($short);
    expectLinkHttp($response['status'] === 302 && str_contains($response['headers'], 'Location: /'.$download) && $count() === 1, 'QR navigation counts one visit and resolves the download.');
    $response = $request($download);
    expectLinkHttp($response['status'] === 200 && $response['body'] === file_get_contents($path), 'Public download streams exact bytes without login.');
    expectLinkHttp(str_contains($response['headers'], 'attachment; filename="audience.pptx"') && str_contains($response['headers'], PRESENTATION_SLIDEDECK_MIMES['pptx']) && !str_contains(strtolower($response['headers']), 'set-cookie:'), 'Downloads use the PowerPoint MIME, filename, and no visitor session.');
    preg_match('/^ETag:\s*(.+)$/mi', $response['headers'], $etagMatch); $etag = trim($etagMatch[1]);
    expectLinkHttp($request($download, null, '', ['Range: bytes=3-10'])['body'] === substr(file_get_contents($path), 3, 8), 'Range requests return the requested bytes.');
    expectLinkHttp($request($download, null, '', ['Range: bytes=999999-'])['status'] === 416, 'Unsatisfiable ranges are rejected.');
    expectLinkHttp($request($download, null, '', ['If-None-Match: '.$etag])['status'] === 304, 'Conditional downloads return 304.');
    expectLinkHttp($request($download, null, '', [], true)['body'] === '' && $count() === 1, 'HEAD and direct downloads do not count visits.');
    $assetUrl = 'presentation_asset.php?id='.$pid.'&type=slidedeck';
    expectLinkHttp($request($assetUrl)['status'] === 302 && $request($assetUrl, null, $cookie)['body'] === file_get_contents($path), 'View button requires login and serves the same file.');
    foreach (['view_engagement.php', 'edit_engagement.php'] as $page) {
        $response = $request($page.'?id='.$event, null, $cookie);
        $doc = new DOMDocument(); @$doc->loadHTML($response['body']); $xpath = new DOMXPath($doc);
        foreach ($links as $link) {
            expectLinkHttp($xpath->query('//button[@data-copy-qr-link="'.$link['qr_url'].'"]')->length === 1, 'Every visible QR code copies its stored URL on '.$page);
        }
        expectLinkHttp(str_contains($response['body'], $page === 'view_engagement.php' ? 'Download PPT Slidedeck' : 'Replace PPT'), 'PowerPoint action present on '.$page);
    }
    expectLinkHttp(in_array($deck['id'], array_column(fetchPresentationQrPdfLinks($conn, $event, $pid), 'id')), 'PPT QR code is included in the QR PDF selection.');
    $stats = $request('short_links.php?id='.$deck['id'], null, $cookie);
    expectLinkHttp($stats['status'] === 200 && !str_contains($stats['body'], 'name="target_url"'), 'File links cannot be changed to arbitrary destinations.');
    updateShortLink($conn, (int) $deck['id'], 1, '', false);
    expectLinkHttp($request($short)['status'] === 410 && $request($download)['status'] === 410, 'Disabling the link disables navigation and downloads.');
    updateShortLink($conn, (int) $deck['id'], 2, '', true);
    writeTestSlidedeck($path, 'Replacement'); clearstatcache();
    expectLinkHttp($upload()['status'] === 302, 'Replacement upload succeeds.');
    $replacement = array_values(array_filter(fetchPresentationShortLinks($conn, $pid), fn($link) => $link['link_type'] === 'slidedeck'))[0];
    expectLinkHttp($replacement['code'] === $originalCode && $replacement['qr_png'] === $originalQr && $count() === 1, 'Replacement preserves code, QR bytes, and statistics.');
    expectLinkHttp($request($download, null, '', ['Range: bytes=0-3', 'If-Range: '.$etag])['body'] === file_get_contents($path), 'Stale If-Range returns the complete new file.');
    [$form, $field] = $editForm();
    $removeField = str_replace('[ppt_slidedeck]', '[remove_ppt_slidedeck]', $field);
    $form[$field] = new CURLFile($path, 'application/octet-stream', 'audience.pptx'); $form[$removeField] = '1';
    expectLinkHttp($request('edit_engagement.php?id='.$event, $form, $cookie)['status'] === 200 && $request($download)['body'] === file_get_contents($path), 'Conflicting replacement/removal leaves the saved file intact.');
    file_put_contents($path, '<html>renamed fake PowerPoint</html>'); clearstatcache();
    expectLinkHttp($upload()['status'] === 200 && $request($download)['status'] === 200, 'Invalid uploads preserve the current file.');
    [$form, $field] = $editForm(); $form[$removeField] = '1';
    expectLinkHttp($request('edit_engagement.php?id='.$event, $form, $cookie)['status'] === 302, 'Removal through the form succeeds.');
    expectLinkHttp($request($short)['status'] === 404 && $request($download)['status'] === 404, 'Removed decks cannot be downloaded.');
    expectLinkHttp(!str_contains($request('view_engagement.php?id='.$event, null, $cookie)['body'], 'Download PPT Slidedeck'), 'Removed decks hide the view button.');
    expectLinkHttp(!in_array($deck['id'], array_column(fetchPresentationQrPdfLinks($conn, $event, $pid), 'id')), 'Removed decks are excluded from QR PDF exports.');
    writeTestSlidedeck($path, 'Re-upload'); clearstatcache();
    expectLinkHttp($upload()['status'] === 302 && $request($download)['body'] === file_get_contents($path), 'Re-upload reuses the original URL.');
    $other = saveSpeaker($conn, ['name'=>'Other PPT Speaker','email'=>'other-ppt@example.test','phone'=>'+19494002892']);
    $conn->execute_query('UPDATE presentations SET speaker_id = ? WHERE id = ?', [$other, $pid]);
    expectLinkHttp($request($assetUrl, null, $cookie)['status'] === 404 && $request($download)['body'] === file_get_contents($path), 'Speaker changes never expose an old speaker file as the new speaker resource; old QR retains attribution.');
    $conn->execute_query('UPDATE presentations SET speaker_id = ? WHERE id = ?', [$speaker, $pid]);
    $before = $request($download)['body'];
    $conn->begin_transaction(); applyPresentationAssetChanges($conn, $event, $pid, ['ppt_slidedeck'=>['action'=>'remove']]); $conn->rollback();
    expectLinkHttp($request($download)['body'] === $before, 'Rolled-back saves preserve the published file.');
    foreach (['pptx' => 'writeSizedTestSlidedeck', 'ppt' => 'writeSizedLegacySlidedeck'] as $extension => $writer) {
        $writer($path, 500 * 1024 * 1024);
        [$form, $field] = $editForm();
        $form[$field] = new CURLFile($path, 'application/octet-stream', 'large.' . $extension);
        $saved = $request('edit_engagement.php?id='.$event, $form, $cookie);
        if ($saved['status'] !== 302) {
            $doc = new DOMDocument(); @$doc->loadHTML($saved['body']); $xpath = new DOMXPath($doc); $errors = [];
            foreach ($xpath->query('//*[@role="alert"]') as $alert) $errors[] = trim($alert->textContent);
            throw new RuntimeException('500 MB '.$extension.' save returned HTTP '.$saved['status'].': '.implode(' ', $errors));
        }
        $large = $conn->execute_query('SELECT storage_key, size FROM presentation_slidedecks WHERE presentation_id=?', [$pid])->fetch_assoc();
        expectLinkHttp((int) $large['size'] === 500 * 1024 * 1024
            && hash_equals(hash_file('sha256', $path), hash_file('sha256', persistentFilePath($large['storage_key']))), 'Large upload preserves the exact bytes.');
        $head = $request($download, null, '', [], true);
        expectLinkHttp($head['status'] === 200 && str_contains($head['headers'], 'Content-Length: 524288000'), 'Large download reports its full size without buffering it.');
        expectLinkHttp(strlen($request($download, null, '', ['Range: bytes=0-31'])['body']) === 32, 'Large downloads retain byte-range support.');
        $file = fopen($path, 'ab'); fwrite($file, "\0"); fclose($file); clearstatcache(true, $path);
        [$form, $field] = $editForm(); $form[$field] = new CURLFile($path, 'application/octet-stream', 'oversized.' . $extension);
        expectLinkHttp($request('edit_engagement.php?id='.$event, $form, $cookie)['status'] === 200
            && $conn->execute_query('SELECT storage_key FROM presentation_slidedecks WHERE presentation_id=?', [$pid])->fetch_row()[0] === $large['storage_key'], 'One byte over 500 MB is rejected without replacing the saved file.');
    }
} finally {
    @unlink($path);
    $conn->query("DELETE FROM engagements WHERE id=$event"); $conn->query("DELETE FROM organizations WHERE id=$org");
    foreach ($userIds as $uid) $conn->query("DELETE FROM users WHERE id=$uid");
    foreach ($sessionIds as $id) { if (session_status()===PHP_SESSION_ACTIVE) session_write_close(); session_id($id); session_start(); session_destroy(); }
}

echo "PPT Slidedeck HTTP integration passed.\n";
