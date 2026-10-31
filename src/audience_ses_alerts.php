<?php
declare(strict_types=1);

require_once __DIR__.'/audience_ses.php';
require_once __DIR__.'/email_helpers.php';

/** One operational SMTP alert per verified administrator and SES incident. */
function queueAudienceSesFailureAlerts(mysqli $db):int
{
    if(!audienceSesInstalled($db))return 0;
    $db->begin_transaction();
    try{
        $settings=$db->query("SELECT audience_id,enabled,check_state,error_message,alert_incident FROM marketing_ses_settings WHERE audience_id=1 FOR UPDATE")->fetch_assoc();
        if(!$settings||!$settings['enabled']||$settings['check_state']!=='attention'){$db->commit();return 0;}
        $incident=$settings['alert_incident'];$newIncident=$incident==='';
        if($incident===''){
            $incident=bin2hex(random_bytes(16));
            $db->execute_query("UPDATE marketing_ses_settings SET alert_incident=? WHERE audience_id=? AND enabled=1 AND check_state='attention'",[$incident,$settings['audience_id']]);
            if($db->affected_rows!==1)throw new RuntimeException('The SES incident changed before alerts were queued.');
        }
        $reason=trim((string)$settings['error_message'])?:'The SES connection check needs attention.';
        $url=rtrim(accountPublicUrl((string)getenv('DNR_PUBLIC_BASE_URL')),'/').'/audience.php?view=delivery';
        $subject='MessageMonkey SES email delivery needs attention';
        $body='MessageMonkey SES email delivery needed attention at '.gmdate('Y-m-d H:i').' UTC.'
            ."\n\nReason: {$reason}\n\nReview the current status in Email delivery: {$url}"
            ."\n\nMOED will retry the SES connection check automatically.";
        $users=$db->query("SELECT id,email FROM users WHERE role='admin' AND account_status='active' AND email_verified_at IS NOT NULL AND email IS NOT NULL AND email<>'' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        if($newIncident&&$users===[]&&function_exists('applicationLog'))applicationLog('error','SES needs attention but no verified account administrator can receive email');
        $queued=0;
        foreach($users as$user){
            try{$recipient=normalizeAccountEmail($user['email']);}
            catch(InvalidArgumentException){
                if(function_exists('applicationLog'))applicationLog('error','SES alert administrator email is invalid',['user_id'=>(int)$user['id']]);
                continue;
            }
            $payload=\Dnr\Security\ApplicationKey::seal(json_encode(['recipient'=>$recipient,'subject'=>$subject,'body'=>$body],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $db->execute_query('INSERT IGNORE INTO marketing_ses_alert_outbox(audience_id,incident_key,user_id,recipient_hash,payload_ciphertext) VALUES (?,?,?,?,?)',[(int)$settings['audience_id'],$incident,(int)$user['id'],hash('sha256',$recipient,true),$payload]);
            $queued+=$db->affected_rows;
        }
        $db->commit();
        return $queued;
    }catch(Throwable $exception){$db->rollback();throw $exception;}
}

function maintainQueuedAudienceSesAlerts(mysqli $db,int $leaseSeconds=600):void
{
    $leaseSeconds=max(60,min(3600,$leaseSeconds));
    expireUncertainEmailDeliveries($db,'marketing_ses_alert_outbox',$leaseSeconds);
    $db->query("UPDATE marketing_ses_alert_outbox SET status='failed',payload_ciphertext=NULL,processing_started_at=NULL,last_error='Delivery attempts exhausted after the processing lease expired.' WHERE status='processing' AND attempts>=8 AND processing_started_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$leaseSeconds} SECOND)");
    $db->query("UPDATE marketing_ses_alert_outbox o JOIN users u ON u.id=o.user_id SET o.status='failed',o.payload_ciphertext=NULL,o.processing_started_at=NULL,o.last_error='Administrator email is no longer eligible.' WHERE o.status IN ('pending','retry') AND (u.role<>'admin' OR u.account_status<>'active' OR u.email_verified_at IS NULL OR u.email IS NULL OR o.recipient_hash<>UNHEX(SHA2(LOWER(TRIM(u.email)),256)))");
}

function claimQueuedAudienceSesAlert(mysqli $db,int $leaseSeconds=600):?array
{
    $leaseSeconds=max(60,min(3600,$leaseSeconds));
    $db->begin_transaction();
    try{
        $row=$db->query("SELECT o.id,o.payload_ciphertext FROM marketing_ses_alert_outbox o JOIN users u ON u.id=o.user_id WHERE o.attempts<8 AND o.payload_ciphertext IS NOT NULL AND u.role='admin' AND u.account_status='active' AND u.email_verified_at IS NOT NULL AND u.email IS NOT NULL AND o.recipient_hash=UNHEX(SHA2(LOWER(TRIM(u.email)),256)) AND ((o.status IN ('pending','retry') AND o.next_attempt_at<=UTC_TIMESTAMP()) OR (o.status='processing' AND o.delivery_started_at IS NULL AND o.processing_started_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$leaseSeconds} SECOND))) ORDER BY o.next_attempt_at,o.id LIMIT 1 FOR UPDATE OF o SKIP LOCKED")->fetch_assoc();
        if(!$row){$db->commit();return null;}
        $id=(int)$row['id'];
        $db->execute_query("UPDATE marketing_ses_alert_outbox SET status='processing',attempts=attempts+1,processing_started_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?",[$id]);
        $claim=stampEmailDeliveryClaim($db,'marketing_ses_alert_outbox',$id);
        $db->commit();
        return ['id'=>$id,'payload_ciphertext'=>$row['payload_ciphertext']]+$claim;
    }catch(Throwable $exception){$db->rollback();throw $exception;}
}

function audienceSesAlertMessage(string $ciphertext):array
{
    $message=json_decode(\Dnr\Security\ApplicationKey::open($ciphertext),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($message)||!is_string($message['recipient']??null)||!is_string($message['subject']??null)||!is_string($message['body']??null))throw new DomainException('The queued SES alert is invalid.');
    $message['recipient']=normalizeAccountEmail($message['recipient']);
    return $message;
}

function completeQueuedAudienceSesAlert(mysqli $db,int $id,string $claim):void
{
    $db->execute_query("UPDATE marketing_ses_alert_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),processing_started_at=NULL,payload_ciphertext=NULL,last_error=NULL WHERE id=? AND claim_token=? AND status='processing'",[$id,$claim]);
    if($db->affected_rows!==1)throw new RuntimeException('The queued SES alert can no longer be completed.');
}

function failQueuedAudienceSesAlert(mysqli $db,int $id,string $claim,Throwable $error,bool $permanent=false):void
{
    if(audienceDeferOperationalMail($db,'marketing_ses_alert_outbox',$id,$claim,$error))return;
    if($error instanceof SmtpUncertainDeliveryException){markEmailDeliveryUncertain($db,'marketing_ses_alert_outbox',$id,$claim);return;}
    $message=mb_substr($error->getMessage(),0,255,'UTF-8');
    $terminal=$permanent?1:0;
    $db->execute_query("UPDATE marketing_ses_alert_outbox SET status=IF(? OR attempts>=8,'failed','retry'),processing_started_at=NULL,delivery_started_at=NULL,last_error=?,payload_ciphertext=IF(? OR attempts>=8,NULL,payload_ciphertext),next_attempt_at=TIMESTAMPADD(MINUTE,LEAST(1440,CAST(POW(2,attempts) AS UNSIGNED)),UTC_TIMESTAMP()) WHERE id=? AND claim_token=? AND status='processing'",[$terminal,$message,$terminal,$id,$claim]);
}
