<?php
declare(strict_types=1);
// Included by the disposable mail-role suite after it drains the five SNS
// fixtures. Reuse a drained row so these checks need no additional DB grants.
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
$securityRows=array_map('intval',array_column(array_slice($queued,0,3),'id'));
$securityRow=$securityRows[0];$securityChecks=0;
$securityEnvelope=['Type'=>'Notification','MessageId'=>'security-forged',
    'TopicArn'=>$ses['topic_arn'],'Timestamp'=>gmdate('c'),'Message'=>'{}',
    'SignatureVersion'=>'2','Signature'=>base64_encode(str_repeat('x',256)),
    'SigningCertURL'=>'https://sns.'.$ses['region'].'.amazonaws.com/SimpleNotificationService-missing.pem'];
$securitySealed=\Dnr\Security\ApplicationKey::seal(json_encode([
    'account'=>currentAccountKey(),'generation'=>$ses['generation'],'envelope'=>$securityEnvelope,
],JSON_THROW_ON_ERROR));
try{
    foreach([301,403,404,410,408,429,500,503]as$httpStatus){
        $db->execute_query("UPDATE marketing_ses_inbox SET state='queued',payload_ciphertext=?,attempts=0,next_attempt_at=UTC_TIMESTAMP() WHERE id=?",[$securitySealed,$securityRow]);
        $client=new \GuzzleHttp\Client(['handler'=>\GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response($httpStatus)]))]);
        $fetch=static fn(string $url):string=>audienceSnsFetch($url,$client);
        if(processAudienceSnsInbox($db,1,$fetch)!==1)throw new RuntimeException('Security fixture was not processed.');
        $result=$db->execute_query('SELECT state,attempts,payload_ciphertext,next_attempt_at>UTC_TIMESTAMP() deferred FROM marketing_ses_inbox WHERE id=?',[$securityRow])->fetch_assoc();
        $permanent=in_array($httpStatus,[301,403,404,410],true);
        if($result['state']!==($permanent?'rejected':'queued')||(int)$result['attempts']!==1
            ||($permanent?$result['payload_ciphertext']!=='':($result['payload_ciphertext']===''||!(int)$result['deferred'])))
            throw new RuntimeException('Incorrect durable receipt handling for certificate HTTP '.$httpStatus);
        if(processAudienceSnsInbox($db,1,static function():never{throw new LogicException('Unexpected repeat fetch');})!==0)
            throw new RuntimeException('A rejected or deferred receipt was processed again.');
        $securityChecks+=2;
    }
    // A temporarily unavailable certificate must recover on the next eligible
    // pass. Signature verification still rejects the forged envelope then.
    $db->execute_query('UPDATE marketing_ses_inbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?',[$securityRow]);
    if(processAudienceSnsInbox($db,1,static fn():string=>$certificate)!==1)throw new RuntimeException('Transient receipt did not retry.');
    $result=$db->execute_query('SELECT state,attempts,payload_ciphertext FROM marketing_ses_inbox WHERE id=?',[$securityRow])->fetch_assoc();
    if($result['state']!=='rejected'||(int)$result['attempts']!==2||$result['payload_ciphertext']!=='')throw new RuntimeException('Retry bypassed signature verification or retained a forged payload.');
    $securityChecks++;
    foreach($securityRows as$rowId)$db->execute_query("UPDATE marketing_ses_inbox SET state='queued',payload_ciphertext=?,attempts=0,next_attempt_at=UTC_TIMESTAMP() WHERE id=?",[$securitySealed,$rowId]);
    $fetches=0;$client=new \GuzzleHttp\Client(['handler'=>new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(404)])]);
    $fetch=static function(string $url)use(&$fetches,$client):string{$fetches++;return audienceSnsFetch($url,$client);};
    if(processAudienceSnsInbox($db,3,$fetch)!==3||$fetches!==1)throw new RuntimeException('Repeated permanent certificate failures must share one fetch per pass.');
    $securityChecks++;
    foreach($securityRows as$rowId){
        $result=$db->execute_query('SELECT state,attempts,payload_ciphertext FROM marketing_ses_inbox WHERE id=?',[$rowId])->fetch_assoc();
        if($result['state']!=='rejected'||(int)$result['attempts']!==1||$result['payload_ciphertext']!=='')throw new RuntimeException('Cached certificate failure must retire every affected receipt.');
        $securityChecks++;
    }
}finally{
    foreach($securityRows as$rowId)$db->execute_query("UPDATE marketing_ses_inbox SET state='done',payload_ciphertext='',claim_token=NULL,error_message='' WHERE id=?",[$rowId]);
}
echo "SNS permanent failure retirement, transient retry and forged-signature rejection: $securityChecks checks passed.\n";
