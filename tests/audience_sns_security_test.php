<?php
declare(strict_types=1);
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/audience_ses_notifications.php';

$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$settings=['enabled'=>1,'region'=>'us-east-1','topic_arn'=>'arn:aws:sns:us-east-1:123456789012:security-fixture'];
$url='https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem';
$envelope=['Type'=>'Notification','MessageId'=>'security-fixture','TopicArn'=>$settings['topic_arn'],
    'Timestamp'=>gmdate('c'),'Message'=>'{}','SignatureVersion'=>'2','SigningCertURL'=>$url,
    'Signature'=>base64_encode(str_repeat('x',256))];

// The SDK canonicalizes Lambda aliases. The application must check exactly the
// value the SDK will fetch, including on old receipts already in the queue.
foreach([
    'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-fixture.pem',
    'https://sns.us-east-1.amazonaws.com:444/other.pem',
    'https://sns.us-east-1.amazonaws.com/other?query=.pem',
]as$alternate){
    $fetched=[];$rejected=false;
    try{audienceSnsValidate($envelope+['SigningCertUrl'=>$alternate],$settings,
        static function(string $requested)use(&$fetched):string{$fetched[]=$requested;return '';});}
    catch(InvalidArgumentException|\Aws\Sns\Exception\InvalidSnsMessageException){$rejected=true;}
    $expect($rejected&&$fetched===[],'Conflicting certificate aliases must be rejected before fetching '.$alternate);
}

// Both supported spellings must still verify real signatures. Duplicate
// spellings remain invalid even when they contain the same value.
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$csr=openssl_csr_new(['commonName'=>'Local security fixture'],$key);
$x509=openssl_csr_sign($csr,null,$key,1);openssl_x509_export($x509,$certificate);
foreach(['1','2']as$version){
    $signed=array_replace($envelope,['SignatureVersion'=>$version]);
    $canonical=(new \Aws\Sns\MessageValidator())->getStringToSign(new \Aws\Sns\Message($signed));
    openssl_sign($canonical,$signature,$key,$version==='1'?OPENSSL_ALGO_SHA1:OPENSSL_ALGO_SHA256);
    $signed['Signature']=base64_encode($signature);
    $lambda=$signed;$lambda['SigningCertUrl']=$url;unset($lambda['SigningCertURL']);
    foreach([$signed,$lambda]as$input){
        $seen=[];$message=audienceSnsValidate($input,$settings,
            static function(string $requested)use(&$seen,$certificate):string{$seen[]=$requested;return $certificate;});
        $expect($seen===[$url]&&$message['MessageId']==='security-fixture','Valid SNS signatures work with either certificate spelling');
    }
}
$fetched=false;$rejected=false;
try{audienceSnsValidate($signed+['SigningCertUrl'=>$url],$settings,
    static function()use(&$fetched,$certificate):string{$fetched=true;return $certificate;});}
catch(InvalidArgumentException){$rejected=true;}
$expect($rejected&&!$fetched,'Equal duplicate certificate aliases are rejected before fetching');

// Real Guzzle responses exercise HTTP error classification without internet.
foreach([301,302,400,401,403,404,410,422,408,425,429,500,502,503,504]as$status){
    $handler=new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response($status)]);
    $client=new \GuzzleHttp\Client(['handler'=>\GuzzleHttp\HandlerStack::create($handler)]);
    $permanent=false;$failed=false;
    try{audienceSnsFetch($url,$client);}catch(InvalidArgumentException){$permanent=true;$failed=true;}catch(Throwable){$failed=true;}
    $expectedPermanent=$status<500&&!in_array($status,[408,425,429],true);
    $expect($failed&&$permanent===$expectedPermanent,'Correct certificate-fetch retry classification for HTTP '.$status);
}
$client=new \GuzzleHttp\Client(['handler'=>new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200,[],$certificate)])]);
$expect(audienceSnsFetch($url,$client)===$certificate,'Successful certificate response is retained');
$client=new \GuzzleHttp\Client(['handler'=>new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200,[],str_repeat('x',65537))])]);
$rejected=false;try{audienceSnsFetch($url,$client);}catch(InvalidArgumentException){$rejected=true;}
$expect($rejected,'Oversized certificate response is permanently rejected');
$client=new \GuzzleHttp\Client(['handler'=>new \GuzzleHttp\Handler\MockHandler([
    new \GuzzleHttp\Exception\ConnectException('Fixture connection timeout',new \GuzzleHttp\Psr7\Request('GET',$url)),
])]);
$retry=false;try{audienceSnsFetch($url,$client);}catch(\GuzzleHttp\Exception\ConnectException){$retry=true;}
$expect($retry,'Connection failures remain eligible for a bounded retry');
echo "SNS certificate scope and retry security: $checks checks passed.\n";
