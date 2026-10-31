<?php
declare(strict_types=1);
require_once __DIR__.'/audience_ses.php';
require_once __DIR__.'/email_helpers.php';

/** Safe, actionable delivery errors; never expose the provider's raw diagnostic. */
final class AudienceSesMessageException extends DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct(match($reason){
            'permission'=>'AWS denied permission to send this email. Review the SES IAM policy, including recipient, sender and configuration-set restrictions.',
            default=>'SES rejected this email. Check the sender, recipient and message content.',
        });
    }
}

/** Only documented rejections are safe to retry; network/5xx outcomes remain uncertain. */
function audienceSesFailure(Throwable $e):string
{
    if(!$e instanceof \Aws\Exception\AwsException)return 'uncertain';
    $code=$e->getAwsErrorCode();$status=$e->getStatusCode();
    if($status===429||in_array($code,['TooManyRequestsException','Throttling','ThrottlingException','LimitExceededException'],true))return 'throttled';
    if(in_array($code,['AccessDeniedException','AccessDenied'],true))return 'permission';
    if(in_array($code,['AccountSuspendedException','SendingPausedException','MailFromDomainNotVerifiedException','NotFoundException','UnrecognizedClientException','ExpiredTokenException','ExpiredToken','InvalidClientTokenId','SignatureDoesNotMatch','InvalidSignatureException'],true))return 'setup';
    return $status!==null&&$status>=400&&$status<500?'rejected':'uncertain';
}
function audienceSesWorkerReady(mysqli $db,int $audience,string $from,?array $binding=null,?callable $factory=null,?callable $resolve=null):array
{
    if(!audienceSesInstalled($db))throw new DomainException('Install and configure SES before sending audience email.');
    $s=$db->execute_query('SELECT * FROM marketing_ses_settings WHERE audience_id=?',[$audience])->fetch_assoc();
    if($s&&$s['enabled']&&$s['check_state']==='ready'&&strtotime(($s['checked_at']??'1970-01-01').' UTC')<time()-900){audienceSesCheck($db,$audience,$factory,$resolve);$s=$db->execute_query('SELECT * FROM marketing_ses_settings WHERE audience_id=?',[$audience])->fetch_assoc();}
    if(!$s||audienceSesReasons($s,$from)||($binding!==null&&audienceSesBinding($s)!==audienceSesBinding($binding)))throw new DomainException('Review the SES sender, connection and delivery events before sending.');
    return $s;
}
function audienceSesDeferred(string $reason,int $seconds=60):never{throw new MailBudgetDeferredException(gmdate('Y-m-d H:i:s',time()+$seconds),$reason);}
/** Serialize quota reservations and API sends for all marketing lanes in this account. */
function audienceSesDeliver(mysqli $db,array $m,string $lane='campaign',?callable $factory=null):string
{
    $lock='mm-ses-1';if((int)$db->execute_query('SELECT GET_LOCK(?,0) acquired',[$lock])->fetch_assoc()['acquired']!==1)audienceSesDeferred('SES delivery is busy.',2);
    try{
        $s=audienceSesWorkerReady($db,1,$m['from_email'],$m['ses_binding']??null,$factory);$factory??='audienceSesClient';try{$credentials=audienceSesCredentials($db,1);}catch(Throwable){$db->query("UPDATE marketing_ses_settings SET check_state='attention',error_message='The saved SES credentials are unavailable. Save the account credentials again.' WHERE audience_id=1");audienceSesDeferred('SES credentials need attention.',300);}$client=$factory('ses',$s,$credentials);
        if($s['blocked_until']&&strtotime($s['blocked_until'].' UTC')>time())throw new MailBudgetDeferredException($s['blocked_until'],'Waiting for the SES sending allowance.');
        if(!$s['quota_checked_at']||strtotime($s['quota_checked_at'].' UTC')<time()-60){
            try{$a=$client->getAccount([]);}catch(Throwable){audienceSesDeferred('Unable to refresh the SES quota. No send attempted.');}
            $q=$a['SendQuota']??[];
            $db->execute_query('UPDATE marketing_ses_settings SET sending_enabled=?,production_access=?,max_daily=?,max_rate=?,sent_last_day=?,quota_checked_at=UTC_TIMESTAMP(6) WHERE audience_id=1 AND generation=?',[!empty($a['SendingEnabled'])?1:0,!empty($a['ProductionAccessEnabled'])?1:0,(float)($q['Max24HourSend']??0),(float)($q['MaxSendRate']??0),(float)($q['SentLast24Hours']??0),$s['generation']]);
            $s=$db->query('SELECT * FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc();if(audienceSesReasons($s,$m['from_email'])){$db->query("UPDATE marketing_ses_settings SET check_state='attention',error_message='SES sending is disabled or its sending quota is unavailable. Review the AWS account.' WHERE audience_id=1");audienceSesDeferred('SES sending is unavailable. Review Email delivery.',300);}
        }
        $used=(int)$db->execute_query('SELECT COUNT(*) n FROM marketing_ses_usage WHERE audience_id=1 AND aws_account_id=? AND region=? AND created_at>=?',[$s['aws_account_id'],$s['region'],$s['quota_checked_at']])->fetch_assoc()['n'];
        if($s['sent_last_day']+$used>=$s['max_daily'])audienceSesDeferred('Waiting for the SES rolling 24-hour recipient allowance.',60);
        $interval=1/max(0.001,(float)$s['max_rate']);$last=$s['last_permit_at']?strtotime($s['last_permit_at'].' UTC')+(float)('0.'.(explode('.',$s['last_permit_at'])[1]??'0')):0;
        $wait=$last+$interval-microtime(true);if($wait>0&&$wait<=1)usleep((int)ceil($wait*1000000));elseif($wait>1)audienceSesDeferred('Waiting for the SES sending rate.',max(1,(int)ceil($wait)));
        $correlation=bin2hex(random_bytes(32));
        $headers=empty($m['unsubscribe'])?[]:['List-Unsubscribe'=>'<'.$m['unsubscribe'].'>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'];
        if(!empty($m['in_reply_to']))$headers['In-Reply-To']=$m['in_reply_to'];
        if(!empty($m['references']))$headers['References']=implode(' ',$m['references']);
        $raw=smtpMessageData($m['from_email'],$m['from_name'],$m['email'],$m['subject'],$m['text'],$m['reply_to']??'',$m['html']??null,null,$m['message_id'],$m['attachments']??[],$headers,$m['to_name']??'');
        // smtpMessageData enforces the encoded-message limit, including MIME overhead.
        // A lower raw limit would reject content accepted by the 10 MB design limit.
        if($lane==='campaign'){
            $db->execute_query("UPDATE marketing_campaign_recipients SET provider='ses',provider_account_id=?,provider_region=?,ses_correlation=? WHERE audience_id=1 AND id=? AND claim_token=? AND state='processing'",[$s['aws_account_id'],$s['region'],$correlation,$m['native_recipient_id'],$m['claim_token']]);if($db->affected_rows!==1)throw new DomainException('The delivery claim changed.');
        }
        if(in_array($lane,['test','inbox','inbox-alert','audience-alert','subscription-notice'],true))$db->execute_query('UPDATE marketing_service_mail SET ses_correlation=?,provider_account_id=?,provider_region=?,recipient_hash=? WHERE audience_id=1 AND id=? AND claim_token=?',[$correlation,$s['aws_account_id'],$s['region'],audienceDispatchEmailHash($m['email']),$m['native_service_id'],$m['claim_token']]);
        if($lane!=='campaign'){$claim=$db->execute_query("SELECT id FROM marketing_service_mail WHERE audience_id=1 AND id=? AND claim_token=? AND state='processing'",[$m['native_service_id'],$m['claim_token']])->fetch_assoc();if(!$claim)throw new DomainException('The service delivery claim changed.');}
        $current=$db->query('SELECT enabled,generation FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc();if(!$current['enabled']||$current['generation']!==$s['generation'])audienceSesDeferred('The SES connection changed. Review delivery setup.',300);
        $db->execute_query('INSERT INTO marketing_ses_usage(audience_id,aws_account_id,region,lane) VALUES (1,?,?,?)',[$s['aws_account_id'],$s['region'],$lane]);
        $db->query('UPDATE marketing_ses_settings SET last_permit_at=UTC_TIMESTAMP(6) WHERE audience_id=1');
        try{$r=$client->sendEmail([
            'FromEmailAddress'=>$m['from_email'],'Destination'=>['ToAddresses'=>[$m['email']]],'ConfigurationSetName'=>$s['configuration_set'],'Content'=>['Raw'=>['Data'=>$raw]],
            'EmailTags'=>[['Name'=>'moed-receipt','Value'=>$correlation],['Name'=>'moed-account','Value'=>hash('sha256',currentAccountKey())]],
            // MessageMonkey signs and measures its own links/pixel, avoiding duplicate rewrites.
            'ConfigurationOverrides'=>['Tracking'=>['OpenTrackingEnabled'=>'DISABLED','ClickTrackingEnabled'=>'DISABLED']],
        ]);}catch(Throwable $e){
            $failure=audienceSesFailure($e);
            if($failure==='throttled'){$db->query('UPDATE marketing_ses_settings SET blocked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),quota_checked_at=NULL WHERE audience_id=1');audienceSesDeferred('SES throttled this request. Waiting before retrying.');}
            // IAM conditions can reject one recipient while allowing others. Do not invalidate the connection.
            if($failure==='permission')throw new AudienceSesMessageException('permission');
            if($failure==='setup'){$db->query("UPDATE marketing_ses_settings SET check_state='attention',error_message='SES rejected the connection or sender. Check the connection before resuming.' WHERE audience_id=1");audienceSesDeferred('SES connection needs attention.',300);}
            if($failure==='rejected')throw new AudienceSesMessageException('rejected');
            throw new RuntimeException('SES acceptance could not be confirmed.');
        }
        $id=(string)($r['MessageId']??'');if($id===''||strlen($id)>256)throw new RuntimeException('SES returned no usable receipt.');
        if($lane==='campaign')$db->execute_query("UPDATE marketing_campaign_recipients SET provider_message_id=? WHERE audience_id=1 AND id=? AND claim_token=? AND (provider_message_id IS NULL OR provider_message_id=?)",[$id,$m['native_recipient_id'],$m['claim_token'],$id]);
        else $db->execute_query('UPDATE marketing_service_mail SET provider_message_id=? WHERE audience_id=1 AND id=? AND claim_token=?',[$id,$m['native_service_id'],$m['claim_token']]);
        if($db->affected_rows!==1){$table=$lane==='campaign'?'marketing_campaign_recipients':'marketing_service_mail';$native=$m[$lane==='campaign'?'native_recipient_id':'native_service_id'];$stored=$db->execute_query("SELECT provider_message_id FROM $table WHERE audience_id=1 AND id=?",[$native])->fetch_assoc();if(($stored['provider_message_id']??null)!==$id)throw new RuntimeException('Unable to retain the SES receipt.');}
        return 'accepted';
    }finally{$db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
