<?php
declare(strict_types=1);
/** Fetches only bounded AWS responses. Redirects and inherited proxy credentials are disabled. */
function audienceSnsFetch(string $url):string
{
    $client=new \GuzzleHttp\Client(['timeout'=>6,'connect_timeout'=>3,'allow_redirects'=>false,'proxy'=>'']);
    $r=$client->get($url,['stream'=>true]);if($r->getStatusCode()!==200)throw new RuntimeException('SNS fetch failed.');
    $body=$r->getBody();$out='';while(!$body->eof()&&strlen($out)<=65536)$out.=$body->read(8192);if(strlen($out)>65536)throw new RuntimeException('SNS response exceeded its limit.');return $out;
}
function audienceSnsHost(array $s):string{return 'sns.'.$s['region'].'.amazonaws.com';}
function audienceSnsCertificateUrl(string $url,array $s):bool
{
    $p=parse_url($url);return is_array($p)&&($p['scheme']??'')==='https'&&($p['host']??'')===audienceSnsHost($s)&&!array_intersect(['user','pass','port','query','fragment'],array_keys($p))&&preg_match('~^/SimpleNotificationService-[A-Za-z0-9_-]+\.pem$~D',$p['path']??'')===1;
}
function audienceSnsEnvelope(array $data,array $s):void
{
    if(empty($s['enabled'])||($data['TopicArn']??'')!==$s['topic_arn']||!in_array($data['Type']??'',['Notification','SubscriptionConfirmation','UnsubscribeConfirmation'],true)||!in_array($data['SignatureVersion']??'',['1','2'],true)||!preg_match('/^[A-Za-z0-9-]{1,100}$/D',$data['MessageId']??''))throw new InvalidArgumentException('SNS scope is invalid.');
    $signature=base64_decode((string)($data['Signature']??''),true);if($signature===false||strlen($signature)<128||strlen($signature)>1024)throw new InvalidArgumentException('SNS signature is invalid.');
    $timestamp=strtotime((string)($data['Timestamp']??''));if(!$timestamp||$timestamp<time()-30*86400||$timestamp>time()+300)throw new InvalidArgumentException('SNS timestamp is outside the accepted window.');
    $cert=$data['SigningCertURL']??$data['SigningCertUrl']??'';if(!audienceSnsCertificateUrl($cert,$s))throw new InvalidArgumentException('SNS certificate location is invalid.');
    if(!is_string($data['Message']??null)||strlen($data['Message'])>262144)throw new InvalidArgumentException('SNS message is invalid.');
}
function audienceSnsValidate(array $data,array $s,?callable $fetch=null):\Aws\Sns\Message
{
    audienceSnsEnvelope($data,$s);$fetch??='audienceSnsFetch';$m=new \Aws\Sns\Message($data);(new \Aws\Sns\MessageValidator($fetch))->validate($m);return $m;
}
/** Confirm only after signature verification, using the worker's scoped credentials. */
function audienceSnsConfirmationRequest(\Aws\Sns\Message $m,array $s):array
{
    $token=(string)$m['Token'];if(strlen($token)>4096||!preg_match('/^[A-Za-z0-9+\/=_.-]+$/D',$token))throw new InvalidArgumentException('Invalid SNS confirmation token.');
    return ['TopicArn'=>$s['topic_arn'],'Token'=>$token,'AuthenticateOnUnsubscribe'=>'true'];
}
function audienceSnsConfirm(AudienceStore $store,\Aws\Sns\Message $m,array $s):string
{
    $client=audienceSesClient('sns',$s,audienceSesCredentials($store->db,$store->audienceId));
    $result=$client->confirmSubscription(audienceSnsConfirmationRequest($m,$s));
    return (string)($result['SubscriptionArn']??'');
}
/** Keep AWS subsecond ordering when comparing a receipt with renewed consent. */
function audienceSnsEventTime(string $value):string
{
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value))throw new InvalidArgumentException('Invalid SES event time.');
    try{$at=new DateTimeImmutable($value);}catch(Exception){throw new InvalidArgumentException('Invalid SES event time.');}
    $errors=DateTimeImmutable::getLastErrors();
    if(($errors&&($errors['warning_count']||$errors['error_count']))||$at->getTimestamp()<time()-30*86400||$at->getTimestamp()>time()+300)throw new InvalidArgumentException('Invalid SES event time.');
    return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}
function audienceSnsReceive(AudienceStore $store,array $data,?callable $fetch=null,?callable $confirm=null):string
{
    $s=$store->sesSettings();$m=audienceSnsValidate($data,$s,$fetch);$fetch??='audienceSnsFetch';
    if($m['Type']==='SubscriptionConfirmation'){
        $confirm??='audienceSnsConfirm';$arn=$confirm($store,$m,$s);
        if(!str_starts_with($arn,$s['topic_arn'].':')||!preg_match('/:[a-f0-9-]{36}$/D',$arn))throw new InvalidArgumentException('Invalid SNS subscription receipt.');
        $store->write('UPDATE marketing_ses_settings SET subscription_arn=?,subscription_event_at=?,last_event_at=UTC_TIMESTAMP() WHERE audience_id=? AND generation=? AND enabled=1 AND (subscription_event_at IS NULL OR subscription_event_at<=?)',[$arn,gmdate('Y-m-d H:i:s',strtotime($m['Timestamp'])),$store->audienceId,$s['generation'],gmdate('Y-m-d H:i:s',strtotime($m['Timestamp']))]);return 'confirmed';
    }
    if($m['Type']==='UnsubscribeConfirmation'){$store->write("UPDATE marketing_ses_settings SET subscription_arn='',subscription_event_at=? WHERE audience_id=? AND generation=? AND (subscription_event_at IS NULL OR subscription_event_at<=?)",[gmdate('Y-m-d H:i:s',strtotime($m['Timestamp'])),$store->audienceId,$s['generation'],gmdate('Y-m-d H:i:s',strtotime($m['Timestamp']))]);return 'disconnected';}
    $event=json_decode($m['Message'],true,32,JSON_THROW_ON_ERROR);$mail=$event['mail']??[];$tags=$mail['tags']??[];
    if(($mail['sendingAccountId']??'')!==$s['aws_account_id']||($tags['moed-account'][0]??'')!==hash('sha256',currentAccountKey()))return 'ignored';
    $correlation=$tags['moed-receipt'][0]??'';$id=$mail['messageId']??'';if(!is_string($correlation)||!preg_match('/^[a-f0-9]{64}$/D',$correlation)||!is_string($id)||$id===''||strlen($id)>256)return 'ignored';
    $kind=$event['eventType']??$event['notificationType']??'';
    $type=match($kind){'Send'=>'send','Delivery'=>'delivered','Bounce'=>($event['bounce']['bounceType']??'')==='Permanent'?'hard-bounce':'soft-bounce','Complaint'=>'complaint','DeliveryDelay'=>'delay','Reject','Rendering Failure','RenderingFailure'=>'rejected',default=>''};
    if($type==='')return 'ignored'; // Open/click events are measured by native signed links and pixel.
    $part=match($kind){'Delivery'=>'delivery','Bounce'=>'bounce','Complaint'=>'complaint','DeliveryDelay'=>'deliveryDelay','Reject'=>'reject',default=>'mail'};
    $at=audienceSnsEventTime((string)($event[$part]['timestamp']??$mail['timestamp']??''));
    return $store->transaction(function()use($store,$s,$m,$mail,$correlation,$id,$type,$at){
        $r=$store->one("SELECT * FROM marketing_campaign_recipients WHERE audience_id=? AND provider='ses' AND ses_correlation=? AND provider_account_id=? AND provider_region=? AND delivery_started_at IS NOT NULL FOR UPDATE",[$store->audienceId,$correlation,$s['aws_account_id'],$s['region']]);
        if(!$r){require_once __DIR__.'/audience_inbox_runtime.php';return audienceInboxProviderEvent($store,$s,$m,$mail,$correlation,$id,$type,$at);}
        if(!$r||count($mail['destination']??[])!==1||!hash_equals($r['email_hash'],audienceDispatchEmailHash((string)$mail['destination'][0]))||($r['provider_message_id']!==null&&$r['provider_message_id']!==$id))return 'ignored';
        $store->write('INSERT IGNORE INTO marketing_ses_notifications(audience_id,notification_id,event_type) VALUES (?,?,?)',[$store->audienceId,$m['MessageId'],$type]);if($store->db->affected_rows!==1)return 'duplicate';
        $store->write('UPDATE marketing_campaign_recipients SET provider_message_id=? WHERE audience_id=? AND id=?',[$id,$store->audienceId,$r['id']]);
        $store->recordDeliveryEvent((int)$r['id'],$type,hash('sha256','ses:'.$m['MessageId']),'provider',$at,['provider'=>'ses','message_id'=>$id]);
        // A verified provider receipt can resolve an ambiguous API response, never replay it.
        $store->write("UPDATE marketing_campaign_recipients SET state='accepted',accepted_at=COALESCE(accepted_at,UTC_TIMESTAMP()),error_message='' WHERE audience_id=? AND id=? AND state='uncertain'",[$store->audienceId,$r['id']]);
        $store->write('UPDATE marketing_ses_settings SET last_event_at=UTC_TIMESTAMP() WHERE audience_id=? AND generation=?',[$store->audienceId,$s['generation']]);return 'accepted';
    });
}
