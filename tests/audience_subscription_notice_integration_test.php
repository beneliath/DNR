<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Subscription notice tests require a disposable environment.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_store.php';
require_once $source.'/audience_delivery_lifecycle.php';
require_once $source.'/audience_inbox_runtime.php';
$db=applicationDatabaseConnection();$checks=0;$suffix=bin2hex(random_bytes(5));
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$store=new AudienceStore($db,1,0,'Subscription notice qualification');
$workerFixture=getenv('DNR_SUBSCRIPTION_NOTICE_WORKER_FIXTURE')==='1';$priorSes=$store->sesSettings();
$priorQueue=$store->all("SELECT id,next_attempt_at FROM marketing_service_mail WHERE state='queued'");
foreach($priorQueue as$queued)$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY) WHERE id=?',[$queued['id']]);
try{
$db->query("UPDATE marketing_ses_settings SET enabled=1,from_email='sender@example.test',access_key_tail='TEST',check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=1000,max_rate=10,sent_last_day=0,quota_checked_at=UTC_TIMESTAMP(),aws_account_id='111122223333',region='us-east-2',subscription_arn='arn:aws:sns:us-east-2:111122223333:fixture:confirmed' WHERE audience_id=1");
$makeForm=static function()use($store,$suffix):array{
    $id=$store->saveCollection(['kind'=>'hosted','name'=>'Notice '.$suffix.' '.bin2hex(random_bytes(2))]);
    $document=$store->collectionResource($id)['draft'];$document['fields']=$document['required']=['email'];
    $document['opt_in']='double';$document['tag_ids']=$document['group_ids']=[];
    $store->saveCollection(['name'=>'Notice '.$suffix,'document'=>$document],$id,1);$store->collectionOperation($id,2,'publish');
    return $store->collectionPublished($store->collectionResource($id)['public_key']);
};
$form=$makeForm();
$payload=static function(string $email,?array $resource=null)use($store,$form):array{
    $resource??=$form;$proof=$store->collectionChallenge($resource,bin2hex(random_bytes(32)));
    $data=json_decode(\Dnr\Security\ApplicationKey::open($proof),true,12,JSON_THROW_ON_ERROR);$data['issued']-=5;
    return ['email'=>$email,'permission'=>'1','proof'=>\Dnr\Security\ApplicationKey::seal(json_encode($data,JSON_THROW_ON_ERROR))];
};
$submit=static fn(array $input,?array $resource=null):array=>$store->submitCollection($resource??$form,$input,'notice-'.$suffix,'https://newsletter.example.test');
$makePerson=static function(string $label)use($store,$suffix):int{
    return $store->savePerson(['email'=>'notice-'.$label.'-'.$suffix.'@example.test','first_name'=>'Original','email_status'=>'subscribed','consent_note'=>'Synthetic confirmed signup']);
};
$mails=static fn(int $id):array=>$store->all('SELECT * FROM marketing_service_mail WHERE audience_id=1 AND contact_id=? ORDER BY id',[$id]);
$id=$makePerson('active');$before=$store->person($id);$input=$payload($before['email']);$result=$submit($input);
$jobs=$mails($id);$expect(count($jobs)===1&&$jobs[0]['kind']==='subscription-notice','Repeat signup queues one private status notice');
$message=json_decode(\Dnr\Security\ApplicationKey::open($jobs[0]['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR)['message'];
$expect($message['email']===$before['email']&&$message['subject']==="You're already subscribed"&&str_contains($message['text'],'No further action is needed.')&&!str_contains($message['text'],'confirm='),'Notice is addressed to the subscriber and contains no consent token');
$expect($store->person($id)===$before,'Repeat signup does not change the profile, status or version');
$expect((int)$store->one('SELECT COUNT(*) n FROM marketing_consent_challenges WHERE contact_id=?',[$id])['n']===0,'Notice creates no consent challenge');
$expect((int)$store->collectionConversions((int)$form['id'])['subscribers']===0,'Repeat signup does not count a new subscription');
$submit($input);$submit($payload($before['email']));$otherForm=$makeForm();$submit($payload($before['email'],$otherForm),$otherForm);
$expect(count($mails($id))===1,'Receipt replays, new proofs and different forms cannot resend within 24 hours');
$delivered=[];processAudienceServiceMail($db,20,static function(array $message,string $kind)use(&$delivered):string{$delivered[]=[$message,$kind];return 'accepted';});
$expect(count($delivered)===1&&$delivered[0][1]==='subscription-notice','Existing worker sends the new notice through the injected mail transport');
$expect($mails($id)[0]['state']==='accepted'&&$mails($id)[0]['payload_ciphertext']==='','Accepted notice retains its receipt and erases private payload');
$db->execute_query('UPDATE marketing_service_mail SET ses_correlation=?,provider_account_id=?,provider_region=?,recipient_hash=?,provider_message_id=? WHERE id=?',[bin2hex(random_bytes(32)),'111122223333','us-east-2',audienceDispatchEmailHash($before['email']),'notice-receipt-fixture',$jobs[0]['id']]);
$receipt=$mails($id)[0];
$db->execute_query('UPDATE marketing_service_mail SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR) WHERE id=?',[$jobs[0]['id']]);
$submit($payload($before['email']));$expect(count($mails($id))===2,'A later signup may request another notice after 24 hours');
$db->execute_query("UPDATE marketing_service_mail SET state='cancelled',payload_ciphertext='' WHERE contact_id=? AND state='queued'",[$id]);
$providerEvent=static function(string $type,?string $destination=null,?array $envelope=null)use($store,$receipt,$before):string{
    return audienceServiceMailProviderEvent($store,$store->sesSettings(),$envelope??['MessageId'=>bin2hex(random_bytes(16))],['destination'=>[$destination??$before['email']]],$receipt['ses_correlation'],$receipt['provider_message_id'],$type,gmdate('Y-m-d H:i:s'));
};
$expect($providerEvent('delivered','different@example.test')==='ignored','A mismatched provider receipt cannot claim notice delivery');
$envelope=['MessageId'=>bin2hex(random_bytes(16))];
$expect($providerEvent('delivered',null,$envelope)==='accepted'&&$providerEvent('delivered',null,$envelope)==='duplicate','Matching notice receipts are recorded once');
$expect($providerEvent('hard-bounce')==='accepted'&&$store->person($id)['email_status']==='cleaned','A hard-bounced subscribed notice cleans the matching address');
$expect($providerEvent('complaint')==='accepted'&&$store->person($id)['email_status']==='cleaned'&&(bool)$store->one('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=1 AND email_hash=?',[audienceDispatchEmailHash($before['email'])]),'A notice complaint retains cleaning and suppresses further mail');

$unknown='notice-new-'.$suffix.'@example.test';$newResult=$submit($payload($unknown));
$new=$store->one('SELECT * FROM marketing_contacts WHERE audience_id=1 AND email=?',[$unknown]);
$expect($new['email_status']==='pending'&&array_column($mails((int)$new['id']),'kind')===['confirmation'],'New address still requires double confirmation');
$expect($result===$newResult,'Public result does not disclose whether the email was already subscribed');
$id=$makePerson('unsubscribed');$store->mutatePerson($id,1,'unsubscribe');$submit($payload($store->person($id)['email']));
$expect($store->person($id)['email_status']==='unsubscribed'&&array_column($mails($id),'kind')===['confirmation'],'Unsubscribed address needs fresh confirmation and receives no subscribed notice');
foreach(['cleaned','archived','deleted','optout','deleted-hash']as$state){
    $id=$makePerson('blocked-'.$state);$email=$store->person($id)['email'];
    if($state==='cleaned')$db->execute_query("UPDATE marketing_contacts SET email_status='cleaned' WHERE id=?",[$id]);
    elseif($state==='archived')$store->mutatePerson($id,1,'archive');
    elseif($state==='deleted')$store->mutatePerson($id,1,'delete');
    else $db->execute_query('INSERT INTO '.($state==='optout'?'marketing_email_optouts':'marketing_deleted_emails').'(audience_id,email_hash) VALUES (1,?)',[audienceDispatchEmailHash($email)]);
    $expect($submit($payload($email))===$newResult&&$mails($id)===[],'Blocked '.$state.' address gets the same public result with no notice');
}
$db->execute_query("UPDATE marketing_service_mail SET state='cancelled',payload_ciphertext='' WHERE state='queued' AND contact_id IN (SELECT id FROM marketing_contacts WHERE audience_id=1 AND email LIKE ?)",['notice-%-'.$suffix.'@example.test']);
foreach(['unsubscribed','cleaned','archived','deleted','email-changed','optout','deleted-hash']as$state){
    $id=$makePerson('queued-'.$state);$email=$store->person($id)['email'];$submit($payload($email));
    $expect(array_column($mails($id),'kind')===['subscription-notice'],'Eligible '.$state.' fixture queues a notice before state changes');
    if($state==='unsubscribed')$store->mutatePerson($id,1,'unsubscribe');
    elseif($state==='cleaned')$db->execute_query("UPDATE marketing_contacts SET email_status='cleaned' WHERE id=?",[$id]);
    elseif($state==='archived')$store->mutatePerson($id,1,'archive');
    elseif($state==='deleted')$store->mutatePerson($id,1,'delete');
    elseif($state==='email-changed')$db->execute_query('UPDATE marketing_contacts SET email=? WHERE id=?',['changed-'.$email,$id]);
    else $db->execute_query('INSERT INTO '.($state==='optout'?'marketing_email_optouts':'marketing_deleted_emails').'(audience_id,email_hash) VALUES (1,?)',[audienceDispatchEmailHash($email)]);
    processAudienceServiceMail($db,20,static function()use(&$delivered):string{$delivered[]='UNEXPECTED';return 'accepted';});
    $job=$mails($id)[0];$expect(count($delivered)===1&&$job['state']==='failed'&&$job['payload_ciphertext']==='','Worker suppresses and erases a notice after '.$state);
}
if($workerFixture){
    $db->execute_query('UPDATE marketing_ses_credentials SET credential_ciphertext=? WHERE audience_id=1',[\Dnr\Security\ApplicationKey::seal(json_encode(['account'=>currentAccountKey(),'credentials'=>['key'=>'AKIA'.str_repeat('A',16),'secret'=>str_repeat('a',40)]],JSON_THROW_ON_ERROR))]);
    $id=$makePerson('sdk-delivery');$submit($payload($store->person($id)['email']));
}
echo "Subscription notices: $checks checks passed; no real email sent.\n";
}finally{
    foreach($priorQueue as$queued)$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$queued['next_attempt_at'],$queued['id']]);
    if(!$workerFixture){
        $keys=['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','sent_last_day','quota_checked_at','aws_account_id','region','subscription_arn','last_event_at'];
        $db->execute_query('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($key)=>$key.'=?',$keys)).' WHERE audience_id=1',array_map(static fn($key)=>$priorSes[$key],$keys));
    }
}
