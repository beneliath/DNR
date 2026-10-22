<?php
declare(strict_types=1);
require_once __DIR__.'/audience_ses_notifications.php';

/** The web tier has no internet access and cannot read queued envelopes. */
function audienceSnsEnqueue(AudienceStore $store,array $data):void
{
    $settings=$store->sesSettings();
    audienceSnsEnvelope($data,$settings);
    $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if(strlen($json)>262144)throw new InvalidArgumentException('SNS envelope is too large.');
    $lock='mm-sns-inbox-'.$store->audienceId;
    if((int)$store->one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']!==1)throw new RuntimeException('SNS inbox is busy.');
    try{
        $pending=(int)$store->one("SELECT COUNT(*) n FROM marketing_ses_inbox WHERE audience_id=? AND state IN ('queued','processing')",[$store->audienceId])['n'];
        if($pending>=10000)throw new RuntimeException('SNS inbox is full.');
        $sealed=\Dnr\Security\ApplicationKey::seal(json_encode(['account'=>currentAccountKey(),'generation'=>$settings['generation'],'envelope'=>$data],JSON_THROW_ON_ERROR));
        $store->write('INSERT IGNORE INTO marketing_ses_inbox(audience_id,generation,payload_hash,payload_ciphertext) VALUES (?,?,?,?)',[$store->audienceId,$settings['generation'],hash('sha256',$json),$sealed]);
    }finally{$store->one('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Verify and apply durable receipts before the worker considers campaign sends. */
function processAudienceSnsInbox(mysqli $db,int $limit=25,?callable $fetch=null,?callable $confirm=null):int
{
    if((function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())||!audienceSesInstalled($db))return 0;
    $lock='mm-sns-process-1';
    if((int)$db->execute_query('SELECT GET_LOCK(?,0) acquired',[$lock])->fetch_assoc()['acquired']!==1)return 0;
    $processed=0;$deadline=microtime(true)+8;$certificates=[];$transport=$fetch??'audienceSnsFetch';
    // Many receipts share one AWS certificate. Keep a bounded cache for this pass.
    $cachedFetch=static function(string $url)use(&$certificates,$transport):string{
        if(!isset($certificates[$url]))$certificates[$url]=$transport($url);
        return $certificates[$url];
    };
    try{
        $store=new AudienceStore($db,1,0,'Amazon SES');
        $db->query("DELETE FROM marketing_ses_inbox WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND state IN ('done','rejected','failed')");
        $db->query("UPDATE marketing_ses_inbox SET state='queued',claim_token=NULL WHERE state='processing' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)");
        $db->query("UPDATE marketing_ses_inbox SET state='failed',payload_ciphertext='',claim_token=NULL,error_message='Event verification expired. Review Email delivery.' WHERE state IN ('queued','processing') AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 DAY)");
        for($index=0;$index<max(1,min(100,$limit))&&microtime(true)<$deadline;$index++){
            $row=$db->query("SELECT * FROM marketing_ses_inbox WHERE audience_id=1 AND state='queued' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1")->fetch_assoc();
            if(!$row)break;
            $claim=bin2hex(random_bytes(16));
            $db->execute_query("UPDATE marketing_ses_inbox SET state='processing',claim_token=?,started_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=? AND state='queued'",[$claim,$row['id']]);
            if($db->affected_rows!==1)continue;
            $state='done';$error='';$retry=gmdate('Y-m-d H:i:s');
            try{
                $settings=$store->sesSettings();
                if(!$settings['enabled']||!hash_equals($settings['generation'],$row['generation']))throw new InvalidArgumentException('Connection changed.');
                $value=json_decode(\Dnr\Security\ApplicationKey::open($row['payload_ciphertext']),true,40,JSON_THROW_ON_ERROR);
                if(!hash_equals(currentAccountKey(),$value['account']??'')||!hash_equals($settings['generation'],$value['generation']??''))throw new InvalidArgumentException('Envelope scope changed.');
                audienceSnsReceive($store,$value['envelope'],$cachedFetch,$confirm);
            }catch(InvalidArgumentException|JsonException|\Aws\Sns\Exception\InvalidSnsMessageException){
                $state='rejected';$error='Notification could not be authenticated.';
            }catch(Throwable){
                $state=(int)$row['attempts']>=19?'failed':'queued';
                $error='Unable to verify delivery events. Check SNS connectivity and ConfirmSubscription permission.';
                $retry=gmdate('Y-m-d H:i:s',time()+min(3600,30*(2**min(7,(int)$row['attempts']))));
            }
            $db->execute_query("UPDATE marketing_ses_inbox SET state=?,error_message=?,next_attempt_at=?,payload_ciphertext=IF(?='queued',payload_ciphertext,''),claim_token=NULL WHERE id=? AND claim_token=?",[$state,$error,$retry,$state,$row['id'],$claim]);
            $processed++;
        }
        return $processed;
    }finally{$db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
