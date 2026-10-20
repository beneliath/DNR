<?php
require_once $source.'/audience_ai.php';
$aiStart=$checks;
$aiApi=static function(int $client,?array $values=null,string $query='',?string $csrfValue=null)use($cookies,$clients):array{
    $roles=['admin','editor','reviewer'];
    $csrfValue??=audienceHttpCsrf($clients[$roles[$client]]('audience.php'));
    $curl=curl_init('http://127.0.0.1/audience_ai_api.php'.$query);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookies[$client],CURLOPT_COOKIEJAR=>$cookies[$client],CURLOPT_TIMEOUT=>10]);
    if($values!==null)curl_setopt_array($curl,[CURLOPT_POSTFIELDS=>json_encode($values,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$csrfValue]]);
    $body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'data'=>json_decode($body,true),'body'=>$body];
};
$uuid=static fn():string=>bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
$actorId=(int)$conn->execute_query('SELECT id FROM users WHERE username=?',['audience-admin-'.$suffix])->fetch_row()[0];
$aiIds=[];
$aiFixture=static function(string $mode='content',string $role='admin')use($conn,$actorId,$uuid,&$aiIds):array{
    $nonce=$uuid();$created=false;
    $id=aiCoachRecordRequest($conn,['role'=>$role,'page'=>'audience.php','step'=>'audience-ai','question'=>'Stage 8 fixture','history'=>[],'topic'=>'audience-ai:'.$mode,'request_id'=>$nonce,'ui'=>[]],$actorId,$created);
    audienceHttpExpect($created&&$id!==null,'Assistant fixture has a unique purpose-bound request');$aiIds[]=$id;
    return [$id,$nonce];
};
$sendingBefore=$conn->query('SELECT COUNT(*) FROM marketing_campaign_deliveries')->fetch_row()[0];
$consentBefore=$conn->query("SELECT GROUP_CONCAT(CONCAT(id,':',email_status) ORDER BY id) FROM marketing_contacts WHERE audience_id=1")->fetch_row()[0];
foreach(['assistant','assistant&mode=analytics']as$view){$r=$admin('audience.php?view='.$view);audienceHttpExpect($r['status']===200&&!preg_match('/(?:Warning|Fatal error):/',$r['body'])&&str_contains($r['body'],'audience-ai-config'),'Assistant route renders '.$view);}
audienceHttpExpect($editor('audience.php?view=assistant')['status']===200&&$editor('audience.php?view=assistant&mode=analytics')['status']===403,'Analytics AI is admin-only while editors can write');
audienceHttpExpect($reviewer('audience.php?view=assistant')['status']===403&&$aiApi(2,null,'?action=history')['status']===403,'Reviewer cannot access assistant or its history');
audienceHttpExpect($aiApi(0,['action'=>'checks','text'=>'Two words'],'','bad')['status']===403,'JSON assistant requires the native CSRF token');
audienceHttpExpect($aiApi(1,['action'=>'checks','text'=>'Two words'])['data']['facts'][0]['value']===2,'Deterministic checks work without inference');
$base=['mode'=>'analytics','operation'=>'analyze','prompt'=>'Explain changes','request_id'=>$uuid(),'role'=>'admin','evidence'=>['secret'=>'spoofed']];
audienceHttpExpect($aiApi(1,$base)['status']===403,'Client role cannot elevate editor analytics access');
audienceHttpExpect($aiApi(0,array_replace($base,['request_id'=>'bad']))['status']===400,'Invalid request identifier is rejected before queueing');
audienceHttpExpect($aiApi(0,array_replace($base,['window'=>['origin'=>'mailchimp']]))['status']===400,'Removed analytics source cannot be queued through the assistant API');
[$id,$nonce]=$aiFixture();
$reply=['purpose'=>'audience-ai','mode'=>'content','operation'=>'write','reason'=>'','message'=>'Ready','headline'=>'Fixture heading','text'=>'Reviewed fixture text','subjects'=>['Fixture subject'],'recommendations'=>[],'checks'=>audienceAiChecks('Reviewed fixture text')];
aiCoachCompleteRequest($conn,$id,$reply,1);
audienceHttpExpect($aiApi(0,null,'?request_id='.$nonce)['data']['response']['text']==='Reviewed fixture text','Owner can reopen the exact completed result');
audienceHttpExpect($aiApi(1,null,'?request_id='.$nonce)['status']===404&&$aiApi(1,['action'=>'cancel','request_id'=>$nonce])['status']===404,'Another user cannot read or cancel a result');
[$pageId,$pageNonce]=$aiFixture('analytics');
$pageTools=new AudienceAiQueries(new AudienceStore($conn,1,$actorId,'Pagination qualification'),audienceAiReportWindow([]));
$pageEvidence=$pageTools->execute(['queries'=>[['tool'=>'contacts','field'=>'','search'=>'fixture@example.test','filters'=>[],'limit'=>1]]]);
aiCoachCompleteRequest($conn,$pageId,['purpose'=>'audience-ai','mode'=>'analytics','reason'=>'','summary'=>'Saved contact results','observations'=>[],'recommendations'=>[],'evidence'=>$pageEvidence],1);
$pageQuery='?action=contacts&request_id='.$pageNonce.'&table_id=query_0_contacts&offset=0';
$contactPage=$aiApi(0,null,$pageQuery);
audienceHttpExpect($contactPage['status']===200&&($contactPage['data']['table']['rows'][0]['email']??'')==='fixture@example.test','Owner pagination reuses only the saved contact filter');
audienceHttpExpect($aiApi(1,null,$pageQuery)['status']===404,'Another user cannot page through contact results');
audienceHttpExpect($aiApi(0,null,str_replace('query_0_contacts','query_9_contacts',$pageQuery))['status']===403,'Unavailable contact tables cannot be selected');
audienceHttpExpect($aiApi(0,null,str_replace('offset=0','offset=-1',$pageQuery))['status']===400,'Contact pagination rejects invalid offsets');
audienceHttpExpect($aiApi(1,['action'=>'save_template','request_id'=>$nonce,'text'=>'Other user','name'=>'Forbidden'])['status']===403,'Another user cannot apply a result');
$history=$aiApi(0,null,'?action=history')['data']['items'];audienceHttpExpect(in_array($nonce,array_column($history,'client_request_id'),true),'Own purpose-filtered history contains the completed request');
[$roleId,$roleNonce]=$aiFixture('content','editor');aiCoachCompleteRequest($conn,$roleId,$reply,1);
audienceHttpExpect($aiApi(0,null,'?request_id='.$roleNonce)['status']===404,'A result made in another role is not disclosed');
$save=['action'=>'save_template','request_id'=>$nonce,'name'=>'Stage 8 reviewed fixture','headline'=>'A <script>literal</script> heading','text'=>"Reviewed <img src=x onerror=alert(1)> text\nSecond line"];
$saved=$aiApi(0,$save);audienceHttpExpect($saved['status']===200&&isset($saved['data']['redirect']),'Reviewed result creates an editable native template');
preg_match('/id=(\d+)/',$saved['data']['redirect'],$savedMatch);$aiTemplateId=(int)$savedMatch[1];
$again=$aiApi(0,$save);audienceHttpExpect($again['data']['redirect']===$saved['data']['redirect'],'Retrying an apply creates only one template');
$template=$campaignStore->designOwner('template',$aiTemplateId);
audienceHttpExpect(str_contains($template['design']['rows'][0]['columns'][0][1]['text'],'<img'),'Reviewed text stays literal native text, not executable HTML');
$designer=$admin($saved['data']['redirect']);audienceHttpExpect($designer['status']===200&&str_contains($designer['body'],'Write with AI')&&str_contains($designer['body'],'audience-ai-config'),'Saved template opens the native designer with inline assistance');
[$pendingId,$pendingNonce]=$aiFixture();$claim=bin2hex(random_bytes(16));
$conn->execute_query("INSERT INTO ai_coach_jobs(request_id,request_json,state,claim_token,deadline_at) VALUES (?,?,'generating',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 SECOND))",[$pendingId,'{}',$claim]);
audienceHttpExpect($aiApi(0,['action'=>'cancel','request_id'=>$pendingNonce])['data']['saved'],'Owner can cancel a running request');
aiCoachFinishJob($conn,['request_id'=>$pendingId,'claim_token'=>$claim,'queue_ms'=>0],$reply,1);
$cancelled=$aiApi(0,null,'?request_id='.$pendingNonce);audienceHttpExpect($cancelled['data']['response']['reason']==='cancelled','Late completion cannot replace cancellation');
audienceHttpExpect($aiApi(0,['action'=>'save_template','request_id'=>$pendingNonce,'name'=>'Forbidden','text'=>'Cancelled'])['status']===400,'Cancelled result cannot be applied');
$duplicate=$aiApi(0,array_replace($base,['request_id'=>$nonce]));audienceHttpExpect($duplicate['status']===403,'Same nonce cannot cross writing and analytics purpose');
// Complete a real HTTP queue admission with no network inference, then inspect only its stored context.
$analysis=$aiApi(0,$base);audienceHttpExpect($analysis['status']===202,'Admin analysis enters the existing queue');
$queued=$conn->execute_query('SELECT j.request_json FROM ai_coach_jobs j JOIN ai_coach_requests r ON r.id=j.request_id WHERE r.client_request_id=?',[$base['request_id']])->fetch_assoc();
$context=json_decode($queued['request_json'],true);
audienceHttpExpect(isset($context['analysis_scope']['dataset'])&&!isset($context['evidence'])&&!str_contains($queued['request_json'],'spoofed'),'Only the server-validated query scope enters the queue; client evidence is ignored');
$aiApi(0,['action'=>'cancel','request_id'=>$base['request_id']]);
$held=[];for($n=0;$n<8;$n++){[$heldId]=$aiFixture();$held[]=$heldId;$conn->execute_query("INSERT INTO ai_coach_jobs(request_id,request_json,state,claim_token,deadline_at) VALUES (?,?,'generating',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND))",[$heldId,'{}',bin2hex(random_bytes(16))]);}
// Separate editor session avoids per-session throttling masking queue admission.
$full=$aiApi(1,['mode'=>'content','operation'=>'write','prompt'=>'A short fixture','request_id'=>$uuid()]);
audienceHttpExpect($full['status']===429&&str_contains($full['data']['error'],'busy'),'Native and existing help share the bounded queue capacity');
foreach($aiIds as$fixtureId)$conn->execute_query('DELETE FROM ai_coach_requests WHERE id=?',[$fixtureId]);
audienceHttpExpect($conn->query('SELECT COUNT(*) FROM marketing_campaign_deliveries')->fetch_row()[0]===$sendingBefore,'Assistant generation and applying never create delivery jobs');
audienceHttpExpect($conn->query("SELECT GROUP_CONCAT(CONCAT(id,':',email_status) ORDER BY id) FROM marketing_contacts WHERE audience_id=1")->fetch_row()[0]===$consentBefore,'Assistant generation and applying never alter subscription consent');
echo 'MessageMonkey AI HTTP qualification passed ('.($checks-$aiStart)." checks).\n";
