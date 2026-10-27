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
// Older releases may leave status-notice jobs in the outbox during an upgrade.
// Public forms now queue confirmations; that behavior is covered by the existing-signup suite.
$makePerson=static function(string $label)use($store,$suffix):int{
    return $store->savePerson(['email'=>'notice-'.$label.'-'.$suffix.'@example.test','first_name'=>'Original','email_status'=>'subscribed','consent_note'=>'Synthetic confirmed signup']);
};
$queueLegacy=static function(int $id)use($store):void{
    $person=$store->person($id);$ses=$store->sesSettings();$operation=bin2hex(random_bytes(32));
    $message=['email'=>$person['email'],'subject'=>"You're already subscribed",'text'=>"You're already subscribed. No further action is needed.",'html'=>null,
        'from_name'=>'MOED MessageMonkey','from_email'=>$ses['from_email'],'ses_binding'=>audienceSesBinding($ses),'to_name'=>'',
        'message_id'=>'<subscription-notice-'.substr($operation,0,40).'@message-monkey.invalid>'];
    $store->write("INSERT INTO marketing_service_mail(audience_id,contact_id,kind,actor_label,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (1,?,'subscription-notice','Legacy fixture',?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))",[$id,$operation,\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>$message],JSON_THROW_ON_ERROR)),$message['message_id']]);
};
$mails=static fn(int $id):array=>$store->all('SELECT * FROM marketing_service_mail WHERE audience_id=1 AND contact_id=? ORDER BY id',[$id]);
$id=$makePerson('active');$before=$store->person($id);$queueLegacy($id);$jobs=$mails($id);
$expect(count($jobs)===1&&$jobs[0]['kind']==='subscription-notice','Legacy outbox fixture retains the old notice kind');
$expect($store->person($id)===$before&&(int)$store->one('SELECT COUNT(*) n FROM marketing_consent_challenges WHERE contact_id=?',[$id])['n']===0,'Delivering an old status notice must not create consent or change a profile');
$delivered=[];processAudienceServiceMail($db,20,static function(array $message,string $kind)use(&$delivered):string{$delivered[]=[$message,$kind];return 'accepted';});
$expect(count($delivered)===1&&$delivered[0][1]==='subscription-notice','Existing worker can finish an old queued notice through the injected mail transport');
$expect($mails($id)[0]['state']==='accepted'&&$mails($id)[0]['payload_ciphertext']==='','Accepted notice retains its receipt and erases private payload');
$db->execute_query('UPDATE marketing_service_mail SET ses_correlation=?,provider_account_id=?,provider_region=?,recipient_hash=?,provider_message_id=? WHERE id=?',[bin2hex(random_bytes(32)),'111122223333','us-east-2',audienceDispatchEmailHash($before['email']),'notice-receipt-fixture',$jobs[0]['id']]);
$receipt=$mails($id)[0];
$providerEvent=static function(string $type,?string $destination=null,?array $envelope=null)use($store,$receipt,$before):string{
    return audienceServiceMailProviderEvent($store,$store->sesSettings(),$envelope??['MessageId'=>bin2hex(random_bytes(16))],['destination'=>[$destination??$before['email']]],$receipt['ses_correlation'],$receipt['provider_message_id'],$type,gmdate('Y-m-d H:i:s'));
};
$expect($providerEvent('delivered','different@example.test')==='ignored','A mismatched provider receipt cannot claim notice delivery');
$envelope=['MessageId'=>bin2hex(random_bytes(16))];
$expect($providerEvent('delivered',null,$envelope)==='accepted'&&$providerEvent('delivered',null,$envelope)==='duplicate','Matching notice receipts are recorded once');
$expect($providerEvent('hard-bounce')==='accepted'&&$store->person($id)['email_status']==='cleaned','A hard-bounced subscribed notice cleans the matching address');
$expect($providerEvent('complaint')==='accepted'&&$store->person($id)['email_status']==='cleaned'&&(bool)$store->one('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=1 AND email_hash=?',[audienceDispatchEmailHash($before['email'])]),'A notice complaint retains cleaning and suppresses further mail');

foreach(['unsubscribed','cleaned','archived','deleted','email-changed','optout','deleted-hash']as$state){
    $id=$makePerson('queued-'.$state);$email=$store->person($id)['email'];$queueLegacy($id);
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
    $id=$makePerson('sdk-delivery');$queueLegacy($id);
}
echo "Legacy subscription notices: $checks checks passed; no real email sent.\n";
}finally{
    foreach($priorQueue as$queued)$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$queued['next_attempt_at'],$queued['id']]);
    if(!$workerFixture){
        $keys=['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','sent_last_day','quota_checked_at','aws_account_id','region','subscription_arn','last_event_at'];
        $db->execute_query('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($key)=>$key.'=?',$keys)).' WHERE audience_id=1',array_map(static fn($key)=>$priorSes[$key],$keys));
    }
}
