<?php
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once $source.'/audience_dispatch_worker.php';
$dispatchStart=$checks;
$conn->query('UPDATE marketing_delivery_policy SET bulk_approved=1,hourly_budget=100000,daily_budget=100000,interval_seconds=1 WHERE audience_id=1');
// These fictional identities are only installed in the marked disposable database.
$conn->execute_query("UPDATE marketing_audiences SET settings_json=JSON_SET(settings_json,'$.mailing_address','Legacy audience address','$.brand.mailing_address','123 Dispatch Fixture Road') WHERE id=1");
$conn->query("UPDATE marketing_ses_settings SET from_email='sender@dispatch.example',check_state='ready',checked_at=UTC_TIMESTAMP() WHERE audience_id=1");
$dispatchAudience=$campaignStore->one('SELECT * FROM marketing_audiences WHERE id=1');$dispatchBase='https://message-monkey.example.test';
$conn->query("INSERT INTO marketing_tags(audience_id,name) VALUES (1,'Dispatch fixture')");$dispatchTag=(int)$conn->insert_id;$dispatchPeople=[];
for($i=0;$i<6;$i++){$conn->execute_query("INSERT INTO marketing_contacts(audience_id,email,first_name,email_status,notes) VALUES (1,?,?,'subscribed','')",['dispatch'.$i.'@example.test','Fixture '.$i]);$pid=(int)$conn->insert_id;$dispatchPeople[]=$pid;$conn->execute_query('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$pid,$dispatchTag]);}
$makeDispatch=static function(string $name,bool $scheduled=true)use($campaignStore,$dispatchTag):int{
 $id=$campaignStore->createCampaign(['name'=>$name,'campaign_type'=>'plain-text']);
 foreach(['to'=>['target'=>'tag:'.$dispatchTag],'from'=>['from_name'=>'Fixture team','from_email'=>'sender@dispatch.example'],'subject'=>['subject'=>'Hello *|FNAME|*'],'content'=>['text'=>'Fictional newsletter body.'],'send_time'=>['send_mode'=>$scheduled?'schedule':'now','delivery'=>'specific','timezone'=>'UTC','delivery_date'=>gmdate('Y-m-d',time()+86400),'delivery_time'=>'12:00']]as$section=>$input){$c=$campaignStore->campaign($id);$campaignStore->saveCampaignSection($id,(int)$c['version'],$section,$input);}return $id;
};
$sendPath=static fn($id)=>'audience.php?view=campaign-send&id='.$id;
$proofFrom=static function(array $page):string{preg_match('/name="review_proof" value="([^"]+)"/',$page['body'],$m);return html_entity_decode($m[1]??'',ENT_QUOTES);};
$dispatchId=$makeDispatch('Scheduled dispatch fixture');$send=$sendPath($dispatchId);$review=$editor($send);$proof=$proofFrom($review);
audienceHttpExpect($review['status']===200&&$proof!==''&&str_contains($review['body'],'Ready to schedule?')&&!preg_match('/Warning:|Fatal error:/',$review['body']),'Send review renders real eligible count and confirmation');
$conn->query('UPDATE marketing_delivery_policy SET bulk_approved=0 WHERE audience_id=1');
$blockedReview=$editor($send);
audienceHttpExpect($proofFrom($blockedReview)===''&&str_contains($blockedReview['body'],'Campaign sending is gated'),'SES approval alone cannot authorize a campaign review');
$testVersion=(int)$campaignStore->campaign($dispatchId)['version'];
audienceHttpExpect($campaignStore->queueCampaignTest($dispatchId,$testVersion,['test_recipients'=>'qualification@example.test','request_key'=>bin2hex(random_bytes(32))],$dispatchAudience,$dispatchBase)===1,'Controlled tests can queue while campaign approval remains closed');
$controlledTests=[];
processAudienceServiceMail($conn,1,static function(array $m,string $kind)use(&$controlledTests):string{$controlledTests[]=$m['email'];return 'accepted';});
audienceHttpExpect($controlledTests===['qualification@example.test'],'The test lane delivers only its explicit recipient with campaign approval closed');
$transportReached=false;$transportBlocked=false;
try{audienceSesDeliver($conn,[],'campaign',static function()use(&$transportReached){$transportReached=true;throw new RuntimeException('Transport must remain unreachable');});}catch(MailBudgetDeferredException){$transportBlocked=true;}
audienceHttpExpect($transportBlocked&&!$transportReached,'The SES transport independently refuses campaign delivery before contacting AWS');
$conn->query('UPDATE marketing_delivery_policy SET bulk_approved=1,daily_budget=0 WHERE audience_id=1');
audienceHttpExpect(!$campaignStore->sendingReadiness('sender@dispatch.example',audienceJson($dispatchAudience['settings_json']))['ready'],'Campaign approval without a budget cannot authorize delivery');
$conn->query('UPDATE marketing_delivery_policy SET daily_budget=100000 WHERE audience_id=1');
$version=(int)$campaignStore->campaign($dispatchId)['version'];$confirm=['csrf_token'=>$token,'action'=>'campaign_dispatch_confirm','id'=>$dispatchId,'version'=>$version,'review_proof'=>$proof,'confirmed'=>'1'];
audienceHttpExpect($editor($send,array_replace($confirm,['csrf_token'=>'invalid']))['status']===400,'Send confirmation requires CSRF');
audienceHttpExpect($reviewer($send,array_replace($confirm,['csrf_token'=>$reviewToken]))['status']===403,'Reviewer cannot confirm delivery');
audienceHttpExpect($editor($send,array_replace($confirm,['confirmed'=>'0']))['status']===200&&!$campaignStore->campaignDelivery($dispatchId),'Explicit review confirmation required');
$conn->execute_query('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$dispatchPeople[0]]);
audienceHttpExpect(str_contains($editor($send,$confirm)['body'],'recipients or sender settings changed')&&!$campaignStore->campaignDelivery($dispatchId),'Changed recipient snapshot invalidates review');
$proof=$proofFrom($editor($send));$confirm['review_proof']=$proof;
audienceHttpExpect($editor($send,$confirm)['status']===302,'Schedule confirmation creates delivery');$job=$campaignStore->campaignDelivery($dispatchId);$jobId=(int)$job['id'];
audienceHttpExpect($job['state']==='scheduled'&&(int)$job['recipient_count']===6,'Scheduled snapshot has exact recipient count');
$immutable=false;try{$campaignStore->saveCampaignSection($dispatchId,(int)$campaignStore->campaign($dispatchId)['version'],'subject',['subject'=>'Unreviewed replacement']);}catch(InvalidArgumentException){$immutable=true;}audienceHttpExpect($immutable,'Confirmed email content cannot be edited');

$r=$campaignStore->one('SELECT * FROM marketing_campaign_recipients WHERE delivery_id=? ORDER BY id',[$jobId]);
audienceHttpExpect(!str_contains($r['context_ciphertext'],'example.test')&&!str_contains($job['snapshot_ciphertext'],'newsletter'),'Personalization and content encrypted at rest');
audienceHttpExpect($editor($send,$confirm)['status']===302&&(int)$campaignStore->one('SELECT COUNT(*) n FROM marketing_campaign_deliveries WHERE campaign_id=?',[$dispatchId])['n']===1,'Repeated confirmation is idempotent');
$received=[];$adapter=static function(array $m)use(&$received):string{$received[]=$m;return 'accepted';};
audienceHttpExpect(processAudienceCampaignDelivery($conn,20,$adapter)===0&&!$received,'Scheduled campaigns never dispatch early');
$opPath='audience.php?view=campaign-delivery&id='.$dispatchId;
$op=static function(string $operation)use($editor,$token,$campaignStore,$dispatchId,$opPath){$j=$campaignStore->campaignDelivery($dispatchId);return $editor($opPath,['csrf_token'=>$token,'action'=>'campaign_delivery_operation','id'=>$dispatchId,'version'=>$j['version'],'operation'=>$operation]);};
audienceHttpExpect($op('pause')['status']===302&&$campaignStore->campaignDelivery($dispatchId)['state']==='paused','Schedule can pause');
audienceHttpExpect(processAudienceCampaignDelivery($conn,20,$adapter)===0,'Paused schedule does not dispatch');
audienceHttpExpect($op('resume')['status']===302&&$campaignStore->campaignDelivery($dispatchId)['state']==='scheduled','Resume preserves future due time');
audienceHttpExpect($op('cancel')['status']===302&&$campaignStore->campaign($dispatchId)['status']==='draft','Unstarted cancelled schedule returns to editable draft');
audienceHttpExpect((int)$campaignStore->one("SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=? AND state='cancelled'",[$jobId])['n']===6,'Cancellation affects only waiting recipients');
// A new exact snapshot is required after cancellation and editing.
$c=$campaignStore->campaign($dispatchId);$campaignStore->saveCampaignSection($dispatchId,(int)$c['version'],'send_time',['send_mode'=>'now','timezone'=>'UTC','delivery'=>'specific']);$c=$campaignStore->campaign($dispatchId);$review=$campaignStore->reviewCampaignDispatch($dispatchId,$dispatchAudience,$dispatchBase);$jobId=$campaignStore->confirmCampaignDispatch($dispatchId,(int)$c['version'],$review['proof'],$dispatchAudience,$dispatchBase);
$conn->query('UPDATE marketing_delivery_policy SET bulk_approved=0 WHERE audience_id=1');$gatedAttempts=0;
processAudienceCampaignDelivery($conn,10,static function()use(&$gatedAttempts):string{$gatedAttempts++;return 'accepted';});
audienceHttpExpect($gatedAttempts===0&&$campaignStore->campaignDelivery($dispatchId)['state']==='paused','Revoking approval after confirmation pauses the campaign without invoking transport');
audienceHttpExpect((int)$campaignStore->one("SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=? AND delivery_started_at IS NOT NULL",[$jobId])['n']===0,'The approval gate preserves all unsent recipient receipts');
$conn->query('UPDATE marketing_delivery_policy SET bulk_approved=1 WHERE audience_id=1');
audienceHttpExpect($op('resume')['status']===302,'An approved campaign still requires an explicit resume after a gate pause');
$recipients=$campaignStore->all('SELECT * FROM marketing_campaign_recipients WHERE delivery_id=? ORDER BY contact_id',[$jobId]);$sealed=json_decode(\Dnr\Security\ApplicationKey::open($recipients[0]['context_ciphertext']),true);$subscription='audience_subscription.php?token='.$sealed['token'];
$get=$reviewer($subscription);audienceHttpExpect($get['status']===200&&$campaignStore->person($dispatchPeople[0])['email_status']==='subscribed','Link scanner GET cannot unsubscribe');
$oneClick=$reviewer($subscription,['List-Unsubscribe'=>'One-Click']);audienceHttpExpect($oneClick['status']===200&&$campaignStore->person($dispatchPeople[0])['email_status']==='unsubscribed','One-click POST persists opt-out');
$reviewer($subscription,['List-Unsubscribe'=>'One-Click']);audienceHttpExpect((int)$campaignStore->one('SELECT COUNT(*) n FROM marketing_email_optouts WHERE audience_id=1 AND email_hash=?',[$recipients[0]['email_hash']])['n']===1,'Unsubscribe is idempotent');
audienceHttpExpect($reviewer('audience_subscription.php?token='.str_repeat('f',64))['status']===404,'Forged unsubscribe token rejected');
// A later import/operator status edit must not bypass the recipient’s public opt-out.
$conn->execute_query("UPDATE marketing_contacts SET email_status='subscribed' WHERE id=?",[$dispatchPeople[0]]);
$conn->execute_query('UPDATE marketing_contacts SET is_archived=1 WHERE id=?',[$dispatchPeople[1]]);
$conn->execute_query("UPDATE marketing_contacts SET email='changed-dispatch@example.test' WHERE id=?",[$dispatchPeople[2]]);
// One uncertain and one safe pre-DATA retry, followed by one accepted message.
$attempts=[];$adapter=static function(array $m)use(&$received,&$attempts):string{$attempts[$m['email']]=($attempts[$m['email']]??0)+1;if($m['email']==='dispatch3@example.test')throw new SmtpUncertainDeliveryException('Fictional dropped DATA');if($m['email']==='dispatch4@example.test'&&$attempts[$m['email']]===1)throw new SmtpPreDataException('Fictional pre-DATA connection loss');$received[]=$m;return 'accepted';};
$beforeProgress=$campaignStore->campaignDelivery($dispatchId);processAudienceCampaignDelivery($conn,1,$adapter);audienceHttpExpect($campaignStore->campaignDelivery($dispatchId)['version']===$beforeProgress['version'],'Recipient progress does not invalidate pause controls');
processAudienceCampaignDelivery($conn,20,$adapter);$counts=$campaignStore->campaignDeliveryCounts($jobId);
audienceHttpExpect(($counts['suppressed']??0)==3&&($counts['uncertain']??0)==1&&($counts['accepted']??0)==1&&($counts['queued']??0)==1,'Current consent, archive and changed email suppress snapshotted recipients');
$conn->execute_query('UPDATE marketing_campaign_recipients SET next_attempt_at=UTC_TIMESTAMP() WHERE delivery_id=?',[$jobId]);processAudienceCampaignDelivery($conn,20,$adapter);$counts=$campaignStore->campaignDeliveryCounts($jobId);
audienceHttpExpect(($counts['accepted']??0)==2&&($attempts['dispatch3@example.test']??0)===1&&($attempts['dispatch4@example.test']??0)===2,'Only a known pre-DATA failure retries; ambiguous SMTP acceptance does not');
audienceHttpExpect(processAudienceCampaignDelivery($conn,20,$adapter)===0&&count($received)===2,'Completed recipients never replay');
audienceHttpExpect($campaignStore->campaignDelivery($dispatchId)['state']==='completed','Queue reaches durable completion');
$page=$editor($opPath);audienceHttpExpect($page['status']===200&&str_contains($page['body'],'Accepted by SES')&&str_contains($page['body'],'Uncertain')&&!preg_match('/Warning:|Fatal error:/',$page['body']),'Status screen shows truthful counts and uncertainty');
foreach($received as$m)audienceHttpExpect(str_contains($m['text'],'Unsubscribe:')&&str_contains($m['text'],'123 Dispatch Fixture Road')&&$m['message_id']!==''&&$m['subject']!=='Hello *|FNAME|*','Every accepted message has personalized content, Brand Kit address, footer and stable ID');
$revokedId=$makeDispatch('Revoked sender fixture',false);$v=$campaignStore->campaign($revokedId)['version'];$rev=$campaignStore->reviewCampaignDispatch($revokedId,$dispatchAudience,$dispatchBase);$revJob=$campaignStore->confirmCampaignDispatch($revokedId,(int)$v,$rev['proof'],$dispatchAudience,$dispatchBase);
$conn->query("UPDATE marketing_ses_settings SET check_state='attention' WHERE audience_id=1");processAudienceCampaignDelivery($conn,20,$adapter);
audienceHttpExpect($campaignStore->campaignDelivery($revokedId)['state']==='paused','Revoked sender authentication pauses dispatch');
$conn->query("UPDATE marketing_ses_settings SET check_state='ready',checked_at=UTC_TIMESTAMP() WHERE audience_id=1");$j=$campaignStore->campaignDelivery($revokedId);$campaignStore->campaignDeliveryOperation($revokedId,(int)$j['version'],'cancel');
// Hosted immutable image snapshots work without a login and do not expose recipient tokens.
$htmlId=$makeDispatch('HTML dispatch fixture',false);$htmlRow=$campaignStore->campaign($htmlId);$htmlDraft=$htmlRow['draft'];$png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';
$gif='data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$svg=audienceSvgImage('data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 80"><path d="M0 0h40v80H0z" fill="#3261b5"/></svg>'));
$htmlDraft['design']=audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[['type'=>'image','image'=>$png,'alt'=>'Fixture image'],['type'=>'image','image'=>$gif,'alt'=>'Animated fixture'],['type'=>'image','image'=>$svg,'alt'=>'Vector logo'],['type'=>'text','text'=>'Hello *|FNAME|*']]]]]]);
$conn->execute_query("UPDATE marketing_campaigns SET campaign_type='regular',draft_json=? WHERE id=?",[json_encode($htmlDraft),$htmlId]);
$rev=$campaignStore->reviewCampaignDispatch($htmlId,$dispatchAudience,$dispatchBase);$htmlJob=$campaignStore->confirmCampaignDispatch($htmlId,(int)$htmlRow['version'],$rev['proof'],$dispatchAudience,$dispatchBase);
$hj=$campaignStore->campaignDelivery($htmlId);$hr=$campaignStore->one('SELECT * FROM marketing_campaign_recipients WHERE delivery_id=? ORDER BY id',[$htmlJob]);$hs=json_decode(\Dnr\Security\ApplicationKey::open($hj['snapshot_ciphertext']),true);$hc=json_decode(\Dnr\Security\ApplicationKey::open($hr['context_ciphertext']),true);$hm=audienceDispatchMessage($hs,$hc['context'],$hc['token'],$hr['message_id']);
audienceHttpExpect(str_contains($hm['html'],'audience_email_asset.php?key=')&&!str_contains($hm['html'],'data:image/'),'Rendered HTML uses hosted immutable images');
$public=static function(string $path,?array $post=null):array{$curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>10]);if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$raw=curl_exec($curl);$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$size),'headers'=>substr($raw,0,$size)];};
$asset='audience_email_asset.php?key='.$hj['asset_key'].'&image='.hash('sha256',$png);$assetResponse=$public($asset);
audienceHttpExpect($assetResponse['status']===200&&$assetResponse['body']===base64_decode(substr($png,strpos($png,',')+1)),'Hosted image returns exact snapshot bytes anonymously');
$gifResponse=$public('audience_email_asset.php?key='.$hj['asset_key'].'&image='.hash('sha256',$gif));
audienceHttpExpect($gifResponse['status']===200&&str_contains(strtolower($gifResponse['headers']),'content-type: image/gif')&&$gifResponse['body']===base64_decode(substr($gif,strpos($gif,',')+1)),'Hosted GIF preserves exact animation bytes and content type');
$svgResponse=$public('audience_email_asset.php?key='.$hj['asset_key'].'&image='.hash('sha256',$svg));
audienceHttpExpect($svgResponse['status']===200&&str_contains(strtolower($svgResponse['headers']),'content-type: image/svg+xml')&&str_contains($svgResponse['headers'],"sandbox; default-src 'none'")&&$svgResponse['body']===base64_decode(explode(',',$svg)[1]),'Hosted SVG preserves vector snapshot bytes and uses a sandboxed response');
audienceHttpExpect($public('audience_email_asset.php?key='.str_repeat('0',64).'&image='.hash('sha256',$png))['status']===404,'Unrecognized asset capability is rejected');
audienceHttpExpect($public('audience_subscription.php?token='.$hc['token'].'&email=1')['status']===200,'Personal email browser view works with its private capability');
$htmlReceived=[];processAudienceCampaignDelivery($conn,20,static function($m)use(&$htmlReceived){$htmlReceived[]=$m;return 'accepted';});audienceHttpExpect(count($htmlReceived)===4,'HTML campaign dispatches only its exact confirmed snapshot');
// Recover stale claims conservatively across a worker restart.
$stale=$campaignStore->all('SELECT id FROM marketing_campaign_recipients WHERE delivery_id=? ORDER BY id LIMIT 2',[$htmlJob]);
$conn->execute_query("UPDATE marketing_campaign_recipients SET state='processing',started_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE),delivery_started_at=UTC_TIMESTAMP() WHERE id=?",[$stale[0]['id']]);
$conn->execute_query("UPDATE marketing_campaign_recipients SET state='processing',started_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE),delivery_started_at=NULL,next_attempt_at=UTC_TIMESTAMP() WHERE id=?",[$stale[1]['id']]);
$conn->execute_query("UPDATE marketing_campaign_deliveries SET state='sending' WHERE id=?",[$htmlJob]);$conn->execute_query("UPDATE marketing_campaigns SET status='sending' WHERE id=?",[$htmlId]);
$recovered=0;processAudienceCampaignDelivery($conn,20,static function($m)use(&$recovered){$recovered++;return 'accepted';});$recoveredCounts=$campaignStore->campaignDeliveryCounts($htmlJob);
audienceHttpExpect($recovered===1&&($recoveredCounts['uncertain']??0)==1,'Restart retries an unstarted claim and quarantines a possibly transmitted claim');
require __DIR__.'/audience_delivery_completion_http.php';
// Dedicated worker fixture: exactly one eligible recipient, injected log adapter only.
$conn->execute_query("UPDATE marketing_contacts SET email_status='unsubscribed' WHERE audience_id=1 AND id<>?",[$dispatchPeople[5]]);
$workerId=$makeDispatch('Dedicated worker dispatch fixture',false);$v=$campaignStore->campaign($workerId)['version'];$rev=$campaignStore->reviewCampaignDispatch($workerId,$dispatchAudience,$dispatchBase);$campaignStore->confirmCampaignDispatch($workerId,(int)$v,$rev['proof'],$dispatchAudience,$dispatchBase);
audienceHttpExpect($otherStore->campaignDelivery($workerId)===null,'Foreign audience cannot read delivery receipt');
$conn->query('UPDATE marketing_delivery_policy SET hourly_budget=3,daily_budget=3,hourly_reserve=1,daily_reserve=1,interval_seconds=2,last_permit_at=NULL WHERE audience_id=1');
echo 'Campaign dispatch HTTP/storage: '.($checks-$dispatchStart)." checks passed; all sends used a disposable adapter.\n";
