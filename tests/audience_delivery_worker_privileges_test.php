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
if(count($queued)!==4)throw new RuntimeException('Signed SNS queue fixtures missing.');
$fixture=json_decode(\Dnr\Security\ApplicationKey::open($queued[0]['payload_ciphertext']),true);
$certificate=$fixture['envelope']['FixtureCertificate'];$failOnce=true;$confirmations=0;
$fetchCertificate=static function(string $url)use($certificate,&$failOnce):string{if($failOnce){$failOnce=false;throw new RuntimeException('SECRET network diagnostic');}return $certificate;};
$confirmSubscription=static function($store,$message,$settings)use(&$confirmations):string{$request=audienceSnsConfirmationRequest($message,$settings);if($request['TopicArn']!==$settings['topic_arn']||$request['AuthenticateOnUnsubscribe']!=='true')throw new RuntimeException('Confirmation lost its authenticated scope.');$confirmations++;return $settings['topic_arn'].':bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';};
if(processAudienceSnsInbox($db,25,$fetchCertificate,$confirmSubscription)!==4)throw new RuntimeException('Mail role cannot process queued SNS envelopes.');
$retry=$db->execute_query('SELECT * FROM marketing_ses_inbox WHERE id=?',[$queued[0]['id']])->fetch_assoc();
if($retry['state']!=='queued'||!$retry['payload_ciphertext']||str_contains($retry['error_message'],'SECRET'))throw new RuntimeException('Transient SNS failure lost its envelope or exposed provider details.');
$counts=$db->query('SELECT state,COUNT(*) n FROM marketing_ses_inbox GROUP BY state')->fetch_all(MYSQLI_ASSOC);$counts=array_column($counts,'n','state');
if((int)($counts['done']??0)!==2||(int)($counts['rejected']??0)!==1)throw new RuntimeException('Signed receipts and forged envelope were not separated.');
foreach(array_slice($queued,1,2)as$notification){$payload=json_decode(\Dnr\Security\ApplicationKey::open($notification['payload_ciphertext']),true)['envelope'];$event=json_decode($payload['Message'],true);$email=$event['mail']['destination'][0];$actual=$db->execute_query('SELECT email_status FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email])->fetch_assoc()['email_status'];if($actual!==($event['eventType']==='Bounce'?'cleaned':'unsubscribed'))throw new RuntimeException('Queued bounce or complaint did not suppress marketing.');}
$db->execute_query('UPDATE marketing_ses_inbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?',[$queued[0]['id']]);
if(processAudienceSnsInbox($db,1,$fetchCertificate,$confirmSubscription)!==1||$confirmations!==1)throw new RuntimeException('Durable confirmation retry failed.');
if((int)$db->query("SELECT COUNT(*) n FROM marketing_ses_inbox WHERE payload_ciphertext<>''")->fetch_assoc()['n']!==0)throw new RuntimeException('Completed SNS tokens were retained.');
if(!str_ends_with($db->query('SELECT subscription_arn FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['subscription_arn'],':bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'))throw new RuntimeException('Authenticated subscription receipt was not retained.');
echo "SNS durable retry, signed confirmation, forged-envelope rejection, suppression and mail-role isolation passed.\n";
$fakeSes=new class{
 public array $sent=[];public ?Throwable $failure=null;
 public function getAccount(array $args):array{return ['SendingEnabled'=>true,'ProductionAccessEnabled'=>true,'SendQuota'=>['Max24HourSend'=>1000,'MaxSendRate'=>10,'SentLast24Hours'=>0]];}
 public function getEmailIdentity(array $args):array{return ['VerifiedForSendingStatus'=>true,'VerificationStatus'=>'SUCCESS','DkimAttributes'=>['SigningEnabled'=>true,'Status'=>'SUCCESS']];}
 public function getConfigurationSet(array $args):array{return ['ConfigurationSetName'=>'message-monkey','SendingOptions'=>['SendingEnabled'=>true]];}
 public function getConfigurationSetEventDestinations(array $args):array{return ['EventDestinations'=>[['Enabled'=>true,'SnsDestination'=>['TopicArn'=>'arn:aws:sns:us-east-1:123456789012:message-monkey'],'MatchingEventTypes'=>['SEND','DELIVERY','BOUNCE','COMPLAINT','REJECT','DELIVERY_DELAY','RENDERING_FAILURE']]]];}
 public function sendEmail(array $args):array{$this->sent[]=$args;if($this->failure)throw $this->failure;return ['MessageId'=>'mock-ses-'.count($this->sent)];}
};
$factory=static fn($service,$settings,$credentials)=>$service==='ses'?$fakeSes:new class{public function getCallerIdentity(array $args):array{return ['Account'=>'123456789012'];}};
$db->query("UPDATE marketing_ses_settings SET check_state='queued' WHERE audience_id=1");
if(processAudienceSesSetup($db,1,$factory,static fn()=>['v=DMARC1; p=none'])!==1)throw new RuntimeException('SES check worker could not claim setup.');
if($db->query('SELECT check_state FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['check_state']!=='ready')throw new RuntimeException('SES check failed.');
try{$db->query("UPDATE marketing_contacts SET email='forbidden@example.test' WHERE 1=0");throw new RuntimeException('Mail role can replace connection identity.');}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1142,1143],true))throw $e;}
$db->query('SELECT id,audience_id,email,email_status,is_archived,deleted_at FROM marketing_contacts LIMIT 0');
$sent=processAudienceCampaignDelivery($db,10,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));if($sent!==1)throw new RuntimeException('Dedicated role could not process a fictional campaign.');
if(count($fakeSes->sent)!==1)throw new RuntimeException('SES campaign send was replayed.');
$request=$fakeSes->sent[0];if(count($request['Destination']['ToAddresses'])!==1||$request['ConfigurationSetName']!=='message-monkey'||$request['ConfigurationOverrides']['Tracking']['ClickTrackingEnabled']!=='DISABLED'||!str_contains($request['Content']['Raw']['Data'],'List-Unsubscribe-Post: List-Unsubscribe=One-Click'))throw new RuntimeException('SES envelope lost private delivery, tracking or unsubscribe headers.');
$row=$db->query("SELECT * FROM marketing_campaign_recipients WHERE provider_message_id='mock-ses-1'")->fetch_assoc();if(!$row||$row['state']!=='accepted'||!$row['ses_correlation'])throw new RuntimeException('SES receipt was not retained.');
// The API adapter’s quota and ambiguity behavior is exercised under the actual worker role.
$resetSes=static function()use($db,$row,$fakeSes):void{$fakeSes->failure=null;$db->query("UPDATE marketing_ses_settings SET blocked_until=NULL,last_permit_at=NULL,quota_checked_at=UTC_TIMESTAMP(6),sent_last_day=0,max_daily=1000,max_rate=1000,check_state='ready',checked_at=UTC_TIMESTAMP() WHERE audience_id=1");$db->execute_query("UPDATE marketing_campaign_recipients SET state='queued',provider_message_id=NULL,ses_correlation=NULL,next_attempt_at=UTC_TIMESTAMP(),attempts=0,delivery_started_at=NULL WHERE id=?",[$row['id']]);$db->execute_query("UPDATE marketing_campaign_deliveries SET state='sending' WHERE id=?",[$row['delivery_id']]);$db->execute_query("UPDATE marketing_campaigns SET status='sending' WHERE id=(SELECT campaign_id FROM marketing_campaign_deliveries WHERE id=?)",[$row['delivery_id']]);};
$resetSes();$db->query('UPDATE marketing_ses_settings SET sent_last_day=1000 WHERE audience_id=1');$before=count($fakeSes->sent);processAudienceCampaignDelivery($db,1,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));$receipt=$db->execute_query('SELECT state,attempts FROM marketing_campaign_recipients WHERE id=?',[$row['id']])->fetch_assoc();if(count($fakeSes->sent)!==$before||$receipt['state']!=='queued'||(int)$receipt['attempts']!==0)throw new RuntimeException('SES quota deferral sent or consumed an attempt.');
foreach([['throttle',429,'TooManyRequestsException','queued'],['rejection',400,'MessageRejected','failed'],['ambiguous',500,'InternalFailure','uncertain']]as[$case,$status,$code,$expected]){
 $resetSes();$fakeSes->failure=new \Aws\Exception\AwsException('Sensitive provider diagnostic',new \Aws\Command('SendEmail'),['code'=>$code,'response'=>new \GuzzleHttp\Psr7\Response($status)]);$before=count($fakeSes->sent);processAudienceCampaignDelivery($db,1,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));$receipt=$db->execute_query('SELECT state,attempts,error_message FROM marketing_campaign_recipients WHERE id=?',[$row['id']])->fetch_assoc();if($receipt['state']!==$expected||count($fakeSes->sent)!==$before+1||str_contains($receipt['error_message'],'Sensitive'))throw new RuntimeException('SES '.$case.' handling failed.');if($case==='throttle'&&(int)$receipt['attempts']!==0)throw new RuntimeException('SES throttle consumed an attempt.');
}
$before=count($fakeSes->sent);processAudienceCampaignDelivery($db,1,static fn($m)=>audienceSesDeliver($db,$m,'campaign',$factory));if(count($fakeSes->sent)!==$before)throw new RuntimeException('Ambiguous SES acceptance was replayed.');
echo "SES quota, throttle, rejection, ambiguity and no-replay checks passed.\n";
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
