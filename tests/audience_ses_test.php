<?php
declare(strict_types=1);
require_once __DIR__.'/../vendor/autoload.php';
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/audience_store.php';require_once $source.'/audience_ses_worker.php';require_once $source.'/audience_ses_notifications.php';
$checks=0;$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};$reject=static function(callable $call)use($expect):void{try{$call();}catch(Throwable){$expect(true,'Rejected invalid input');return;}throw new RuntimeException('Expected rejection');};
$s=audienceSesInput(['region'=>'us-east-1','from_email'=>'Sender@Example.org','configuration_set'=>'message-monkey','topic_arn'=>'arn:aws:sns:us-east-1:123456789012:message-monkey']);$expect($s['from_email']==='sender@example.org'&&$s['aws_account_id']==='123456789012','Normalized scoped SES setup');
foreach([['region'=>'http://localhost'],['topic_arn'=>'arn:aws:sns:eu-west-1:123456789012:topic'],['topic_arn'=>'arn:aws:sqs:us-east-1:123456789012:topic'],['configuration_set'=>'../bad'],['from_email'=>'a@gmail.com']]as$change)$reject(fn()=>audienceSesInput(array_replace($s,$change)));
$ready=$s+['enabled'=>1,'access_key_tail'=>'TEST','check_state'=>'ready','checked_at'=>gmdate('Y-m-d H:i:s'),'production_access'=>1,'sending_enabled'=>1,'max_daily'=>100,'max_rate'=>2,'subscription_arn'=>$s['topic_arn'].':'.str_repeat('a',36)];$expect(!audienceSesReasons($ready,$s['from_email']),'All prerequisites permit readiness');
foreach([['enabled'=>0],['subscription_arn'=>''],['production_access'=>0],['max_rate'=>0],['check_state'=>'attention'],['checked_at'=>'2000-01-01']]as$change)$expect((bool)audienceSesReasons(array_replace($ready,$change),$s['from_email']),'Missing prerequisite blocks sending');
$expect((bool)audienceSesReasons($ready,'other@example.org'),'Different sender blocked');$expect(!array_key_exists('access_key_tail',audienceSesBinding($ready)),'Snapshot excludes credential material');
$expired=array_replace($ready,['checked_at'=>gmdate('Y-m-d H:i:s',time()-3600)]);
$expect((bool)audienceSesReasons($expired,$s['from_email']),'Expired verification still blocks direct delivery');
$expect(!audienceSesReasons($expired,$s['from_email'],requireFreshCheck:false),'Previously verified sender can queue a test for worker revalidation');
foreach([['enabled'=>0],['access_key_tail'=>''],['subscription_arn'=>''],['production_access'=>0],['sending_enabled'=>0],['max_daily'=>0],['max_rate'=>0],['check_state'=>'attention'],['check_state'=>'queued'],['checked_at'=>null],['checked_at'=>'invalid']]as$change)$expect((bool)audienceSesReasons(array_replace($expired,$change),$s['from_email'],requireFreshCheck:false),'Test enqueueing still requires a configured, previously verified sender');
$expect((bool)audienceSesReasons($expired,'other@example.org',requireFreshCheck:false),'Test enqueueing cannot change the configured sender');
$certUrl='https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem';$expect(audienceSnsCertificateUrl($certUrl,$s),'Exact AWS certificate URL accepted');
$eventSecond=gmdate('Y-m-d\TH:i:s');$expect(str_ends_with(audienceSnsEventTime($eventSecond.'.123456Z'),'.123456'),'SES receipts preserve subsecond consent ordering');$reject(fn()=>audienceSnsEventTime('tomorrow'));
foreach(['http://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem','https://sns.us-east-1.amazonaws.com.evil.test/SimpleNotificationService-x.pem','https://sns.us-east-1.amazonaws.com:443/SimpleNotificationService-x.pem','https://sns.us-east-1.amazonaws.com/other.pem','https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-x.pem',$certUrl.'?next=http://localhost']as$url)$expect(!audienceSnsCertificateUrl($url,$s),'Certificate fetch cannot redirect scope');
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$csr=openssl_csr_new(['commonName'=>'SNS test certificate'],$key);$x509=openssl_csr_sign($csr,null,$key,1);openssl_x509_export($x509,$cert);
$data=['Type'=>'Notification','MessageId'=>'fixture-123','TopicArn'=>$s['topic_arn'],'Timestamp'=>gmdate('c'),'Message'=>'{"eventType":"Send"}','SignatureVersion'=>'2','SigningCertURL'=>$certUrl,'Signature'=>'pending'];
$sign=static function(array $d)use($key):array{$m=new \Aws\Sns\Message($d);$canonical=(new \Aws\Sns\MessageValidator())->getStringToSign($m);openssl_sign($canonical,$sig,$key,$d['SignatureVersion']==='1'?OPENSSL_ALGO_SHA1:OPENSSL_ALGO_SHA256);$d['Signature']=base64_encode($sig);return $d;};
foreach(['1','2']as$version){$signed=$sign(array_replace($data,['SignatureVersion'=>$version]));$expect(audienceSnsValidate($signed,$ready,static fn()=>$cert)['MessageId']==='fixture-123','Real SNS canonical RSA signature verifies');$reject(fn()=>audienceSnsValidate(array_replace($signed,['Message'=>'tampered']),$ready,static fn()=>$cert));}
$reject(fn()=>audienceSnsValidate($sign(array_replace($data,['Timestamp'=>'2000-01-01T00:00:00Z'])),$ready,static fn()=>$cert));$reject(fn()=>audienceSnsValidate($sign(array_replace($data,['TopicArn'=>'arn:aws:sns:us-east-1:999999999999:other'])),$ready,static fn()=>$cert));
$confirm=$sign(array_replace($data,['Type'=>'SubscriptionConfirmation','Token'=>'secret-token','SubscribeURL'=>'https://evil.test/']));$m=audienceSnsValidate($confirm,$ready,static fn()=>$cert);$request=audienceSnsConfirmationRequest($m,$s);$expect($request===['TopicArn'=>$s['topic_arn'],'Token'=>'secret-token','AuthenticateOnUnsubscribe'=>'true'],'Confirmation uses only authenticated token and configured topic');
$snsClient=audienceSesClient('sns',$s,['key'=>'AKIA'.str_repeat('A',16),'secret'=>str_repeat('b',40)]);$signedRequest=false;$snsClient->getHandlerList()->setHandler(static function($command,$httpRequest)use(&$signedRequest){$signedRequest=str_starts_with($httpRequest->getHeaderLine('Authorization'),'AWS4-HMAC-SHA256 ')&&str_contains((string)$httpRequest->getBody(),'AuthenticateOnUnsubscribe=true');return new \GuzzleHttp\Promise\FulfilledPromise(new \Aws\Result(['SubscriptionArn'=>'fixture']));});$snsClient->confirmSubscription($request);$expect($signedRequest,'Protected SNS confirmation carries an AWS SigV4 signature');
$command=new \Aws\Command('SendEmail');foreach([[429,'TooManyRequestsException','throttled'],[403,'AccessDeniedException','permission'],[403,'AccessDenied','permission'],[403,'ExpiredTokenException','setup'],[400,'MailFromDomainNotVerifiedException','setup'],[400,'MessageRejected','rejected'],[500,'InternalFailure','uncertain']]as[$status,$code,$expected]){$e=new \Aws\Exception\AwsException('SECRET must not be logged',$command,['code'=>$code,'response'=>new \GuzzleHttp\Psr7\Response($status)]);$expect(audienceSesFailure($e)===$expected,'API failure classification '.$code);}$expect(audienceSesFailure(new RuntimeException('connection reset'))==='uncertain','Unknown API outcome never replays');
$credentialError=new \Aws\Exception\AwsException('SECRET must not be emailed',$command,['code'=>'ExpiredTokenException','response'=>new \GuzzleHttp\Psr7\Response(403)]);
$temporaryError=new \Aws\Exception\AwsException('SECRET must not be emailed',$command,['code'=>'InternalFailure','response'=>new \GuzzleHttp\Psr7\Response(503)]);
$expect(str_contains(audienceSesVerificationError($credentialError),'credentials')&&!str_contains(audienceSesVerificationError($credentialError),'SECRET'),'Credential failure is actionable without leaking AWS diagnostics');
$expect(str_contains(audienceSesVerificationError($temporaryError),'retry')&&!str_contains(audienceSesVerificationError($temporaryError),'SECRET'),'Temporary SES outage is identified for automatic retry');
$diagnosticRequest=new \GuzzleHttp\Psr7\Request('GET','https://email.us-east-2.amazonaws.com/v2/email/account?X-Amz-Credential=SECRET',['Authorization'=>'SECRET'],'SECRET');
$diagnosticError=new \Aws\Exception\AwsException('SECRET signed request',new \Aws\Command('GetAccount'),[
    'code'=>'InternalFailure','request_id'=>'aws-request-fixture','request'=>$diagnosticRequest,
    'response'=>new \GuzzleHttp\Psr7\Response(503,['X-Amzn-RequestId'=>'aws-request-fixture'],'SECRET'),
    'transfer_stats'=>['total_time'=>1.25,'namelookup_time'=>0.03,'ssl_verify_result'=>0,'url'=>'SECRET','request_header'=>'SECRET'],
]);
$diagnostics=audienceSesErrorDiagnostics($diagnosticError);
$expect($diagnostics['operation']==='GetAccount'&&$diagnostics['http_status']===503&&$diagnostics['aws_code']==='InternalFailure'
    &&$diagnostics['aws_request_id']==='aws-request-fixture'&&$diagnostics['endpoint_host']==='email.us-east-2.amazonaws.com',
    'SES diagnostics identify the failing AWS operation, response, request and host');
$expect($diagnostics['total_time']===1.25&&$diagnostics['namelookup_time']===0.03&&$diagnostics['ssl_verify_result']===0,
    'SES diagnostics retain numeric transfer and TLS details');
$expect(!str_contains(json_encode($diagnostics,JSON_THROW_ON_ERROR),'SECRET'),'Signed query strings, authorization, bodies and raw provider messages are excluded from diagnostics');
foreach ([6,7,28,35,60] as $errno) {
    $transportError=new \GuzzleHttp\Exception\ConnectException('cURL error '.$errno.': SECRET connection detail',$diagnosticRequest);
    $networkError=new \Aws\Exception\AwsException('SECRET',new \Aws\Command('GetEmailIdentity'),
        ['connection_error'=>true,'request'=>$diagnosticRequest],$transportError);
    $diagnostics=audienceSesErrorDiagnostics($networkError);
    $expect($diagnostics['curl_errno']===$errno&&$diagnostics['transport_error']===curl_strerror($errno)
        &&$diagnostics['connection_error']===true&&$diagnostics['http_status']===null&&!str_contains(json_encode($diagnostics,JSON_THROW_ON_ERROR),'SECRET'),
        'DNS, connection, timeout and TLS errors remain identifiable without a response or secret material: '.$errno);
}
$expect(audienceSesErrorDiagnostics(new RuntimeException('SECRET'))===['exception'=>RuntimeException::class],
    'Non-AWS failures expose their type without raw exception text');
$client=audienceSesClient('ses',$s,['key'=>'AKIA'.str_repeat('A',16),'secret'=>str_repeat('b',40)]);$calls=0;$client->getHandlerList()->setHandler(static function($command,$request)use(&$calls){$calls++;return new \GuzzleHttp\Promise\RejectedPromise(new \Aws\Exception\AwsException('ambiguous',$command,['response'=>new \GuzzleHttp\Psr7\Response(500)]));});try{$client->getAccount([]);}catch(Throwable){}$expect($calls===1,'SDK does not replay ambiguous API requests');$shape=$client->getApi()->getOperation('SendEmail')->getInput()->getMembers();$expect(isset($shape['ConfigurationOverrides']),'SDK supports per-message tracking overrides');
$client->getHandlerList()->setHandler(static function($command,$request){return new \GuzzleHttp\Promise\FulfilledPromise(new \Aws\Result(['MessageId'=>'fixture']));});
$result=$client->sendEmail(['FromEmailAddress'=>'sender@example.org','Destination'=>['ToAddresses'=>['recipient@example.test']],'ConfigurationSetName'=>'message-monkey','Content'=>['Raw'=>['Data'=>"From: sender@example.org\r\nTo: recipient@example.test\r\nSubject: Test\r\n\r\nFixture"]],'EmailTags'=>[['Name'=>'moed-receipt','Value'=>str_repeat('a',64)]],'ConfigurationOverrides'=>['Tracking'=>['OpenTrackingEnabled'=>'DISABLED','ClickTrackingEnabled'=>'DISABLED']]]);$expect($result['MessageId']==='fixture','Actual SDK validates the raw API request and tracking overrides');
echo "SES validation, real SNS signatures and API semantics: $checks checks passed.\n";
