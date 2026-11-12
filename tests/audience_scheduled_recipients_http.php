<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");

$scheduledTime=['mode'=>'schedule','delivery'=>'specific','timezone'=>'UTC','local'=>gmdate('Y-m-d H:i',time()+86400),'utc'=>gmdate('Y-m-d H:i',time()+86400).':00'];
$allowTag=$campaignStore->ensureTag('Scheduled members');
$excludeTag=$campaignStore->ensureTag('Scheduled exclusions');
$rules=['match'=>'all','rules'=>[['field'=>'tags','operator'=>'any','value'=>[$allowTag]]]];
$conn->execute_query("INSERT INTO marketing_segments(audience_id,name,filters_json) VALUES (1,'Scheduled segment',?)",[json_encode($rules)]);
$segmentId=(int)$conn->insert_id;
$makeScheduled=static fn()=>$makeRuntime([
    'send_time'=>$scheduledTime,'include'=>['segment:'.$segmentId],'exclude'=>['tag:'.$excludeTag],
    'recipient_rules'=>['match'=>'all','rules'=>[['field'=>'last_name','operator'=>'is','value'=>'Member']]]
]);
$signup=static function(string $name,bool $included=true,string $last='Member')use($campaignStore,$allowTag):int{
    $person=$campaignStore->savePerson(['email'=>$name.'@example.test','first_name'=>$name,'last_name'=>$last,'email_status'=>'subscribed','consent_note'=>'Requested the fictional newsletter']);
    if($included)$campaignStore->write('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$person,$allowTag]);
    return $person;
};
$dueNow=static fn(int $delivery)=>$campaignStore->write('UPDATE marketing_campaign_deliveries SET due_at=UTC_TIMESTAMP() WHERE id=?',[$delivery]);
$sent=[];
$transport=static function(array $message)use(&$sent):string{$sent[]=$message['email'];return 'accepted';};

// An empty preview can be scheduled, and an empty audience at send time finishes without sending.
$emptyCampaign=$makeScheduled();
$emptyJob=$confirmRuntime($emptyCampaign);
$dueNow($emptyJob);
processAudienceCampaignDelivery($conn,5,$transport);
audienceHttpExpect(!$sent&&$campaignStore->campaignDelivery($emptyCampaign)['state']==='completed'&&!$campaignStore->campaignDeliveryCounts($emptyJob),'An empty scheduled audience completes exactly once');

$campaignId=$makeScheduled();$row=$campaignStore->campaign($campaignId);
$review=$campaignStore->reviewCampaignDispatch($campaignId,$dispatchAudience,$dispatchBase);
$signup('joined-during-review');
$left=$signup('left-before-delivery');$signup('opted-out-before-delivery');
$excluded=$signup('excluded-before-delivery');$changed=$signup('changed-before-delivery');
$jobId=$campaignStore->confirmCampaignDispatch($campaignId,(int)$row['version'],$review['proof'],$dispatchAudience,$dispatchBase);
$signup('joined-after-scheduling');
$signup('never-in-segment',false);$signup('wrong-filter',true,'Visitor');
$conn->execute_query('DELETE FROM marketing_contact_tags WHERE contact_id=? AND tag_id=?',[$left,$allowTag]);
$conn->execute_query('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$excluded,$excludeTag]);
$conn->execute_query("UPDATE marketing_contacts SET last_name='Visitor' WHERE id=?",[$changed]);
$conn->execute_query('INSERT INTO marketing_email_optouts(audience_id,email_hash) VALUES (1,?)',[audienceDispatchEmailHash('opted-out-before-delivery@example.test')]);
$removedAfterStart=$signup('opts-out-during-delivery');
$dueNow($jobId);processAudienceCampaignDelivery($conn,1,$transport);
$signup('joined-after-first-send');
$conn->execute_query("UPDATE marketing_contacts SET email_status='unsubscribed' WHERE id=?",[$removedAfterStart]);
processAudienceCampaignDelivery($conn,10,$transport);
$counts=$campaignStore->campaignDeliveryCounts($jobId);
audienceHttpExpect($sent===['joined-during-review@example.test','joined-after-scheduling@example.test']&&(int)$counts['suppressed']===1&&(int)$campaignStore->campaignDelivery($campaignId)['recipient_count']===3,'Send time applies the segment, exclusions, saved filters and opt-outs, then fixes the list for every worker pass');
audienceHttpExpect(processAudienceCampaignDelivery($conn,10,$transport)===0,'A completed scheduled delivery cannot replay');

// Model an untouched pre-upgrade delivery with real queued receipts from the old confirmation path.
$legacy=$makeRuntime(['include'=>['segment:'.$segmentId],'exclude'=>['tag:'.$excludeTag]]);
$legacyJob=$confirmRuntime($legacy);$old=$campaignStore->campaignDelivery($legacy);
$snapshot=json_decode(\Dnr\Security\ApplicationKey::open($old['snapshot_ciphertext']),true);
$snapshot['draft']['send_time']=$scheduledTime;
$conn->execute_query("UPDATE marketing_campaign_deliveries SET state='scheduled',snapshot_ciphertext=? WHERE id=?",[\Dnr\Security\ApplicationKey::seal(json_encode($snapshot)),$legacyJob]);
$conn->execute_query("UPDATE marketing_campaigns SET status='scheduled' WHERE id=?",[$legacy]);
$late=$signup('legacy-late-subscriber');
$conn->execute_query('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$late,$excludeTag]);
$conn->execute_query('DELETE FROM marketing_contact_tags WHERE contact_id=? AND tag_id=?',[$changed,$allowTag]);
$signup('legacy-late-match');
$sent=[];processAudienceCampaignDelivery($conn,1,$transport);
$started=$campaignStore->campaignDelivery($legacy);
$snapshot=json_decode(\Dnr\Security\ApplicationKey::open($started['snapshot_ciphertext']),true);
unset($snapshot['recipients_resolved']); // Older workers left no marker on in-progress campaigns.
$conn->execute_query('UPDATE marketing_campaign_deliveries SET snapshot_ciphertext=? WHERE id=?',[\Dnr\Security\ApplicationKey::seal(json_encode($snapshot)),$legacyJob]);
$signup('legacy-after-start');processAudienceCampaignDelivery($conn,20,$transport);
audienceHttpExpect(in_array('legacy-late-match@example.test',$sent,true)&&!in_array('changed-before-delivery@example.test',$sent,true)&&!in_array('legacy-late-subscriber@example.test',$sent,true)&&count($sent)===count(array_unique($sent)),'An untouched legacy schedule refreshes its rows, adds new matches and skips obsolete recipients');
audienceHttpExpect(!in_array('legacy-after-start@example.test',$sent,true),'A legacy delivery already in progress keeps its original audience');
