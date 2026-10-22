<?php
require_once $source.'/audience_ai.php';
$aiStart=$checks;
$aiApi=static function(int $client,?array $values=null,string $query='',?string $csrfValue=null)use($cookies,$clients):array{
    $roles=['admin','editor','reviewer','queue-editor'];
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
$aiFixture=static function(string $mode='content',string $role='admin',?int $ownerId=null)use($conn,$actorId,$uuid,&$aiIds):array{
    $nonce=$uuid();$created=false;
    $id=aiCoachRecordRequest($conn,['role'=>$role,'page'=>'audience.php','step'=>'audience-ai','question'=>'Stage 8 fixture','history'=>[],'topic'=>'audience-ai:'.$mode,'request_id'=>$nonce,'ui'=>[]],$ownerId??$actorId,$created);
    audienceHttpExpect($created&&$id!==null,'Assistant fixture has a unique purpose-bound request');$aiIds[]=$id;
    return [$id,$nonce];
};
$sendingBefore=$conn->query('SELECT COUNT(*) FROM marketing_campaign_deliveries')->fetch_row()[0];
$consentBefore=$conn->query("SELECT GROUP_CONCAT(CONCAT(id,':',email_status) ORDER BY id) FROM marketing_contacts WHERE audience_id=1")->fetch_row()[0];
foreach(['assistant','assistant&mode=analytics']as$view){$r=$admin('audience.php?view='.$view);audienceHttpExpect($r['status']===200&&!preg_match('/(?:Warning|Fatal error):/',$r['body'])&&str_contains($r['body'],'audience-ai-config'),'Assistant route renders '.$view);}
audienceHttpExpect($editor('audience.php?view=assistant')['status']===200,'Editors retain writing assistance');
foreach(['admin','editor','reviewer']as$role){
    $page=$clients[$role]('audience.php?view=assistant&mode=analytics');
    audienceHttpExpect($page['status']===200&&!preg_match('/(?:Warning|Fatal error):/',$page['body'])&&str_contains($page['body'],'aria-label="Ask Analytics AI" aria-current="page"'),'Every role can open Analytics AI with its own active sidebar entry');
}
audienceHttpExpect($reviewer('audience.php?view=assistant')['status']===403&&$aiApi(2,null,'?action=history')['status']===200,'Reviewers can access analytics history but not writing assistance');
audienceHttpExpect($aiApi(2,['action'=>'checks','text'=>'Two words'])['status']===403&&$aiApi(2,['action'=>'save_template'])['status']===403,'Read-only analytics access cannot check writing or save templates');
audienceHttpExpect($aiApi(0,['action'=>'checks','text'=>'Two words'],'','bad')['status']===403,'JSON assistant requires the native CSRF token');
audienceHttpExpect($aiApi(1,['action'=>'checks','text'=>'Two words'])['data']['facts'][0]['value']===2,'Deterministic checks work without inference');
$base=['mode'=>'analytics','operation'=>'analyze','prompt'=>'Explain changes','request_id'=>$uuid(),'role'=>'admin','evidence'=>['secret'=>'spoofed']];
audienceHttpExpect($aiApi(2,array_replace($base,['request_id'=>'bad']))['status']===400,'Reviewer analytics requests reach validation without granting the supplied administrator role');
audienceHttpExpect($aiApi(0,array_replace($base,['request_id'=>'bad']))['status']===400,'Invalid request identifier is rejected before queueing');
audienceHttpExpect($aiApi(0,array_replace($base,['window'=>['origin'=>'mailchimp']]))['status']===400,'Removed analytics source cannot be queued through the assistant API');
[$id,$nonce]=$aiFixture();
$reply=['purpose'=>'audience-ai','mode'=>'content','operation'=>'write','reason'=>'','message'=>'Ready','headline'=>'Fixture heading','text'=>'Reviewed fixture text','subjects'=>['Fixture subject'],'recommendations'=>[],'checks'=>audienceAiChecks('Reviewed fixture text')];
aiCoachCompleteRequest($conn,$id,$reply,1);
audienceHttpExpect($aiApi(0,null,'?request_id='.$nonce)['data']['response']['text']==='Reviewed fixture text','Owner can reopen the exact completed result');
audienceHttpExpect($aiApi(0,null,'?request_id='.$nonce)['data']['input']===null,'Older requests without retained input do not invent source text');
$legacyInput=audienceAiRequest(['request_id'=>$nonce,'operation'=>'tone','text'=>"Original source *|FNAME|*\nSecond paragraph.",'subject'=>'Original subject','prompt'=>'Warm this up','tone'=>'warm'],'admin');
$conn->execute_query("INSERT INTO ai_coach_jobs(request_id,request_json,state,deadline_at) VALUES (?,?,'complete',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 SECOND))",[$id,json_encode($legacyInput+['brand'=>['name'=>'Not part of the saved writing fields']])]);
$legacyReopened=$aiApi(0,null,'?request_id='.$nonce)['data']['input'];
audienceHttpExpect($legacyReopened===array_intersect_key($legacyInput,array_flip(['text','subject','prompt','operation','tone'])),'Existing history recovers the exact validated source and writing controls from its stored job');
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
foreach([1=>'editor',2=>'reviewer']as$client=>$role){
    $roleRequest=array_replace($base,['request_id'=>$uuid()]);$queuedResult=$aiApi($client,$roleRequest);
    audienceHttpExpect($queuedResult['status']===202,'Analytics generation enters the existing queue for '.$role);
    $queued=$conn->execute_query('SELECT j.request_json FROM ai_coach_jobs j JOIN ai_coach_requests r ON r.id=j.request_id WHERE r.client_request_id=?',[$roleRequest['request_id']])->fetch_assoc();
    $context=json_decode($queued['request_json'],true);
    audienceHttpExpect($context['role']===$role&&!isset($context['evidence']),'Queued analytics trusts the session role and server scope for '.$role);
    audienceHttpExpect($aiApi($client,['action'=>'cancel','request_id'=>$roleRequest['request_id']])['data']['saved'],'Own analytics cancellation works for '.$role);
    $roleActorId=(int)$conn->execute_query('SELECT id FROM users WHERE username=?',['audience-'.$role.'-'.$suffix])->fetch_row()[0];
    [$roleResultId,$roleResultNonce]=$aiFixture('analytics',$role,$roleActorId);
    aiCoachCompleteRequest($conn,$roleResultId,['purpose'=>'audience-ai','mode'=>'analytics','reason'=>'','summary'=>'Saved contact results','observations'=>[],'recommendations'=>[],'evidence'=>$pageEvidence],1);
    audienceHttpExpect($aiApi($client,null,'?request_id='.$roleResultNonce)['status']===200,'Own analytics result is readable by '.$role);
    $history=$aiApi($client,null,'?action=history')['data']['items'];
    audienceHttpExpect(in_array($roleResultNonce,array_column($history,'client_request_id'),true),'Own analytics history is visible to '.$role);
    $pageResult=$aiApi($client,null,'?action=contacts&request_id='.$roleResultNonce.'&table_id=query_0_contacts&offset=0');
    audienceHttpExpect($pageResult['status']===200&&($pageResult['data']['table']['rows'][0]['email']??'')==='fixture@example.test','Own saved contact results can be paged by '.$role);
    audienceHttpExpect($aiApi(0,null,'?request_id='.$roleResultNonce)['status']===404,'Another user cannot read '.$role.' analytics results');
}
// Native AI response deletion is owner-scoped, permanent, confirmed, and available to Editor+ without elevation.
$coSetElevation(null);
foreach([0=>'admin',1=>'editor']as$deleteClient=>$deleteRole){
    $deleteActor=(int)$conn->execute_query('SELECT id FROM users WHERE username=?',['audience-'.$deleteRole.'-'.$suffix])->fetch_row()[0];
    audienceHttpExpect($aiApi($deleteClient,null,'?action=history')['data']['can_remove_history']===true,'History deletion is available to '.$deleteRole.' without Admin Unlock');
    foreach(['analytics','content']as$deleteMode){
        [$deleteId,$deleteNonce]=$aiFixture($deleteMode,$deleteRole,$deleteActor);$deleteClaim=bin2hex(random_bytes(16));
        $conn->execute_query("INSERT INTO ai_coach_jobs(request_id,request_json,state,claim_token,deadline_at) VALUES (?,?,'generating',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 20 SECOND))",[$deleteId,'{}',$deleteClaim]);
        $deletePost=['action'=>'delete','request_id'=>$deleteNonce,'confirmed'=>true];
        audienceHttpExpect($aiApi($deleteClient,$deletePost,'','bad')['status']===403,'AI deletion requires CSRF for '.$deleteRole.' '.$deleteMode);
        audienceHttpExpect($aiApi($deleteClient,array_replace($deletePost,['confirmed'=>false]))['status']===400&&$aiApi($deleteClient,null,'?request_id='.$deleteNonce)['status']===200,'Unconfirmed deletion preserves '.$deleteRole.' '.$deleteMode);
        audienceHttpExpect($aiApi(1-$deleteClient,$deletePost)['status']===404,'AI deletion cannot target another user’s '.$deleteMode.' request');
        audienceHttpExpect($aiApi($deleteClient,$deletePost)['data']['deleted']===true,'Confirmed '.$deleteRole.' '.$deleteMode.' deletion succeeds without Admin Unlock');
        audienceHttpExpect(!$conn->execute_query('SELECT id FROM ai_coach_requests WHERE id=?',[$deleteId])->fetch_assoc()&&!$conn->execute_query('SELECT request_id FROM ai_coach_jobs WHERE request_id=?',[$deleteId])->fetch_assoc(),'Permanent deletion removes source, response, and queue data');
        aiCoachFinishJob($conn,['request_id'=>$deleteId,'claim_token'=>$deleteClaim,'queue_ms'=>0],$reply,1);
        audienceHttpExpect($aiApi($deleteClient,null,'?request_id='.$deleteNonce)['status']===404&&$aiApi($deleteClient,$deletePost)['status']===404,'Late completion and repeated deletion cannot restore the removed request');
    }
}
$reviewActor=(int)$conn->execute_query('SELECT id FROM users WHERE username=?',['audience-reviewer-'.$suffix])->fetch_row()[0];
[$reviewDeleteId,$reviewDeleteNonce]=$aiFixture('analytics','reviewer',$reviewActor);
audienceHttpExpect($aiApi(2,null,'?action=history')['data']['can_remove_history']===false&&$aiApi(2,['action'=>'delete','request_id'=>$reviewDeleteNonce,'confirmed'=>true])['status']===403,'Reviewer cannot delete even their own analytics response');
[$foreignPurposeId,$foreignPurposeNonce]=$aiFixture();$conn->execute_query("UPDATE ai_coach_requests SET guided_step='other' WHERE id=?",[$foreignPurposeId]);
audienceHttpExpect($aiApi(0,['action'=>'delete','request_id'=>$foreignPurposeNonce,'confirmed'=>true])['status']===404&&$conn->execute_query('SELECT id FROM ai_coach_requests WHERE id=?',[$foreignPurposeId])->fetch_assoc(),'Native AI deletion cannot remove other AI Coach history');
[$oldRoleId,$oldRoleNonce]=$aiFixture('analytics','editor');
audienceHttpExpect($aiApi(0,['action'=>'delete','request_id'=>$oldRoleNonce,'confirmed'=>true])['status']===404,'Deletion retains the current-role ownership boundary');
$held=[];for($n=0;$n<8;$n++){[$heldId]=$aiFixture();$held[]=$heldId;$conn->execute_query("INSERT INTO ai_coach_jobs(request_id,request_json,state,claim_token,deadline_at) VALUES (?,?,'generating',?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND))",[$heldId,'{}',bin2hex(random_bytes(16))]);}
// This session has not generated analytics; test queue admission without its request throttle.
$fullNonce=$uuid();$fullInput=['mode'=>'content','operation'=>'rewrite','text'=>"Retained source text\nWith a second paragraph.",'subject'=>'Retained subject','tone'=>'warm','prompt'=>'A short fixture','request_id'=>$fullNonce];
$full=$aiApi(3,$fullInput);
audienceHttpExpect($full['status']===429&&str_contains($full['data']['error'],'busy'),'Native and existing help share the bounded queue capacity');
$fullSaved=$conn->execute_query('SELECT id,ui_state_json FROM ai_coach_requests WHERE client_request_id=?',[$fullNonce])->fetch_assoc();$aiIds[]=$fullSaved['id'];
audienceHttpExpect(audienceJson($fullSaved['ui_state_json'])['writing_input']==array_intersect_key($fullInput,array_flip(['text','subject','prompt','operation','tone'])),'New writing requests persist their source and controls in the request record');
audienceHttpExpect($aiApi(3,null,'?request_id='.$fullNonce)['data']['input']==array_intersect_key($fullInput,array_flip(['text','subject','prompt','operation','tone']))&&!$conn->execute_query('SELECT request_id FROM ai_coach_jobs WHERE request_id=?',[$fullSaved['id']])->fetch_assoc(),'Saved writing input reopens without a retained queue job');
// Import/restore order can differ from timestamp order; take the newest twenty before displaying either mode.
$chronologicalNonces=[];$chronologicalStart=new DateTimeImmutable('+30 days',new DateTimeZone('UTC'));
for($historyIndex=0;$historyIndex<22;$historyIndex++){
    [$historyId,$historyNonce]=$aiFixture($historyIndex%2===0?'analytics':'content');$chronologicalNonces[]=$historyNonce;
    $historyDate=$chronologicalStart->modify('-'.max(0,$historyIndex-1).' seconds')->format('Y-m-d H:i:s.u');
    $conn->execute_query('UPDATE ai_coach_requests SET created_at=? WHERE id=?',[$historyDate,$historyId]);
}
$expectedHistory=$chronologicalNonces;[$expectedHistory[0],$expectedHistory[1]]=[$expectedHistory[1],$expectedHistory[0]];
$orderedHistory=$aiApi(0,null,'?action=history')['data']['items'];
audienceHttpExpect(array_column($orderedHistory,'client_request_id')===array_slice($expectedHistory,0,20),'Both AI modes use timestamp-descending history before the twenty-item limit, with newest ID first for tied timestamps');
foreach(['audience-ai:analytics','audience-ai:content']as$historyTopic){
    $modeTimes=array_column(array_values(array_filter($orderedHistory,static fn($row)=>$row['manual_topic']===$historyTopic)),'created_at');$sortedTimes=$modeTimes;rsort($sortedTimes,SORT_STRING);
    audienceHttpExpect($modeTimes===$sortedTimes,'Recent requests are newest-first for '.$historyTopic);
}
foreach($aiIds as$fixtureId)$conn->execute_query('DELETE FROM ai_coach_requests WHERE id=?',[$fixtureId]);
audienceHttpExpect($conn->query('SELECT COUNT(*) FROM marketing_campaign_deliveries')->fetch_row()[0]===$sendingBefore,'Assistant generation and applying never create delivery jobs');
audienceHttpExpect($conn->query("SELECT GROUP_CONCAT(CONCAT(id,':',email_status) ORDER BY id) FROM marketing_contacts WHERE audience_id=1")->fetch_row()[0]===$consentBefore,'Assistant generation and applying never alter subscription consent');
echo 'MessageMonkey AI HTTP qualification passed ('.($checks-$aiStart)." checks).\n";
