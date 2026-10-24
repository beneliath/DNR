<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once '/var/www/html/bootstrap.php';require_once '/var/www/html/audience_delivery_worker.php';require_once '/var/www/html/audience_dispatch_worker.php';
$db=applicationDatabaseConnection();$user=$db->query('SELECT CURRENT_USER() u')->fetch_assoc()['u'];if(!str_starts_with($user,'dnrmaildispatch@'))throw new RuntimeException('Dedicated mail role required.');
foreach(['marketing_sending_domains','marketing_sending_addresses','marketing_delivery_setup_jobs']as$table)$db->query('SELECT id FROM '.$table.' LIMIT 0');
$ses=$db->query('SELECT * FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc();$sealed=$db->query('SELECT credential_ciphertext FROM marketing_ses_credentials WHERE audience_id=1')->fetch_assoc()['credential_ciphertext'];
if(str_contains($sealed,str_repeat('s',40))||audienceSesCredentials($db,1)['secret']!==str_repeat('s',40))throw new RuntimeException('Worker credential isolation failed.');
try{$db->query("UPDATE marketing_ses_credentials SET credential_ciphertext='' WHERE audience_id=1");throw new RuntimeException('Worker can replace credentials.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
require_once '/var/www/html/audience_store.php';require_once '/var/www/html/audience_ses_inbox.php';
$queued=$db->query("SELECT * FROM marketing_ses_inbox WHERE state='queued' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
if(count($queued)!==5)throw new RuntimeException('Signed SNS queue fixtures missing.');
$fixture=json_decode(\Dnr\Security\ApplicationKey::open($queued[0]['payload_ciphertext']),true);
$certificate=$fixture['envelope']['FixtureCertificate'];$failOnce=true;$confirmations=0;
$fetchCertificate=static function(string $url)use($certificate,&$failOnce):string{if($failOnce){$failOnce=false;throw new RuntimeException('SECRET network diagnostic');}return $certificate;};
$confirmSubscription=static function($store,$message,$settings)use(&$confirmations):string{$request=audienceSnsConfirmationRequest($message,$settings);if($request['TopicArn']!==$settings['topic_arn']||$request['AuthenticateOnUnsubscribe']!=='true')throw new RuntimeException('Confirmation lost its authenticated scope.');$confirmations++;return $settings['topic_arn'].':bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';};
if(processAudienceSnsInbox($db,25,$fetchCertificate,$confirmSubscription)!==5)throw new RuntimeException('Mail role cannot process queued SNS envelopes.');
$retry=$db->execute_query('SELECT * FROM marketing_ses_inbox WHERE id=?',[$queued[0]['id']])->fetch_assoc();
if($retry['state']!=='queued'||!$retry['payload_ciphertext']||str_contains($retry['error_message'],'SECRET'))throw new RuntimeException('Transient SNS failure lost its envelope or exposed provider details.');
$counts=$db->query('SELECT state,COUNT(*) n FROM marketing_ses_inbox GROUP BY state')->fetch_all(MYSQLI_ASSOC);$counts=array_column($counts,'n','state');
if((int)($counts['done']??0)!==3||(int)($counts['rejected']??0)!==1)throw new RuntimeException('Signed receipts and forged envelope were not separated.');
if((int)$db->query("SELECT COUNT(*) n FROM marketing_inbox_delivery_events e JOIN marketing_service_mail m ON m.id=e.service_mail_id AND m.audience_id=e.audience_id WHERE m.kind='test' AND m.recipient_hash=SHA2('delivery-one@example.test',256) AND e.event_type='send'")->fetch_assoc()['n']!==2)throw new RuntimeException('The restricted worker lost a test callback while retiring its raw envelope.');
foreach(array_slice($queued,1,2)as$notification){$payload=json_decode(\Dnr\Security\ApplicationKey::open($notification['payload_ciphertext']),true)['envelope'];$event=json_decode($payload['Message'],true);$email=$event['mail']['destination'][0];$actual=$db->execute_query('SELECT email_status FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email])->fetch_assoc()['email_status'];if($actual!==($event['eventType']==='Bounce'?'cleaned':'unsubscribed'))throw new RuntimeException('Queued bounce or complaint did not suppress marketing.');}
$db->execute_query('UPDATE marketing_ses_inbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?',[$queued[0]['id']]);
if(processAudienceSnsInbox($db,1,$fetchCertificate,$confirmSubscription)!==1||$confirmations!==1)throw new RuntimeException('Durable confirmation retry failed.');
if((int)$db->query("SELECT COUNT(*) n FROM marketing_ses_inbox WHERE payload_ciphertext<>''")->fetch_assoc()['n']!==0)throw new RuntimeException('Completed SNS tokens were retained.');
if(!str_ends_with($db->query('SELECT subscription_arn FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['subscription_arn'],':bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'))throw new RuntimeException('Authenticated subscription receipt was not retained.');
echo "SNS durable retry, signed confirmation, forged-envelope rejection, suppression and mail-role isolation passed.\n";
$fakeSes=new class{
 public array $sent=[];public ?Throwable $failure=null;public int $checks=0;public bool $verified=true;public ?Closure $onSend=null;
 public function getAccount(array $args):array{$this->checks++;return ['SendingEnabled'=>true,'ProductionAccessEnabled'=>true,'SendQuota'=>['Max24HourSend'=>1000,'MaxSendRate'=>10,'SentLast24Hours'=>0]];}
 public function getEmailIdentity(array $args):array{return ['VerifiedForSendingStatus'=>$this->verified,'VerificationStatus'=>$this->verified?'SUCCESS':'FAILED','DkimAttributes'=>['SigningEnabled'=>true,'Status'=>'SUCCESS']];}
 public function getConfigurationSet(array $args):array{return ['ConfigurationSetName'=>'message-monkey','SendingOptions'=>['SendingEnabled'=>true]];}
 public function getConfigurationSetEventDestinations(array $args):array{return ['EventDestinations'=>[['Enabled'=>true,'SnsDestination'=>['TopicArn'=>'arn:aws:sns:us-east-1:123456789012:message-monkey'],'MatchingEventTypes'=>['SEND','DELIVERY','BOUNCE','COMPLAINT','REJECT','DELIVERY_DELAY','RENDERING_FAILURE']]]];}
 public function sendEmail(array $args):array{$this->sent[]=$args;if($this->onSend)($this->onSend)($args);if($this->failure)throw $this->failure;return ['MessageId'=>'mock-ses-'.count($this->sent)];}
};
$factory=static fn($service,$settings,$credentials)=>$service==='ses'?$fakeSes:new class{public function getCallerIdentity(array $args):array{return ['Account'=>'123456789012'];}};
$db->query("UPDATE marketing_ses_settings SET check_state='queued' WHERE audience_id=1");
if(processAudienceSesSetup($db,1,$factory,static fn()=>['v=DMARC1; p=none'])!==1)throw new RuntimeException('SES check worker could not claim setup.');
if($db->query('SELECT check_state FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['check_state']!=='ready')throw new RuntimeException('SES check failed.');
$idleChecks=$fakeSes->checks;
if(processAudienceSesSetup($db,1,$factory)!==0||$fakeSes->checks!==$idleChecks)throw new RuntimeException('Fresh idle SES connection was checked unnecessarily.');
$db->query("UPDATE marketing_ses_settings SET checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 11 MINUTE) WHERE audience_id=1");
if(processAudienceSesSetup($db,1,$factory,static fn()=>['v=DMARC1; p=none'])!==1||$fakeSes->checks!==$idleChecks+1)throw new RuntimeException('Idle SES connection was not refreshed before signup readiness expired.');
$idleSettings=$db->query('SELECT * FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc();
if(audienceSesReasons($idleSettings,$ses['from_email'])||$fakeSes->sent)throw new RuntimeException('Idle refresh did not restore readiness without sending email.');
$fakeSes->verified=false;
$db->query("UPDATE marketing_ses_settings SET checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 11 MINUTE) WHERE audience_id=1");
processAudienceSesSetup($db,1,$factory,static fn()=>['v=DMARC1; p=none']);
$idleSettings=$db->query('SELECT * FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc();
if($idleSettings['check_state']!=='attention'||!audienceSesReasons($idleSettings,$ses['from_email'])||$fakeSes->sent)throw new RuntimeException('Failed idle verification did not block signup without sending email.');
if(processAudienceSesSetup($db,1,$factory)!==0)throw new RuntimeException('Failed connection was automatically retried without an administrator check.');
$fakeSes->verified=true;
$db->query("UPDATE marketing_ses_settings SET check_state='queued' WHERE audience_id=1");
processAudienceSesSetup($db,1,$factory,static fn()=>['v=DMARC1; p=none']);
echo "Idle SES readiness refresh, fresh-check suppression and failed-verification gate passed.\n";
$beforeChecks=$fakeSes->checks;
$db->query("UPDATE marketing_ses_settings SET checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE audience_id=1");
$refreshed=audienceSesWorkerReady($db,1,$ses['from_email'],audienceSesBinding($ses),$factory,static fn()=>['v=DMARC1; p=none']);
if($fakeSes->checks!==$beforeChecks+1||audienceSesReasons($refreshed,$ses['from_email']))throw new RuntimeException('Worker did not refresh expired SES verification.');
audienceSesWorkerReady($db,1,$ses['from_email'],audienceSesBinding($ses),$factory);
if($fakeSes->checks!==$beforeChecks+1)throw new RuntimeException('Worker unnecessarily repeated a fresh connection check.');
$fakeSes->verified=false;
$db->query("UPDATE marketing_ses_settings SET checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE audience_id=1");
$blocked=false;try{audienceSesWorkerReady($db,1,$ses['from_email'],audienceSesBinding($ses),$factory,static fn()=>['v=DMARC1; p=none']);}catch(DomainException){$blocked=true;}
if(!$blocked||$fakeSes->sent||$db->query('SELECT check_state FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['check_state']!=='attention')throw new RuntimeException('Failed verification did not block delivery before send.');
$db->query("UPDATE marketing_ses_settings SET check_state='ready',checked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE audience_id=1");
$blocked=false;try{audienceSesWorkerReady($db,1,$ses['from_email'],audienceSesBinding($ses),static fn()=>throw new RuntimeException('Provider unavailable'));}catch(RuntimeException){$blocked=true;}
if(!$blocked||$fakeSes->sent)throw new RuntimeException('Unavailable verification did not block delivery before send.');
$fakeSes->verified=true;
audienceSesWorkerReady($db,1,$ses['from_email'],audienceSesBinding($ses),$factory,static fn()=>['v=DMARC1; p=none']);
echo "Expired SES verification refreshes in the credential-isolated worker; invalid/unavailable verification blocks delivery.\n";
try{$db->query("UPDATE marketing_contacts SET email='forbidden@example.test' WHERE 1=0");throw new RuntimeException('Mail role can replace connection identity.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
$db->query('SELECT id,audience_id,email,email_status,is_archived,deleted_at FROM marketing_contacts LIMIT 0');
$sent=processAudienceCampaignDelivery($db,10,static function($m)use($db,$factory){
 $m['text']='Large newsletter transport regression';
 $m['html']='<p>'.str_repeat('Newsletter ',880000).'</p>';
 if(strlen($m['text'])+strlen($m['html'])>AUDIENCE_DESIGN_MAX_BYTES)throw new RuntimeException('Large email fixture exceeds the design limit.');
 return audienceSesDeliver($db,$m,'campaign',$factory);
});if($sent!==1)throw new RuntimeException('Dedicated role could not process a fictional campaign.');
if(count($fakeSes->sent)!==1)throw new RuntimeException('SES campaign send was replayed.');
$request=$fakeSes->sent[0];if(count($request['Destination']['ToAddresses'])!==1||$request['ConfigurationSetName']!=='message-monkey'||$request['ConfigurationOverrides']['Tracking']['ClickTrackingEnabled']!=='DISABLED'||!str_contains($request['Content']['Raw']['Data'],'List-Unsubscribe-Post: List-Unsubscribe=One-Click'))throw new RuntimeException('SES envelope lost private delivery, tracking or unsubscribe headers.');
if(strlen($request['Content']['Raw']['Data'])<=9_500_000)throw new RuntimeException('Large email regression did not exercise encoded MIME overhead.');
$row=$db->query("SELECT * FROM marketing_campaign_recipients WHERE provider_message_id='mock-ses-1'")->fetch_assoc();if(!$row||$row['state']!=='accepted'||!$row['ses_correlation'])throw new RuntimeException('SES receipt was not retained.');
// The API adapter’s quota and ambiguity behavior is exercised under the actual worker role.
$resetSes=static function()use($db,$row,$fakeSes):void{$fakeSes->failure=null;$db->query("UPDATE marketing_ses_settings SET blocked_until=NULL,last_permit_at=NULL,quota_checked_at=UTC_TIMESTAMP(6),sent_last_day=0,max_daily=1000,max_rate=1000,check_state='ready',checked_at=UTC_TIMESTAMP() WHERE audience_id=1");$db->execute_query("UPDATE marketing_campaign_recipients SET state='queued',provider_message_id=NULL,ses_correlation=NULL,next_attempt_at=UTC_TIMESTAMP(),attempts=0,delivery_started_at=NULL WHERE id=?",[$row['id']]);$db->execute_query("UPDATE marketing_campaign_deliveries SET state='sending' WHERE id=?",[$row['delivery_id']]);$db->execute_query("UPDATE marketing_campaigns SET status='sending' WHERE id=(SELECT campaign_id FROM marketing_campaign_deliveries WHERE id=?)",[$row['delivery_id']]);};
$resetSes();$db->query('UPDATE marketing_ses_settings SET sent_last_day=1000 WHERE audience_id=1');$before=count($fakeSes->sent);processAudienceCampaignDelivery($db,1,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));$receipt=$db->execute_query('SELECT state,attempts FROM marketing_campaign_recipients WHERE id=?',[$row['id']])->fetch_assoc();if(count($fakeSes->sent)!==$before||$receipt['state']!=='queued'||(int)$receipt['attempts']!==0)throw new RuntimeException('SES quota deferral sent or consumed an attempt.');
foreach([['throttle',429,'TooManyRequestsException','queued'],['permission',403,'AccessDeniedException','failed'],['rejection',400,'MessageRejected','failed'],['ambiguous',500,'InternalFailure','uncertain']]as[$case,$status,$code,$expected]){
 $resetSes();$fakeSes->failure=new \Aws\Exception\AwsException('Sensitive provider diagnostic',new \Aws\Command('SendEmail'),['code'=>$code,'response'=>new \GuzzleHttp\Psr7\Response($status)]);$before=count($fakeSes->sent);processAudienceCampaignDelivery($db,1,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));$receipt=$db->execute_query('SELECT state,attempts,error_message FROM marketing_campaign_recipients WHERE id=?',[$row['id']])->fetch_assoc();if($receipt['state']!==$expected||count($fakeSes->sent)!==$before+1||str_contains($receipt['error_message'],'Sensitive'))throw new RuntimeException('SES '.$case.' handling failed.');if($case==='throttle'&&(int)$receipt['attempts']!==0)throw new RuntimeException('SES throttle consumed an attempt.');if($case==='permission'&&(!str_contains($receipt['error_message'],'SES IAM policy')||$db->query('SELECT check_state FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['check_state']!=='ready'))throw new RuntimeException('Recipient permission denial disabled the connection or lost its actionable error.');
}
$before=count($fakeSes->sent);processAudienceCampaignDelivery($db,1,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));if(count($fakeSes->sent)!==$before)throw new RuntimeException('Ambiguous SES acceptance was replayed.');
echo "SES quota, throttle, rejection, ambiguity and no-replay checks passed.\n";
$resetSes();
$testPayload=$db->query("SELECT payload_ciphertext FROM marketing_service_mail WHERE audience_id=1 AND kind='test' ORDER BY id DESC LIMIT 1")->fetch_assoc();
if(!$testPayload)throw new RuntimeException('Test-mail fixture missing.');
// The HTTP suites also leave queued fixtures. Isolate these three worker jobs,
// then restore the other fixtures' schedules for the remaining qualification.
$pendingServiceFixtures=$db->query("SELECT id,next_attempt_at FROM marketing_service_mail WHERE audience_id=1 AND state='queued'")->fetch_all(MYSQLI_ASSOC);
foreach($pendingServiceFixtures as$fixture)$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?',[$fixture['id']]);
$testJobs=[];
foreach(['denied-one@example.test'=>'AccessDeniedException','denied-two@another.example.test'=>'AccessDenied','allowed@example.test'=>null]as$email=>$failure){
 $payload=json_decode(\Dnr\Security\ApplicationKey::open($testPayload['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR);
 $operation=bin2hex(random_bytes(32));$payload['message']['email']=$email;$payload['message']['message_id']='<test-'.substr($operation,0,40).'@message-monkey.invalid>';
 $db->execute_query("INSERT INTO marketing_service_mail(audience_id,kind,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (1,'test',?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))",[$operation,\Dnr\Security\ApplicationKey::seal(json_encode($payload,JSON_THROW_ON_ERROR)),$payload['message']['message_id']]);
 $testJobs[(int)$db->insert_id]=$failure;
}
$before=count($fakeSes->sent);
processAudienceServiceMail($db,3,static function($message,$kind)use($db,$factory,$fakeSes,$testJobs):string{
 $code=$testJobs[$message['native_service_id']];
 $fakeSes->failure=$code===null?null:new \Aws\Exception\AwsException('SECRET provider diagnostic',new \Aws\Command('SendEmail'),['code'=>$code,'response'=>new \GuzzleHttp\Psr7\Response(403)]);
 return audienceSesDeliver($db,$message,$kind,$factory);
});
foreach($testJobs as$id=>$code){
 $receipt=$db->execute_query('SELECT state,attempts,error_message,provider_message_id FROM marketing_service_mail WHERE id=?',[$id])->fetch_assoc();
 if($receipt['state']!==($code===null?'accepted':'failed')||(int)$receipt['attempts']!==1||str_contains($receipt['error_message'],'SECRET'))throw new RuntimeException('Service-mail permission result was lost or leaked provider details.');
 if($code!==null&&(!str_contains($receipt['error_message'],'SES IAM policy')||$receipt['provider_message_id']!==null))throw new RuntimeException('Permission denial was misreported as delivery or generic eligibility failure.');
 if($code===null&&(!$receipt['provider_message_id']||$receipt['error_message']!==''))throw new RuntimeException('An allowed recipient could not send after a denied recipient.');
}
if(count($fakeSes->sent)!==$before+3||$db->query('SELECT check_state FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['check_state']!=='ready')throw new RuntimeException('Per-message denial invalidated SES readiness.');
processAudienceServiceMail($db,3,static fn($m,$kind)=>audienceSesDeliver($db,$m,$kind,$factory));
if(count($fakeSes->sent)!==$before+3)throw new RuntimeException('A permission-denied test email was automatically replayed.');
// A signed Delivery can race the API timeout; it must settle acceptance without another send.
$payload=json_decode(\Dnr\Security\ApplicationKey::open($testPayload['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR);
$payload['message']['email']='callback-race@example.test';$payload['message']['message_id']='<race-'.bin2hex(random_bytes(20)).'@example.test>';
$db->execute_query("INSERT INTO marketing_service_mail(audience_id,kind,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (1,'test',?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))",[bin2hex(random_bytes(32)),\Dnr\Security\ApplicationKey::seal(json_encode($payload,JSON_THROW_ON_ERROR)),$payload['message']['message_id']]);$raceId=(int)$db->insert_id;
$raceKey=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$raceCsr=openssl_csr_new(['commonName'=>'Test receipt race'],$raceKey);$raceX509=openssl_csr_sign($raceCsr,null,$raceKey,1);openssl_x509_export($raceX509,$raceCertificate);
$fakeSes->failure=new RuntimeException('API response lost');
$fakeSes->onSend=static function(array $request)use($db,$raceId,$raceKey,$raceCertificate):void{
 $store=new AudienceStore($db,1,0,'Receipt race');$settings=$store->sesSettings();$tags=array_column($request['EmailTags'],'Value','Name');
 $job=$store->one('SELECT * FROM marketing_service_mail WHERE id=?',[$raceId]);
 if($job['ses_correlation']!==$tags['moed-receipt']||$job['recipient_hash']!==audienceDispatchEmailHash('callback-race@example.test')||$job['provider_account_id']!==$settings['aws_account_id']||$job['provider_region']!==$settings['region'])throw new RuntimeException('Test send did not freeze its callback binding before contacting SES.');
 $event=['eventType'=>'Delivery','mail'=>['sendingAccountId'=>$settings['aws_account_id'],'messageId'=>'race-provider-receipt','destination'=>$request['Destination']['ToAddresses'],'timestamp'=>gmdate('c'),'tags'=>['moed-receipt'=>[$tags['moed-receipt']],'moed-account'=>[$tags['moed-account']]]]];
 $envelope=['Type'=>'Notification','MessageId'=>'test-race-delivered','TopicArn'=>$settings['topic_arn'],'Timestamp'=>gmdate('c'),'Message'=>json_encode($event),'SignatureVersion'=>'2','SigningCertURL'=>'https://'.audienceSnsHost($settings).'/SimpleNotificationService-race.pem','Signature'=>'pending'];
 openssl_sign((new \Aws\Sns\MessageValidator())->getStringToSign(new \Aws\Sns\Message($envelope)),$signature,$raceKey,OPENSSL_ALGO_SHA256);$envelope['Signature']=base64_encode($signature);
 if(audienceSnsReceive($store,$envelope,static fn()=>$raceCertificate)!=='accepted')throw new RuntimeException('Early signed callback was lost.');
};
processAudienceServiceMail($db,1,static fn($m,$kind)=>audienceSesDeliver($db,$m,$kind,$factory));
$raceReceipt=$db->execute_query('SELECT state,error_message,provider_message_id FROM marketing_service_mail WHERE id=?',[$raceId])->fetch_assoc();
if($raceReceipt['state']!=='accepted'||$raceReceipt['error_message']!==''||$raceReceipt['provider_message_id']!=='race-provider-receipt')throw new RuntimeException('Early delivery receipt failed to resolve an ambiguous API response.');
$fakeSes->onSend=null;$fakeSes->failure=null;$afterRace=count($fakeSes->sent);processAudienceServiceMail($db,1,static fn($m,$kind)=>audienceSesDeliver($db,$m,$kind,$factory));
if(count($fakeSes->sent)!==$afterRace)throw new RuntimeException('A confirmed test email was replayed.');
echo "Restricted worker retains test callbacks and resolves callback/API races without replay.\n";
foreach($pendingServiceFixtures as$fixture)$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$fixture['next_attempt_at'],$fixture['id']]);
echo "Test-mail permission denials retain safe actionable errors without blocking other recipients or replaying failed sends.\n";
$deferred=static function(callable $call):MailBudgetDeferredException{try{$call();}catch(MailBudgetDeferredException $e){return $e;}throw new RuntimeException('Expected quota deferral.');};
$db->query("UPDATE marketing_delivery_policy SET blocked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE audience_id=1");
$pause=$deferred(fn()=>audienceReserveMail($db,'<pause@example.test>','confirmation'));if(strtotime($pause->retryAt.' UTC')<time()+3500)throw new RuntimeException('Provider pause ignored.');
$db->query('UPDATE marketing_delivery_policy SET blocked_until=NULL WHERE audience_id=1');
audienceReserveMail($db,'<one@example.test>','marketing');$deferred(fn()=>audienceReserveMail($db,'<paced@example.test>','marketing'));
$db->query('UPDATE marketing_delivery_policy SET last_permit_at=NULL WHERE audience_id=1');audienceReserveMail($db,'<two@example.test>','marketing');
$deferred(fn()=>audienceReserveMail($db,'<reserve@example.test>','marketing'));
audienceReserveMail($db,'<operational@example.test>','operational');$deferred(fn()=>audienceReserveMail($db,'<exhausted@example.test>','operational'));
if((int)$db->query('SELECT COUNT(*) n FROM marketing_relay_usage')->fetch_assoc()['n']!==3)throw new RuntimeException('Deferrals consumed allocation.');
try{$db->query('UPDATE marketing_delivery_policy SET hourly_budget=100 WHERE audience_id=1');throw new RuntimeException('Worker can change paid budgets.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
$uid=(int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch_assoc()['id'];$claim=bin2hex(random_bytes(16));
$db->execute_query("INSERT INTO notification_outbox(user_id,notification_type,digest_date,recipient_hash,payload_ciphertext,status,attempts,claim_token,processing_started_at,delivery_started_at) VALUES (?,'daily_task_digest','2099-01-01',?,'encrypted-fixture','processing',8,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$uid,random_bytes(32),$claim]);$notice=(int)$db->insert_id;
$future=gmdate('Y-m-d H:i:s',time()+3600);audienceDeferOperationalMail($db,'notification_outbox',$notice,$claim,new MailBudgetDeferredException($future,'Hourly allocation used.'));
$noticeRow=$db->execute_query('SELECT * FROM notification_outbox WHERE id=?',[$notice])->fetch_assoc();if($noticeRow['status']!=='retry'||(int)$noticeRow['attempts']!==7||$noticeRow['payload_ciphertext']!=='encrypted-fixture'||$noticeRow['claim_token']!==null||$noticeRow['delivery_started_at']!==null||$noticeRow['next_attempt_at']!==$future)throw new RuntimeException('Quota deferral lost an operational message or consumed retries.');
echo "Shared paid allocation, reserves, interval and provider pause checks passed.\n";
echo "SES credential, API envelope, setup and campaign worker privileges passed; no external message sent.\n";

// A data-only flow must execute with the dedicated role, without credential-write authority.
$flowWorker = new AudienceStore($db,1,0,'Worker qualification');
processAudienceFlows($db,20);
$flowFixture=$db->query("SELECT r.state,c.first_name FROM marketing_flow_runs r JOIN marketing_flows f ON f.id=r.flow_id JOIN marketing_contacts c ON c.id=r.contact_id WHERE f.name='Restricted worker flow' ORDER BY r.id DESC LIMIT 1")->fetch_assoc();
if(!$flowFixture||$flowFixture['state']!=='completed'||$flowFixture['first_name']!=='Worker qualified')throw new RuntimeException('Dedicated role could not execute a native flow action.');
try{$db->query("UPDATE marketing_flows SET state='running' WHERE 1=0");throw new RuntimeException('Mail worker can activate authoring state.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
echo "Flow execution and authoring privilege separation passed.\n";

// Inbox execution can inspect eligibility and receipts, never author sources or templates.
require_once '/var/www/html/audience_inbox_runtime.php';
$workerInbox=new AudienceStore($db,1,0,'Inbox worker');
if(!$workerInbox->inboxAvailable())throw new RuntimeException('Inbox runtime was not installed.');
$workerInbox->inboxSettings();$db->query('SELECT role,email,email_verified_at,account_status FROM users LIMIT 1');
$db->query('SELECT id FROM marketing_inbox_delivery_events LIMIT 1');
foreach(["UPDATE marketing_inbox_settings SET gateway_confirmed=1 WHERE 1=0","INSERT INTO marketing_inbox_templates(audience_id,name,subject,body_html) VALUES (1,'Forbidden worker authoring','','')"]as$sql){try{$db->query($sql);throw new RuntimeException('Inbox worker gained authoring privileges.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}}
// Exercise the real eligibility path, not just SELECTs against a few tables.
$reply=$db->query("SELECT m.* FROM marketing_service_mail m JOIN marketing_contacts c ON c.id=m.contact_id WHERE m.kind='inbox' AND c.email='inbox-worker-ready@example.test' ORDER BY m.id DESC LIMIT 1")->fetch_assoc();
if(!$reply)throw new RuntimeException('Restricted Inbox fixture missing.');
$message=json_decode(\Dnr\Security\ApplicationKey::open($reply['payload_ciphertext']),true)['message'];
audienceInboxServiceReady($db,$reply,$message);
try{$db->query('SELECT label_id FROM marketing_inbox_thread_labels LIMIT 0');throw new RuntimeException('Delivery acquired unnecessary UI label access.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
$missing=$message;$missing['inbox_thread_id']=2147483647;
try{audienceInboxServiceReady($db,$reply,$missing);throw new RuntimeException('Missing conversation was allowed.');}catch(InvalidArgumentException){}
$queuedForLater=$db->query("SELECT id,next_attempt_at FROM marketing_service_mail WHERE state='queued'")->fetch_all(MYSQLI_ASSOC);
foreach($queuedForLater as$fixture)$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?',[$fixture['id']]);
// Freeze the current synthetic sender binding, just as a fresh queued reply does.
$message['ses_binding']=audienceSesBinding($db->query('SELECT * FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc());
$db->execute_query("UPDATE marketing_service_mail SET state='queued',next_attempt_at=UTC_TIMESTAMP(),payload_ciphertext=? WHERE id=?",[\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>$message],JSON_THROW_ON_ERROR)),$reply['id']]);
$replySent=0;processAudienceServiceMail($db,1,static function($outgoing,$kind)use(&$replySent,$db,$factory):string{if($kind!=='inbox'||$outgoing['email']!=='inbox-worker-ready@example.test')throw new RuntimeException('Unexpected fixture recipient.');$replySent++;return audienceSesDeliver($db,$outgoing,$kind,$factory);});
$replyResult=$db->execute_query('SELECT state,error_message FROM marketing_service_mail WHERE id=?',[$reply['id']])->fetch_assoc();
if($replySent!==1||$replyResult['state']!=='accepted'||$replyResult['error_message']!=='')throw new RuntimeException('Restricted worker could not prepare and deliver a labelled Inbox reply: '.$replyResult['error_message']);
foreach($queuedForLater as$fixture)if((int)$fixture['id']!==(int)$reply['id'])$db->execute_query('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$fixture['next_attempt_at'],$fixture['id']]);
echo "Inbox restricted worker prepares and delivers a labelled reply without UI privileges; missing threads fail closed.\n";
echo "Inbox worker read/receipt privileges and source/template authoring separation passed.\n";

require_once '/var/www/html/audience_notification_runtime.php';
queueAudienceChangeAlerts($db,10);
(new AudienceStore($db,1,0,'Permission fixture'))->audienceNotificationUsers();
$db->query('UPDATE marketing_audience_notification_state SET event_cursor=event_cursor WHERE audience_id=1');
try{$db->query("UPDATE marketing_audiences SET settings_json=JSON_OBJECT() WHERE id=1");throw new RuntimeException('Mail worker can change audience defaults or staff notification preferences.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
echo "Audience notification worker permissions passed; no external mail.\n";
