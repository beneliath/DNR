<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') { echo "Short link HTTP tests skipped (disposable server required).\n"; exit; }
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/presentation_helpers.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080','/');
if (!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost'],true)) throw new RuntimeException('Loopback server required.');
function expectLinkHttp(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$request=static function(string $path,?array $post=null,string $cookie='',array $extra=[],bool $head=false)use($base):array{
 $c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36']);
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
try{
 expectLinkHttp($request('short_links.php')['status']===302,'Statistics require authentication');
 expectLinkHttp($request('short_link_qr.php?id='.$web['id'])['status']===302,'QR downloads require authentication');
 $r=$request('surls/'.$web['code'].'?code=ffffffffffffffff');
 expectLinkHttp($r['status']===302&&str_contains($r['headers'],'Location: https://example.com/path?a=1&b=2'),'Dedicated route resolves persisted destination and ignores injected code');
 expectLinkHttp(str_contains(strtolower($r['headers']),'no-store')&&!str_contains(strtolower($r['headers']),'set-cookie:'),'Redirect is not cached and sets no visitor cookie');
 $count=fn()=>(int)$conn->query('SELECT COALESCE(SUM(visits),0) AS n FROM short_link_stats WHERE link_id='.(int)$web['id'])->fetch_assoc()['n'];
 expectLinkHttp($count()===1,'Ordinary visit counted once');
 $request('surls/'.$web['code'],null,'',[],true);
 $request('surls/'.$web['code'],null,'',['User-Agent: Googlebot']);
 expectLinkHttp($count()===1,'HEAD and bots excluded');
 expectLinkHttp($request('surls/ffffffffffffffff')['status']===404,'Unknown short link returns 404');
 expectLinkHttp(count($links)===1,'New presentation without PDF has no notes link');
 $pdf="%PDF-1.4\nNotes fixture\nstartxref\n0\n%%EOF\n";
 $asset=['data'=>$pdf,'filename'=>'http-notes.pdf','size'=>strlen($pdf),'sha256'=>hash('sha256',$pdf,true)];
 $conn->begin_transaction();
 applyPresentationAssetChanges($conn,$event,$pid,['speaker_notes'=>['action'=>'replace','asset'=>$asset]]);
 ensurePresentationShortLinks($conn,$pid);
 $conn->commit();
 $links=fetchPresentationShortLinks($conn,$pid);$notes=array_values(array_filter($links,fn($link)=>$link['link_type']==='notes'))[0];
 $notesCount=fn()=>(int)$conn->query('SELECT COALESCE(SUM(visits),0) AS n FROM short_link_stats WHERE link_id='.(int)$notes['id'])->fetch_assoc()['n'];
 $notesQrBefore=$conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$notes['id'])->fetch_assoc();
 $r=$request('surls/'.$notes['code']);
 expectLinkHttp($r['status']===302&&preg_match('/^Location:\s*(\/surls\/[a-f0-9]{16}\/speaker-notes\.pdf)\s*$/mi',$r['headers'],$downloadMatch)===1,'Scanning the existing notes QR redirects directly to a named PDF');
 $notesDownload=ltrim($downloadMatch[1],'/');
 expectLinkHttp($notesDownload==='surls/'.$notes['code'].'/speaker-notes.pdf','Download destination preserves the original bearer code');
 expectLinkHttp(!str_contains(strtolower($r['headers']),'set-cookie:')&&str_contains(strtolower($r['headers']),'no-store'),'Notes redirect needs no session and is not cached');
 expectLinkHttp(!str_contains(strtolower($r['headers']),'content-disposition:')&&$notesCount()===0,'QR navigation itself does not download or count a visit');
 expectLinkHttp($request('surls/'.$notes['code'],null,'',[],true)['status']===302&&$notesCount()===0,'HEAD follows the same redirect without counting');
 $r=$request($notesDownload);
 expectLinkHttp($r['status']===200&&$r['body']===$pdf&&str_contains($r['headers'],'inline; filename="http-notes.pdf"'),'Public notes view serves the exact PDF inline');
 expectLinkHttp(str_contains(strtolower($r['headers']),'content-type: application/pdf')&&str_contains($r['headers'],"default-src 'none'; frame-ancestors 'none'")&&str_contains(strtolower($r['headers']),'x-content-type-options: nosniff'),'Named PDF supports browser viewing with resource restrictions and nosniff');
 expectLinkHttp(preg_match('/^Content-Security-Policy:[^\r\n]*\bsandbox\b/mi',$r['headers'])===0,'The PDF response does not sandbox the browser viewer');
 expectLinkHttp(!str_contains(strtolower($r['headers']),'set-cookie:')&&$notesCount()===1,'Only the completed PDF delivery counts, without setting a cookie');
 $head=$request($notesDownload,null,'',[],true);
 expectLinkHttp($head['status']===200&&$head['body']===''&&str_contains($head['headers'],'Content-Length: '.strlen($pdf))&&$notesCount()===1,'PDF HEAD reports the file size without downloading or counting');
 $r=$request($notesDownload,null,'',['Range: bytes=3-10']);
 expectLinkHttp($r['status']===206&&$r['body']===substr($pdf,3,8),'Notes support byte ranges');
 expectLinkHttp($notesCount()===1,'Continuation ranges do not inflate downloads');
 expectLinkHttp($request($notesDownload,null,'',['Range: bytes=99999-'])['status']===416,'Invalid range rejected');
 $mobileAgents=[
  'iPhone Firefox'=>'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/142.0 Mobile/15E148 Safari/605.1.15',
  'iPhone Safari'=>'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
  'Android Chrome'=>'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36',
 ];
 foreach($mobileAgents as$agentName=>$agent){
  $scan=$request('surls/'.$notes['code'],null,'',['User-Agent: '.$agent]);
  expectLinkHttp($scan['status']===302&&str_contains($scan['headers'],'Location: /'.$notesDownload),'Same redirect for '.$agentName);
  $download=$request($notesDownload,null,'',['User-Agent: '.$agent]);
  expectLinkHttp($download['status']===200&&$download['body']===$pdf&&str_contains($download['headers'],'inline; filename="http-notes.pdf"')&&str_contains(strtolower($download['headers']),'content-type: application/pdf'),'Same inline PDF response for '.$agentName);
 }
 expectLinkHttp($notesCount()===4,'Each redirect plus download counts once');
 $r=$request($notesDownload.'?code=ffffffffffffffff&download=0',null,'',['Sec-Purpose: prefetch']);
 expectLinkHttp($r['status']===200&&$r['body']===$pdf&&$notesCount()===4,'Named PDF route ignores injected query values and excludes prefetches');
 expectLinkHttp($request($notesDownload,[])['status']===405,'PDF endpoint rejects unsupported methods');
 expectLinkHttp($request('surls/'.$web['code'].'/speaker-notes.pdf')['status']===404,'PDF endpoint cannot redirect a different resource type');
 expectLinkHttp($conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$notes['id'])->fetch_assoc()===$notesQrBefore,'Download routing preserves stored QR images and their URL');
 foreach(['admin','editor','reviewer']as$role){
  $name='qr-http-'.bin2hex(random_bytes(5));$hash=password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT);
  $stmt=$conn->prepare('INSERT INTO users(username,password,role) VALUES(?,?,?)');$stmt->bind_param('sss',$name,$hash,$role);$stmt->execute();$uid=(int)$conn->insert_id;$userIds[]=$uid;
  startSecureSession();$_SESSION=['user_id'=>$uid,'username'=>$name,'role'=>$role,'authenticated_role'=>$role,'auth_version'=>1,'auth_complete'=>true,'_csrf_token'=>bin2hex(random_bytes(32))];$csrf=$_SESSION['_csrf_token'];$sessionIds[]=session_id();$cookie=session_name().'='.session_id();session_write_close();
  foreach(['slides','notes']as$assetType){
   $assetPath='presentation_asset.php?id='.$pid.'&type='.$assetType;
   expectLinkHttp($request($assetPath)['status']===302,'Presentation download button still requires login: '.$assetType);
   $buttonDownload=$request($assetPath,null,$cookie,['User-Agent: '.$mobileAgents['iPhone Firefox']]);
   $expectedPdf=isset($uploadedPdf)?$uploadedPdf:$pdf;
   $expectedFilename=isset($uploadedPdf)?'uploaded-notes.pdf':'http-notes.pdf';
   expectLinkHttp($buttonDownload['status']===200&&$buttonDownload['body']===$expectedPdf&&str_contains(strtolower($buttonDownload['headers']),'content-type: application/pdf')&&str_contains($buttonDownload['headers'],'inline; filename="'.$expectedFilename.'"')&&str_contains($buttonDownload['headers'],"default-src 'none'; frame-ancestors 'none'"),'View buttons and the public QR use the same inline PDF response: '.$role.' '.$assetType);
  }
  $r=$request('short_links.php?presentation_id='.$pid,null,$cookie);
  expectLinkHttp($r['status']===200&&str_contains($r['body'],'HTTP QR Fixture'),'All MOED roles can view statistics: '.$role);
  expectLinkHttp(preg_match('/<script[^>]*id="short-link-stats-data"[^>]*>(.*?)<\/script>/s',$r['body'],$chartMatch)===1,'Authenticated chart data is included: '.$role);
  $chartData=json_decode($chartMatch[1],true,512,JSON_THROW_ON_ERROR);
  expectLinkHttp($chartData['total']>0 && array_sum(array_column($chartData['timeline'],'total'))===$chartData['total'],'Chart series matches the filtered visit total: '.$role);
  expectLinkHttp(str_contains($r['body'],'name="target_url"')===($role!=='reviewer'),'Reviewer sees no edit form');
  $qrReport=$request('short_links.php?id='.$web['id'],null,$cookie);
  expectLinkHttp($qrReport['status']===200&&str_contains($qrReport['body'],'Website QR Code Statistics'),'Resource-specific QR report available: '.$role);
  expectLinkHttp(preg_match('/<script[^>]*id="short-link-stats-data"[^>]*>(.*?)<\/script>/s',$qrReport['body'],$qrChartMatch)===1,'QR report contains chart data');
  $qrChartData=json_decode($qrChartMatch[1],true,512,JSON_THROW_ON_ERROR);
  expectLinkHttp($qrChartData['total']===$count(),'QR report includes only this code, excluding notes traffic');
  $qrDoc=new DOMDocument();@$qrDoc->loadHTML($qrReport['body']);$qrXpath=new DOMXPath($qrDoc);
  expectLinkHttp($qrXpath->query('//form[@id="short-link-filters"]//input[@name="id" and @value="'.$web['id'].'"]')->length===1,'Date filtering retains the QR ID');
  expectLinkHttp($qrXpath->query('//form[@id="short-link-filters"]//a[normalize-space()="Clear Filters" and @href="short_links.php?id='.$web['id'].'"]')->length===1,'Clear Filters retains the QR ID');
  expectLinkHttp($qrXpath->query('//select[@name="speaker_id" or @name="type"]')->length===0,'QR report offers date filters without cross-speaker filters');
  $engagementView=$request('view_engagement.php?id='.$event,null,$cookie);
  $viewDoc=new DOMDocument();@$viewDoc->loadHTML($engagementView['body']);$viewXpath=new DOMXPath($viewDoc);
  foreach($links as$generatedLink)expectLinkHttp($viewXpath->query('//div[contains(@class,"presentation-qr-display")]//a[@href="short_links.php?id='.$generatedLink['id'].'"]')->length===1,'Each presentation QR card opens its own statistics: '.$role);
  expectLinkHttp(!str_contains($engagementView['body'],'Presentation Statistics'),'Engagement view has no aggregate Presentation Statistics link');
  expectLinkHttp($viewXpath->query('//a[@class="presentation-view-pdf" and @href="presentation_asset.php?id='.$pid.'&type=notes" and @target="_blank" and @rel="noopener" and normalize-space()="View PDF Speaker Notes"]')->length===1,'Each uploaded notes file has its own correctly labelled new-tab button: '.$role);
  $preview=$viewXpath->query('//div[contains(@class,"presentation-qr-display")]//img')->item(0);
  expectLinkHttp($preview instanceof DOMElement && $preview->getAttribute('src')==='data:image/png;base64,'.base64_encode($web['qr_png']),'QR preview embeds the stored PNG without a separate image request');
  foreach(['png','svg']as$format){
   $path='short_link_qr.php?id='.$web['id'].'&format='.$format.'&download=1';
   $stored=$conn->query('SELECT '.$format.' AS bytes FROM short_link_qr_images WHERE link_id='.(int)$web['id'])->fetch_assoc()['bytes'];
   $r=$request($path,null,$cookie);
   expectLinkHttp($r['status']===200&&str_contains($r['headers'],'attachment;')&&$r['body']===$stored,'Authenticated QR download serves exact stored bytes: '.$role.' '.$format);
   $etag='"'.hash('sha256',$stored).'"';
   expectLinkHttp(str_contains($r['headers'],'ETag: '.$etag)&&str_contains($r['headers'],'private, max-age=300'),'Private QR caching with content validator');
   $cached=$request($path,null,$cookie,['If-None-Match: '.$etag]);
   expectLinkHttp($cached['status']===304&&$cached['body']==='','Unchanged stored images support revalidation');
   expectLinkHttp($request($path,null,'',['If-None-Match: '.$etag])['status']===302,'Revalidation still requires authentication');
   $head=$request($path,null,$cookie,[],true);
   expectLinkHttp($head['status']===200&&$head['body']===''&&str_contains($head['headers'],'Content-Length: '.strlen($stored)),'HEAD reports stored image size without a body');
  }
  $post=['link_id'=>$web['id'],'version'=>1,'target_url'=>'https://example.com/changed','is_enabled'=>1,'csrf_token'=>$csrf];
  expectLinkHttp($request('short_links.php?presentation_id='.$pid,array_replace($post,['csrf_token'=>'invalid']),$cookie)['status']===($role==='reviewer'?403:400),'CSRF and reviewer write protection');
  if ($role === 'editor') {
   $edit = $request('edit_engagement.php?id='.$event,null,$cookie);
   expectLinkHttp($edit['status']===200,'Presentation edit form available');
   $doc = new DOMDocument(); @$doc->loadHTML($edit['body']); $xpath = new DOMXPath($doc); $form = [];
   foreach ($xpath->query('//form[@id="engagement-edit-form"]//input | //form[@id="engagement-edit-form"]//select | //form[@id="engagement-edit-form"]//textarea') as $control) {
    $name=$control->getAttribute('name'); $type=$control->getAttribute('type');
    if ($control->hasAttribute('disabled') || $xpath->query('ancestor::template',$control)->length > 0) continue;
    if($name===''||in_array($type,['file','submit','button'],true)||($type==='checkbox'||$type==='radio')&&!$control->hasAttribute('checked'))continue;
    if($control->tagName==='textarea')$value=$control->textContent;
    elseif($control->tagName==='select'){$value='';foreach($control->getElementsByTagName('option')as$index=>$option){if($index===0||$option->hasAttribute('selected'))$value=$option->getAttribute('value');if($option->hasAttribute('selected'))break;}}
    else $value=$control->getAttribute('value');
    $form[$name]=$value;
   }
   $input=$xpath->query('//input[@type="file" and contains(@name,"[speaker_notes]")]')->item(0);
   expectLinkHttp($input instanceof DOMElement,'Notes upload field present');
   expectLinkHttp($xpath->query('//div[contains(@class,"presentation-entry")]//input[@type="file"]')->length===1,'Exactly one PDF upload appears per presentation');
   expectLinkHttp(str_contains($edit['body'],'No replacement selected'),'Saved PDF is distinguished from a pending replacement');
   $path=tempnam(sys_get_temp_dir(),'qr-notes-upload-');
   $uploadedPdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nxref\n0 2\n0000000000 65535 f \n0000000009 00000 n \ntrailer\n<< /Root 1 0 R /Size 2 >>\nstartxref\n52\n%%EOF\n";
   file_put_contents($path,$uploadedPdf);
   try {
    $form[$input->getAttribute('name')]=new CURLFile($path,'application/pdf','uploaded-notes.pdf');$form['save_engagement']='1';
    $saved=$request('edit_engagement.php?id='.$event,$form,$cookie);
    if ($saved['status'] !== 302) { preg_match('/<div[^>]*class="[^"]*form-error-summary[^"]*"[^>]*>(.*?)<\/div>/s', $saved['body'], $failure); throw new RuntimeException('Notes upload failed with HTTP '.$saved['status'].': '.strip_tags($failure[1] ?? substr($saved['body'], 0, 200))); }
    expectLinkHttp($request('surls/'.$notes['code'])['status']===302&&$request($notesDownload)['body']===$uploadedPdf,'Uploaded notes replace the PDF behind the original QR and download URLs');
   }finally{unlink($path);}
  }
  if($role==='reviewer')expectLinkHttp($request('short_links.php',$post,$cookie)['status']===403,'Reviewer cannot mutate even with valid CSRF');
 }
 // Exercise multiple presentations independently, including cards without notes.
 $moreIds=[];
 for($index=2;$index<=4;$index++){
  $conn->begin_transaction();
  $conn->query("INSERT INTO presentations (engagement_id,speaker_id,topic_title) VALUES ($event,$speaker,'Presentation $index')");
  $moreId=(int)$conn->insert_id;$moreIds[]=$moreId;
  if($index<4)applyPresentationAssetChanges($conn,$event,$moreId,['speaker_notes'=>['action'=>'replace','asset'=>$asset]]);
  ensurePresentationShortLinks($conn,$moreId);$conn->commit();
 }
 $multiView=$request('view_engagement.php?id='.$event,null,$cookie);
 $multiDoc=new DOMDocument();@$multiDoc->loadHTML($multiView['body']);$multiXpath=new DOMXPath($multiDoc);
 expectLinkHttp($multiXpath->query('//a[@class="presentation-view-pdf"]')->length===3,'All three presentations with PDFs have view buttons; an empty presentation has none');
 foreach(array_merge([$pid],array_slice($moreIds,0,2))as$notesId){
  expectLinkHttp($multiXpath->query('//a[@class="presentation-view-pdf" and @href="presentation_asset.php?id='.$notesId.'&type=notes" and @target="_blank" and normalize-space()="View PDF Speaker Notes"]')->length===1,'Presentation '.$notesId.' opens its own Speaker Notes');
 }
 // Remove the added test presentations before the single-resource removal assertions.
 $conn->query('DELETE FROM presentations WHERE id IN ('.implode(',',$moreIds).')');
 $conn->begin_transaction();
 applyPresentationAssetChanges($conn,$event,$pid,['speaker_notes'=>['action'=>'remove']]);
 ensurePresentationShortLinks($conn,$pid);$conn->commit();
 $removedView=$request('view_engagement.php?id='.$event,null,$cookie);
 expectLinkHttp(!str_contains($removedView['body'],'alt="Speaker Notes QR code"')&&!str_contains($removedView['body'],'Speaker Notes QR Code Statistics'),'Removed notes leave no QR image or placeholder card in Presentations');
 expectLinkHttp($request('surls/'.$notes['code'])['status']===404,'Removed notes are unavailable publicly');
 expectLinkHttp($request($notesDownload)['status']===404,'An already resolved download URL cannot serve removed notes');
 $conn->begin_transaction();
 applyPresentationAssetChanges($conn,$event,$pid,['speaker_notes'=>['action'=>'replace','asset'=>$asset]]);
 ensurePresentationShortLinks($conn,$pid);$conn->commit();
 expectLinkHttp(str_contains($request('view_engagement.php?id='.$event,null,$cookie)['body'],'alt="Speaker Notes QR code"'),'Re-uploaded notes reveal the original QR code');
 expectLinkHttp($request('surls/'.$notes['code'])['status']===302&&$request($notesDownload)['body']===$pdf,'Re-uploading restores the same automatic download chain');
 updateShortLink($conn,(int)$notes['id'],1,'',false);
 expectLinkHttp($request('surls/'.$notes['code'])['status']===410&&$request($notesDownload)['status']===410,'Disabling notes revokes both the QR and resolved PDF URL');
 $missingCode=bin2hex(random_bytes(8));
 $conn->query("INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type,target_url) VALUES ('$missingCode',$event,$pid,$speaker,'bio','https://example.com/bio')");
 $missingId=(int)$conn->insert_id;
 expectLinkHttp($request('short_link_qr.php?id='.$missingId,null,$cookie)['status']===503,'An unprepared legacy QR is never rendered on demand');
 expectLinkHttp($conn->query("SELECT link_id FROM short_link_qr_images WHERE link_id=$missingId")->num_rows===0,'Viewing a missing QR does not write images to the database');
 $before=$conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$web['id'])->fetch_assoc();
 expectLinkHttp(backfillShortLinkQrImages($conn)===1,'Explicit backfill prepares the legacy link');
 expectLinkHttp(backfillShortLinkQrImages($conn)===0,'Backfill is safely re-runnable without re-rendering existing images');
 expectLinkHttp($conn->query('SELECT * FROM short_link_qr_images WHERE link_id='.(int)$web['id'])->fetch_assoc()===$before,'Backfill preserves existing image bytes, encoded URL and creation time');
 expectLinkHttp($request('short_link_qr.php?id='.$missingId,null,$cookie)['status']===200,'Backfilled image is immediately available');
 updateShortLink($conn,(int)$web['id'],1,'https://example.com/new',false);
 expectLinkHttp($request('surls/'.$web['code'])['status']===410,'Disabled links return 410');
 expectLinkHttp($count()===1,'Disabled links do not record visits');
}finally{
 $conn->query("DELETE FROM engagements WHERE id=$event");$conn->query("DELETE FROM organizations WHERE id=$org");foreach($userIds as$uid)$conn->query("DELETE FROM users WHERE id=$uid");
 foreach($sessionIds as$id){if(session_status()===PHP_SESSION_ACTIVE)session_write_close();session_id($id);session_start();session_destroy();}
}

echo "Short link HTTP integration tests passed.\n";
