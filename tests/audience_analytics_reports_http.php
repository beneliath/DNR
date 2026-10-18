<?php
// This file runs only inside the already verified disposable Audience suite.
$activityResult=$as->analyticsActivity($window,'accepted');
audienceHttpExpect($activityResult['total']===36&&count($activityResult['rows'])===25,'Recipient pagination counts distinct accepted messages without event fan-out');
audienceHttpExpect($as->analyticsActivity($window,'opens')['total']===19,'Recipient open drilldown reconciles with the headline after repeated observations');
audienceHttpExpect($as->analyticsActivity($window,'clicks')['total']===3,'Recipient clicks obey the dashboard bot filter');
audienceHttpExpect($as->analyticsActivity(array_replace($window,['bots'=>'all']),'clicks')['total']===6,'Unfiltered recipient activity consistently includes detected bots');
$last=$as->analyticsActivity($window,'accepted',2);audienceHttpExpect(count($last['rows'])===11&&!array_intersect(array_column($last['rows'],'id'),array_column($activityResult['rows'],'id')),'Recipient pagination has neither duplicates nor omissions');
audienceHttpExpect(str_ends_with($last['rows'][0]['destination'],'@example.test'),'Recipient destinations come from the accepted receipt');
$evidence=$as->analyticsEvidence($window);audienceHttpExpect(array_sum(array_column($evidence['links'],'messages'))===3,'Link-level unique messages reconcile with tracked click recipients');
$before=$evidence['links'][0];$rid=$as->one("SELECT e.recipient_id,e.details_json FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id WHERE e.audience_id=? AND e.event_type='click' AND JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot'))='false' ORDER BY e.id LIMIT 1",[$analyticsAid]);
$as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'click',?,'browser',UTC_TIMESTAMP(),?)",[$analyticsAid,$rid['recipient_id'],bin2hex(random_bytes(32)),$rid['details_json']]);
$updatedEvidence=$as->analyticsEvidence($window);audienceHttpExpect(array_sum(array_column($updatedEvidence['links'],'messages'))===3&&array_sum(array_column($updatedEvidence['links'],'observations'))===4,'Repeated link clicks increase observations while unique messages stay fixed');
$custom=$as->analyticsReportRows($window,['dataset'=>'email','dimension'=>'day','columns'=>['accepted','open_rate']]);
audienceHttpExpect(array_sum(array_column($custom,'accepted'))===36,'Custom daily report reconciles with email dashboard');
$segmentPerson=$samples['archived_fictional_contacts'][1];$as->write('UPDATE marketing_contacts SET is_archived=0 WHERE audience_id=? AND id=?',[$analyticsAid,$segmentPerson]);
$as->write('INSERT INTO marketing_segments(audience_id,name,filters_json) VALUES (?,?,?)',[$analyticsAid,'Native report segment',json_encode(['match'=>'all','rules'=>[['field'=>'email','operator'=>'is','value'=>'stage7-sample-2@example.test']]])]);$reportSegment=(int)$conn->insert_id;
$segmentWindow=array_replace($window,['segment'=>$reportSegment]);
audienceHttpExpect($as->analyticsOverview($segmentWindow)['totals']['accepted']===3&&$as->analyticsActivity($segmentWindow)['total']===3,'Saved current-membership segment filters reconcile across dashboards and recipient drilldowns');
$as->write('UPDATE marketing_contacts SET is_archived=1 WHERE audience_id=? AND id=?',[$analyticsAid,$segmentPerson]);
audienceHttpExpect($as->analyticsOverview($segmentWindow)['totals']['accepted']===0,'Segment reports explicitly follow current active connection membership');
$badSegment=false;try{$as->analyticsOverview(array_replace($window,['segment'=>2147483647]));}catch(InvalidArgumentException){$badSegment=true;}audienceHttpExpect($badSegment,'Foreign or unavailable report segments are rejected');
$history=$as->analyticsAudienceHistory($window,'Local sample data');
audienceHttpExpect(array_sum(array_column($history['current'],'added'))>0&&array_sum(array_column($history['previous'],'added'))>0,'Growth fixtures provide real dated current and comparison evidence');
audienceHttpExpect(count($as->analyticsAudienceHistory($window,'foreign source')['current'])===0,'Source filter is applied to both joins and transitions');
$funnels=$as->analyticsNativeFunnels($window);
audienceHttpExpect(count($funnels['forms'])===3&&array_sum(array_column($funnels['forms'],'visitors'))===18&&array_sum(array_column($funnels['forms'],'responses'))===9,'Cross-form summaries count native visitors and responses independently');
audienceHttpExpect(array_sum(array_column($funnels['forms'],'confirmed'))===2,'Confirmed subscriptions require explicit collection conversion evidence');
audienceHttpExpect(count($funnels['flows'])===2&&array_sum(array_column($funnels['flows'],'runs'))===16,'Cross-flow summaries count executions once');
$flowRows=$as->analyticsReportRows($window,['dataset'=>'flows']);audienceHttpExpect(array_sum(array_column(array_column($flowRows,'previous'),'runs'))===12,'Cross-flow comparisons use the previous start-date cohort');
$timing=$as->analyticsTimingEvidence();audienceHttpExpect(!$timing['ready']&&$timing['contacts']<100,'Small native history exposes the selected-time fallback');
$patternRows=$as->analyticsContentPatterns($window,$as->analyticsOverview($window));audienceHttpExpect(array_sum(array_column($patternRows,'accepted'))===36,'Content patterns reconcile with the same accepted cohort');
$mapDelivery=$as->one('SELECT id,snapshot_ciphertext FROM marketing_campaign_deliveries WHERE audience_id=? ORDER BY id LIMIT 1',[$analyticsAid]);$mapSnapshot=audienceJson(Dnr\Security\ApplicationKey::open($mapDelivery['snapshot_ciphertext']));$mapSnapshot['draft']['design']=audienceDesignStarters()['newsletter']['design'];$as->write('UPDATE marketing_campaign_deliveries SET snapshot_ciphertext=? WHERE audience_id=? AND id=?',[Dnr\Security\ApplicationKey::seal(json_encode($mapSnapshot)),$analyticsAid,$mapDelivery['id']]);
$map=$as->analyticsClickMap($window,(int)$mapDelivery['id']);
audienceHttpExpect(str_contains($map['html'],'observed clicks')&&!preg_match('/<a[^>]+href=|<img[ >]|<script[ >]|<form[ >]/i',$map['html']),'Click maps annotate immutable content while disabling links, images and executable content');
audienceHttpExpect(str_contains($map['html'],"default-src 'none'")&&str_contains($map['html'],'nonce-'),'Click map has a restrictive sandbox-compatible content policy');
$denied=false;try{$as->analyticsClickMap($window,2147483647);}catch(InvalidArgumentException){$denied=true;}audienceHttpExpect($denied,'Click map rejects a delivery outside the filtered account cohort');
$otherActor=new AudienceStore($conn,$analyticsAid,77777,'Another authorized user');
$definition=['dataset'=>'email','columns'=>['accepted','opens','click_rate'],'plots'=>['accepted','click_rate'],'name'=>'=Saved native report','start'=>$window['start'],'end'=>$window['end']];
$reportId=$as->analyticsSaveReport($definition);$saved=$as->analyticsSavedReport($reportId);
audienceHttpExpect($saved['version']===1&&!$saved['shared'],'New saved reports start private with versioned definitions');
$denied=false;try{$otherActor->analyticsSavedReport($reportId);}catch(InvalidArgumentException $e){$denied=$e->getCode()===403;}audienceHttpExpect($denied,'Private report definitions cannot be retrieved by another account user');
$denied=false;try{$campaignStore->analyticsSavedReport($reportId,true);}catch(InvalidArgumentException $e){$denied=true;}audienceHttpExpect($denied,'Even an administrator cannot retrieve another audience report');
$stale=false;try{$as->analyticsSaveReport($definition+['report'=>$reportId,'report_version'=>0]);}catch(InvalidArgumentException){$stale=true;}audienceHttpExpect($stale,'Stale report edits are rejected');
$as->analyticsSaveReport($definition+['report'=>$reportId,'report_version'=>1,'shared'=>'1']);
audienceHttpExpect($otherActor->analyticsSavedReport($reportId)['shared'],'Shared definitions can be read by other already authorized account users');
$denied=false;try{$otherActor->analyticsSaveReport($definition+['report'=>$reportId,'report_version'=>2]);}catch(InvalidArgumentException $e){$denied=$e->getCode()===403;}audienceHttpExpect($denied,'Sharing does not grant edit ownership');
$otherActor->analyticsSaveReport(['report'=>$reportId,'report_version'=>2,'report_action'=>'favorite']);
audienceHttpExpect(in_array('77777',$as->analyticsSavedReport($reportId)['favorites'],true),'Favorites are scoped to the actor');
$copy=$otherActor->analyticsSaveReport(['report'=>$reportId,'report_version'=>3,'report_action'=>'copy']);
audienceHttpExpect($copy!==$reportId&&$otherActor->analyticsSavedReport($copy)['owner']===77777,'Report copy creates an independent owned definition');
$as->analyticsSaveReport(['report'=>$reportId,'report_version'=>3,'report_action'=>'delete']);
audienceHttpExpect(!in_array($reportId,array_column($as->analyticsSavedReports(),'key'),true)&&in_array($reportId,array_column($as->analyticsSavedReports(false,true),'key'),true),'Removed reports are hidden and recoverable');
$denied=false;try{$otherActor->analyticsSaveReport(['report'=>$reportId,'report_version'=>4,'report_action'=>'copy']);}catch(InvalidArgumentException $e){$denied=$e->getCode()===403;}audienceHttpExpect($denied,'Removing a shared report revokes direct copy access until its owner restores it');
$as->analyticsSaveReport(['report'=>$reportId,'report_version'=>4,'report_action'=>'restore']);
audienceHttpExpect($as->analyticsSavedReport($reportId)['version']===5,'Report restore preserves its original identity');
foreach(['reports','custom','activity','links','devices','insights','audience','conversions']as$section){$response=$editor('audience.php?view=analytics&section='.$section);audienceHttpExpect($response['status']===200&&!preg_match('/(?:Warning|Fatal error|Deprecated):/',$response['body'])&&str_ends_with(trim($response['body']),'</html>'),'Extended report route renders a complete shell: '.$section);}
foreach(['recipients','links','custom','audience','forms','flows']as$export){$section=$export==='custom'?'custom':($export==='links'?'links':'email');$response=$editor('audience.php?view=analytics&section='.$section.'&download='.$export);audienceHttpExpect($response['status']===200&&str_contains($response['headers'],'text/csv'),'Detailed CSV export is available: '.$export);audienceHttpExpect($reviewer('audience.php?view=analytics&section='.$section.'&download='.$export)['status']===403,'Detailed CSV enforces export permissions: '.$export);}
$analyticsToken=audienceHttpCsrf($editor('audience.php?view=analytics&section=custom'));$analyticsReviewToken=audienceHttpCsrf($reviewer('audience.php?view=analytics'));
$httpCreate=$editor('audience.php?view=analytics&section=custom',['csrf_token'=>$analyticsToken,'action'=>'analytics_report','name'=>'HTTP private report','dataset'=>'email','columns'=>['accepted','click_rate']]);
audienceHttpExpect($httpCreate['status']===303,'Report create uses a CSRF-protected POST and redirect');preg_match('/report=([a-f0-9]+)/',$httpCreate['headers'],$reportMatch);$httpId=$reportMatch[1]??'';
audienceHttpExpect($httpId!==''&&$reviewer('audience.php?view=analytics&section=custom&report='.$httpId)['status']===403,'Private report direct URL is denied to another user');
audienceHttpExpect($admin('audience.php?view=analytics&section=custom&report='.$httpId)['status']===200,'Administrator can inspect a native account report');
audienceHttpExpect($editor('audience.php?view=analytics&section=custom',['csrf_token'=>'bad','action'=>'analytics_report','name'=>'bad'])['status']===400,'Report save rejects invalid CSRF');
audienceHttpExpect($reviewer('audience.php?view=analytics&section=custom',['csrf_token'=>$analyticsReviewToken,'action'=>'analytics_report','name'=>'bad'])['status']===403,'Reviewer cannot create or mutate reports');
audienceHttpExpect($editor('audience.php?view=analytics&section=custom&columns[]=password')['status']===400,'Direct custom metric injection is rejected');
audienceHttpExpect($editor('audience.php?view=analytics&section=activity&activity=password')['status']===400,'Direct recipient activity injection is rejected');

$baseline=$as->analyticsTimingEvidence()['contacts'];$timingDelivery=(int)$mapDelivery['id'];$lastTimingRecipient=0;
for($i=0;$i<100-$baseline;$i++){
    $email='timing-'.$i.'@example.test';$as->write("INSERT INTO marketing_contacts(audience_id,email,email_status,is_archived,source,notes) VALUES (?,?,'non-subscribed',1,'Disposable timing fixture','Fictional; never sent')",[$analyticsAid,$email]);$contact=(int)$conn->insert_id;
    $as->write("INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at,accepted_at,phase) VALUES (?,?,?,?,?,?,?,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'main')",[$analyticsAid,$timingDelivery,$contact,audienceDispatchEmailHash($email),Dnr\Security\ApplicationKey::seal(json_encode(['email'=>$email])),bin2hex(random_bytes(32)),'timing-'.$i.'@example.test']);$lastTimingRecipient=(int)$conn->insert_id;
    $as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'click',?,'browser',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),JSON_OBJECT('bot',?))",[$analyticsAid,$lastTimingRecipient,bin2hex(random_bytes(32)),$i===99-$baseline?'true':'false']);
}
audienceHttpExpect($as->analyticsTimingEvidence()['contacts']===99&&!$as->analyticsTimingEvidence()['ready'],'Detected bot clicks cannot satisfy the 100-recipient scheduling threshold');
$as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'click',?,'browser',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),JSON_OBJECT('bot',false))",[$analyticsAid,$lastTimingRecipient,bin2hex(random_bytes(32))]);
audienceHttpExpect($as->analyticsTimingEvidence()['ready']&&$as->analyticsTimingEvidence()['contacts']===100,'Scheduling and analytics share the exact 100 distinct recipient threshold');
