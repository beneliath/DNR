<?php
require_once $source.'/audience_ai.php';
// All writes are confined to the disposable analytics fixture audience.
$cid=$samples['campaigns'][0];$person=$samples['people'][9]??null;
if(!$person)$person=(int)$as->one("SELECT id FROM marketing_contacts WHERE audience_id=? AND email='stage7-sample-10@example.test'",[$analyticsAid])['id'];
$recipient=$as->one('SELECT r.id FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id WHERE r.audience_id=? AND r.contact_id=? AND d.campaign_id=?',[$analyticsAid,$person,$cid]);
$rid=(int)$recipient['id'];$link=hash('sha256','https://example.test/engagement');
$selection=array_replace($window,['messages_mode'=>'selected','messages'=>[$cid]]);
$before=$as->analyticsOverview($selection)['totals'];
foreach([1,2]as$repeat)$as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'click',?,'browser',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),?)",[$analyticsAid,$rid,bin2hex(random_bytes(32)),json_encode(['bot'=>false,'link_hash'=>$link,'link_label'=>'example.test/engagement'])]);
$after=$as->analyticsOverview($selection)['totals'];
audienceHttpExpect($after['opens']===$before['opens']+1&&$after['clicks']===$before['clicks']+1&&$after['total_clicks']===$before['total_clicks']+2,'Click-only activity implies one unique open; repeat clicks remain separate totals');
audienceHttpExpect($after['successful']===$after['accepted']-$after['failed']&&$after['failed']===1&&$after['click_rate']===audienceAnalyticsRate($after['clicks'],$after['successful']),'Bounced messages are excluded from engagement denominator');
$listed=$as->analyticsCampaignMetrics([$cid])[$cid];
audienceHttpExpect($listed['open_rate']===$after['open_rate']&&$listed['click_rate']===$after['click_rate'],'Campaign list metrics reconcile with the same campaign report');
$insights=$as->personInsights($person);
$personal=array_values(array_filter($insights['campaigns'],static fn($c)=>(int)$c['id']===$cid))[0]['metrics'];
audienceHttpExpect($personal['opens']===1&&$personal['clicks']===1,'Profile Insights contains inferred opens and unique clicks');
$activity=$as->analyticsActivity($selection,'opens',1,500);
audienceHttpExpect(in_array($rid,array_map('intval',array_column($activity['rows'],'id')),true),'Opened recipient drilldown includes click-only observations');
$matches=static function(array $rule,?int $personId=null)use($as,$person):bool{
    [$where,$params]=$as->where(audienceListFilters(['archived'=>'all']),['match'=>'all','rules'=>[$rule]]);
    return (int)$as->one("SELECT COUNT(*) n FROM marketing_contacts c WHERE $where AND c.id=?",[...$params,$personId??$person])['n']===1;
};
$clickRule=['field'=>'engagement:click','operator'=>'last_days','value'=>'7','campaign_id'=>(string)$cid,'link_hash'=>$link];
audienceHttpExpect($matches($clickRule),'Campaign and tracked-link segment matches recent click');
audienceHttpExpect(!$matches(array_replace($clickRule,['operator'=>'not_last_days'])),'Negative engagement predicate excludes the recent clicker');
audienceHttpExpect($matches(['field'=>'engagement:open','operator'=>'ever','value'=>'','campaign_id'=>(string)$cid]),'Open segment uses click inference consistently');
$botRule=['field'=>'engagement:click','operator'=>'ever','value'=>'','campaign_id'=>(string)$cid];
$botPerson=$samples['archived_fictional_contacts'][0];
audienceHttpExpect(!$matches($botRule,$botPerson)&&$matches($botRule+['bots'=>'all'],$botPerson),'Engagement segments exclude known bots by default and support explicit unfiltered observations');
$foreign=false;try{$matches(array_replace($clickRule,['campaign_id'=>(string)$samples['campaigns'][0].'999']));}catch(InvalidArgumentException){$foreign=true;}
audienceHttpExpect($foreign,'A campaign outside the scoped catalog is rejected');
$timeline=$as->personActivity($person,'email_click',0,0,1,false);
audienceHttpExpect(count($timeline['rows'])>=2&&!array_filter($timeline['rows'],static fn($e)=>!str_starts_with($e['event_type'],'email_')),'Profile activity supports email filters');
audienceHttpExpect(!array_filter($timeline['rows'],static fn($e)=>!empty(audienceJson($e['details_json'])['bot'])),'Human timeline observations are not mislabeled as known bots');
$pending=$as->analyticsOverview($selection)['totals'];
audienceHttpExpect($pending['awaiting_receipt']>0&&$pending['awaiting_receipt']===$pending['successful']-$pending['delivered'],'Missing delivery receipts remain provisional rather than counted as failure');
foreach(['rejected','hard-bounce']as$type)$as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,?,?,'provider',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),JSON_OBJECT())",[$analyticsAid,$rid,$type,bin2hex(random_bytes(32))]);
$failed=$as->analyticsOverview($selection)['totals'];
audienceHttpExpect($failed['successful']===$after['successful']-1&&$failed['clicks']===$before['clicks'],'A message with multiple failure events leaves the denominator once and does not inflate engagement');
audienceHttpExpect(!$matches($clickRule),'Engagement segment excludes failed messages consistently with reports');

$foreignProfile=false;try{$campaignStore->personInsights($person);}catch(InvalidArgumentException){$foreignProfile=true;}
audienceHttpExpect($foreignProfile,'Contact Insights rejects another audience contact');
$evidence=$as->analyticsEvidence($selection);
audienceHttpExpect(!in_array($link,array_column($evidence['links'],'link_hash'),true),'Link evidence excludes the same failed recipient as headline metrics');
foreach(['email','audience','forms','flows']as$dataset){
    $aiWindow=audienceAiReportWindow(['dataset'=>$dataset,'start'=>$window['start'],'end'=>$window['end']]);
    $aiEvidence=audienceAiReportEvidence($as,$aiWindow);
    audienceHttpExpect($aiEvidence['window']['dataset']===$dataset&&count($aiEvidence['facts'])<=21&&!str_contains(json_encode($aiEvidence),'@example.test'),'AI selected dataset uses bounded aggregate facts without recipient identities');
    if($dataset!=='email')audienceHttpExpect(!in_array('open_rate',array_column($aiEvidence['facts'],'id'),true)&&$aiEvidence['series']===[],'Non-email AI does not present email metrics');
}

$activeReports=array_values(array_filter($as->analyticsSavedReports(true),static fn($r)=>empty($r['deleted'])));
for($i=count($activeReports);$i<50;$i++)$as->analyticsSaveReport(['name'=>'Capacity '.$i]);
$capacityKey=$as->analyticsSavedReports(true)[0]['key'];$capacityVersion=$as->analyticsSavedReports(true)[0]['version'];
$c2reject(fn()=>$as->analyticsSaveReport(['name'=>'Over capacity']),'Active report capacity is enforced');
$as->analyticsSaveReport(['report'=>$capacityKey,'report_version'=>$capacityVersion,'report_action'=>'delete'],true);
$as->analyticsSaveReport(['name'=>'Freed capacity']);
$c2reject(fn()=>$as->analyticsSaveReport(['report'=>$capacityKey,'report_version'=>$capacityVersion+1,'report_action'=>'restore'],true),'Restore cannot exceed active report capacity');
audienceHttpExpect(count(array_filter($as->analyticsSavedReports(true),static fn($r)=>empty($r['deleted'])))===50,'Removing a saved report frees its active slot');
$oldChoice=$campaignStore->createCampaign(['name'=>'Old selectable campaign fixture','campaign_type'=>'regular']);
$campaignStore->write("UPDATE marketing_campaigns SET status='sent',updated_at='2020-01-01' WHERE audience_id=? AND id=?",[$campaignStore->audienceId,$oldChoice]);
for($i=0;$i<101;$i++){
    $choice=$campaignStore->createCampaign(['name'=>'Recent selection fixture '.$i,'campaign_type'=>'regular']);
    $campaignStore->write("UPDATE marketing_campaigns SET status='sent' WHERE audience_id=? AND id=?",[$campaignStore->audienceId,$choice]);
}
$oldChoicePage=$editor('audience.php?view=analytics&messages_mode=selected&messages[]='.$oldChoice);
audienceHttpExpect($oldChoicePage['status']===200&&str_contains($oldChoicePage['body'],'Old selectable campaign fixture'),'A campaign older than the latest hundred remains selectable');

$mapSeed=$as->one('SELECT * FROM marketing_campaign_deliveries WHERE audience_id=? ORDER BY id LIMIT 1',[$analyticsAid]);
$recipientSeed=$as->one('SELECT * FROM marketing_campaign_recipients WHERE audience_id=? AND delivery_id=? LIMIT 1',[$analyticsAid,$mapSeed['id']]);
for($i=0;$i<101;$i++){
    $as->write("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label) VALUES (?,?,?,'completed',UTC_TIMESTAMP(),'UTC',?,?,1,?,'Old-map qualification')",[$analyticsAid,$mapSeed['campaign_id'],$i+2,$mapSeed['snapshot_ciphertext'],bin2hex(random_bytes(32)),$as->actorId]);$newDelivery=(int)$conn->insert_id;
    $as->write("INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at,accepted_at,phase) VALUES (?,?,?,?,?,?,?,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'main')",[$analyticsAid,$newDelivery,$recipientSeed['contact_id'],$recipientSeed['email_hash'],$recipientSeed['context_ciphertext'],bin2hex(random_bytes(32)),'<map-'.bin2hex(random_bytes(16)).'@example.test>']);
}
$allMap=$as->analyticsClickMap($window,(int)$mapSeed['id']);
audienceHttpExpect(count($allMap['options'])>100&&$allMap['delivery']===(int)$mapSeed['id']&&!isset($allMap['options'][0]['snapshot_ciphertext']),'Click map selects an older delivery without loading every encrypted message snapshot');
$longWindow=audienceAnalyticsWindow(['start'=>'2020-01-01','end'=>$window['end']]);$longEvidence=audienceAiReportEvidence($as,audienceAiReportWindow(['start'=>'2020-01-01','end'=>$window['end']]));
audienceHttpExpect(count($longEvidence['series'])<=120&&array_sum(array_column($longEvidence['series'],'accepted'))===$as->analyticsOverview($longWindow)['totals']['accepted'],'Long-range AI series stays bounded and preserves totals');
