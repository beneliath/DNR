<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable mail-worker fixture required.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once $source.'/audience_delivery_lifecycle.php';
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Subscription notice worker');
$job=$store->one("SELECT * FROM marketing_service_mail WHERE kind='subscription-notice' AND state='queued' ORDER BY id DESC LIMIT 1");
if(!$job)throw new RuntimeException('Missing synthetic subscription notice.');
$message=json_decode(\Dnr\Security\ApplicationKey::open($job['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR)['message'];
$sdk=new class{
    public array $requests=[];
    public function sendEmail(array $request):array{$this->requests[]=$request;return ['MessageId'=>'subscription-notice-sdk-fixture'];}
};
processAudienceServiceMail($db,20,static fn(array $message,string $kind):string=>audienceSesDeliver($db,$message,$kind,static fn()=>$sdk));
$receipt=$store->one('SELECT * FROM marketing_service_mail WHERE id=?',[$job['id']]);
if(count($sdk->requests)!==1||$sdk->requests[0]['Destination']['ToAddresses']!==[$message['email']]||$receipt['state']!=='accepted'||$receipt['payload_ciphertext']!==''||strlen($receipt['ses_correlation'])!==64||$receipt['recipient_hash']!==audienceDispatchEmailHash($message['email'])||$receipt['provider_message_id']!=='subscription-notice-sdk-fixture')throw new RuntimeException('Dedicated dispatch role must send, correlate and erase the notice through the injected SDK.');
echo "Subscription notice dispatch role: fake SES adapter, receipt correlation and payload erasure passed; no real email sent.\n";
