<?php
// Signed callbacks for test recipients, including addresses outside the audience.
$testDeliveryStart=$checks;
$sourceTest=$campaignStore->one("SELECT payload_ciphertext FROM marketing_service_mail WHERE kind='test' ORDER BY id DESC LIMIT 1");
$unchangedContact=$campaignStore->person((int)$runtimeRows[4]['contact_id']);
$receiptTests=[];
foreach(['delivery-one@example.test','delivery-two@another.example.test',$unchangedContact['email']]as$index=>$email){
    $p=json_decode(\Dnr\Security\ApplicationKey::open($sourceTest['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR);
    $p['message']['email']=$email;$p['message']['message_id']='<receipt-test-'.bin2hex(random_bytes(20)).'@example.test>';
    $correlation=bin2hex(random_bytes(32));$provider='test-provider-'.bin2hex(random_bytes(8));
    $campaignStore->write("INSERT INTO marketing_service_mail(audience_id,kind,operation_key,payload_ciphertext,message_id,state,next_attempt_at,delivery_started_at,expires_at,ses_correlation,provider_account_id,provider_region,recipient_hash,provider_message_id) VALUES (1,'test',?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),?,?,?,?,?)",[bin2hex(random_bytes(32)),\Dnr\Security\ApplicationKey::seal(json_encode($p,JSON_THROW_ON_ERROR)),$p['message']['message_id'],$index===2?'uncertain':'accepted',$correlation,$ses['aws_account_id'],$ses['region'],audienceDispatchEmailHash($email),$index===2?null:$provider]);
    $receiptTests[]=['id'=>(int)$conn->insert_id,'email'=>$email,'provider'=>$provider,'correlation'=>$correlation];
}
$testEvent=static function(array $job,string $kind,string $at)use($ses):array{
    $event=['eventType'=>$kind,'mail'=>['sendingAccountId'=>$ses['aws_account_id'],'messageId'=>$job['provider'],'destination'=>[$job['email']],'timestamp'=>$at,'tags'=>['moed-receipt'=>[$job['correlation']],'moed-account'=>[hash('sha256',currentAccountKey())]]]];
    if($kind==='Bounce')$event['bounce']=['bounceType'=>'Permanent','timestamp'=>$at];
    return $event;
};
$testStatus=static function(int $id)use($campaignStore):array{$rows=array_column($campaignStore->serviceMailActivity(),null,'id');return $rows[$id];};
audienceHttpExpect($testStatus($receiptTests[0]['id'])['delivery_status']['label']==='Accepted','API acceptance alone does not claim delivery');
$testTime=gmdate('c',time()-10);$delivered=$testEvent($receiptTests[0],'Delivery',$testTime);
foreach(['recipient','provider','correlation','aws-account','moed-account']as$wrong){
    $invalid=$delivered;
    if($wrong==='recipient')$invalid['mail']['destination']=['different@example.test'];
    if($wrong==='provider')$invalid['mail']['messageId']='wrong-provider';
    if($wrong==='correlation')$invalid['mail']['tags']['moed-receipt']=[str_repeat('f',64)];
    if($wrong==='aws-account')$invalid['mail']['sendingAccountId']='999999999999';
    if($wrong==='moed-account')$invalid['mail']['tags']['moed-account']=[hash('sha256','other-account')];
    audienceHttpExpect(audienceSnsReceive($campaignStore,$signSes($invalid,'test-wrong-'.$wrong),$fetch)==='ignored','Test callback rejects mismatched '.$wrong);
}
$signedTest=$signSes($delivered,'test-delivered');$tampered=$signedTest;$tampered['Message']='{}';$blocked=false;
try{audienceSnsReceive($campaignStore,$tampered,$fetch);}catch(\Aws\Sns\Exception\InvalidSnsMessageException){$blocked=true;}
audienceHttpExpect($blocked&&!$testStatus($receiptTests[0]['id'])['delivery_events'],'Unauthenticated test callback cannot create delivery evidence');
audienceHttpExpect(audienceSnsReceive($campaignStore,$signedTest,$fetch)==='accepted'&&audienceSnsReceive($campaignStore,$signedTest,$fetch)==='duplicate','Signed test delivery is retained once');
audienceSnsReceive($campaignStore,$signSes($testEvent($receiptTests[0],'Send',gmdate('c')),'test-late-send'),$fetch);
audienceSnsReceive($campaignStore,$signSes($testEvent($receiptTests[0],'DeliveryDelay',gmdate('c',time()-20)),'test-late-delay'),$fetch);
audienceHttpExpect($testStatus($receiptTests[0]['id'])['delivery_status']['label']==='Delivered'&&count($testStatus($receiptTests[0]['id'])['delivery_events'])===3,'Out-of-order Send/Delay does not downgrade delivery and history is retained');
audienceSnsReceive($campaignStore,$signSes($testEvent($receiptTests[1],'Bounce',$testTime),'test-bounced'),$fetch);
audienceHttpExpect($testStatus($receiptTests[1]['id'])['delivery_status']['label']==='Bounced','A second unrelated recipient gets its own bounce outcome');
audienceSnsReceive($campaignStore,$signSes($testEvent($receiptTests[2],'Bounce',$testTime),'test-bounce-uncertain'),$fetch);
audienceHttpExpect($testStatus($receiptTests[2]['id'])['state']==='accepted'&&$testStatus($receiptTests[2]['id'])['delivery_status']['label']==='Bounced','A signed bounce resolves uncertain API acceptance without requeueing');
audienceHttpExpect($campaignStore->person((int)$unchangedContact['id'])['email_status']===$unchangedContact['email_status'],'Test receipts do not change audience subscription consent');
$activityPage=$admin('audience.php?view=delivery&tab=activity');
foreach([$receiptTests[0]['id']=>'Delivered',$receiptTests[1]['id']=>'Bounced']as$testId=>$label){
    preg_match('/<tr data-service-mail-id="'.$testId.'">(.*?)<\/tr>/s',$activityPage['body'],$activityRow);
    audienceHttpExpect($activityPage['status']===200&&str_contains($activityRow[1]??'','<td>'.$label.'</td>')&&str_contains($activityRow[1]??'','Delivery history'),'Activity renders the specific test outcome and receipt history');
}
audienceHttpExpect(str_contains($activityPage['body'],'delivery-one@example.test')&&str_contains($activityPage['body'],'delivery-two@another.example.test')&&!preg_match('/Warning:|Fatal error:/',$activityPage['body']),'Activity identifies each recipient without rendering errors');
audienceHttpExpect($editor('audience.php?view=delivery&tab=activity')['status']===403,'Delivery activity keeps administrator-only access');
echo 'Test delivery callbacks and Activity: '.($checks-$testDeliveryStart)." checks passed; no external mail.\n";
