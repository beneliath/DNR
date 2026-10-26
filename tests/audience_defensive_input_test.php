<?php
declare(strict_types=1);
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/audience_import.php';
require_once __DIR__.'/../src/audience_ses_notifications.php';
$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$reject=static function(callable $operation,string $why)use($expect):void{
    $rejected=false;try{$operation();}catch(InvalidArgumentException){$rejected=true;}
    $expect($rejected,$why);
};

// A small header must not exempt a later row from the documented column limit.
$reject(static fn()=>audienceParseImport("Email,Name\n".implode(',',array_fill(0,201,'x'))."\n"),'Every CSV row must respect the column limit');
// Bound structural complexity before native CSV parsing allocates cell arrays,
// including during delimiter detection and for separators inside quoted fields.
foreach([',',"\t",';']as$separator){
    $reject(static fn()=>audienceParseImport('Email,Name'."\n".str_repeat($separator,500001)."\n"),'Dense data rows are rejected before allocation');
    $reject(static fn()=>audienceParseImport(str_repeat($separator,500001)."\nvalue\n"),'Dense headers are rejected before delimiter detection');
}
$quoted="Email,Name,Notes\r\na@example.test,\"Rivéra, Avery\",\"One; two\nHe said \"\"hello\"\"\"\r\n";
$parsed=audienceParseImport($quoted);
$expect($parsed['rows']===[['a@example.test','Rivéra, Avery',"One; two\nHe said \"hello\""]],'Quoted delimiters, newlines and Unicode remain intact');
$parsed=audienceParseImport(implode(',',array_fill(0,200,'Header'))."\n".implode(',',array_fill(0,200,'value'))."\n");
$expect(count($parsed['rows'][0])===200,'The supported 200-column boundary still works');
$parsed=audienceParseImport("Email,Name\n".str_repeat("a@example.test,Avery\n",50000));
$expect(count($parsed['rows'])===50000,'Ordinary 50,000-row imports still work');

$settings=['enabled'=>1,'region'=>'us-east-1','topic_arn'=>'arn:aws:sns:us-east-1:123456789012:defense-fixture'];
$url='https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem';
$envelope=['Type'=>'Notification','MessageId'=>'defense-fixture','TopicArn'=>$settings['topic_arn'],'Timestamp'=>gmdate('c'),
    'Message'=>'{}','SignatureVersion'=>'2','SigningCertURL'=>$url,'Signature'=>base64_encode(str_repeat('x',256))];
foreach(['Type','MessageId','TopicArn','Timestamp','Message','SignatureVersion','SigningCertURL','Signature','Subject','Token','SubscribeURL','UnsubscribeURL']as$field){
    foreach([[],null,42]as$value)$reject(static fn()=>audienceSnsEnvelope(array_replace($envelope,[$field=>$value]),$settings),'Malformed SNS field is rejected before admission: '.$field);
}
foreach(['SigningCertURL'=>'SigningCertUrl','SubscribeURL'=>'SubscribeUrl','UnsubscribeURL'=>'UnsubscribeUrl']as$canonical=>$alias){
    $reject(static fn()=>audienceSnsEnvelope(array_replace($envelope,[$canonical=>$url,$alias=>$url]),$settings),'Duplicate SNS field spellings are rejected before admission');
}
foreach(['SubscriptionConfirmation','UnsubscribeConfirmation']as$type){
    $reject(static fn()=>audienceSnsEnvelope(array_replace($envelope,['Type'=>$type]),$settings),'Incomplete confirmation cannot occupy the queue');
    audienceSnsEnvelope(array_replace($envelope,['Type'=>$type,'Token'=>'fixture-token','SubscribeURL'=>$url]),$settings);$checks++;
    $reject(static fn()=>audienceSnsEnvelope(array_replace($envelope,['Type'=>$type,'Token'=>"bad\ntoken",'SubscribeURL'=>$url]),$settings),'Invalid confirmation token cannot occupy the queue');
}
$reject(static fn()=>audienceSnsEnvelope(array_replace($envelope,['Timestamp'=>'now']),$settings),'Relative timestamps cannot occupy the queue');
audienceSnsEnvelope($envelope,$settings);$checks++;
$lambda=$envelope;$lambda['SigningCertUrl']=$url;unset($lambda['SigningCertURL']);audienceSnsEnvelope($lambda,$settings);$checks++;
echo "MessageMonkey defensive input admission: $checks checks passed.\n";
