<?php
declare(strict_types=1);
require_once __DIR__.'/email_helpers.php';

/** Adapter for setup-only messages. It cannot accept campaign content or recipients. */
function audienceDeliverSetupMessage(string $kind,?array $message,?mysqli $budgetDb=null):array
{
    $transport=strtolower(trim((string)(getenv('DNR_MAIL_TRANSPORT')?:'disabled')));
    $from=strtolower(trim((string)(getenv('DNR_MAIL_FROM')?:'')));
    if(!in_array($transport,['smtp','log'],true)||!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Delivery configuration is incomplete.');
    $receipt=['transport'=>$transport,'from'=>$from,'tls'=>in_array(getenv('DNR_SMTP_ENCRYPTION')?:'starttls',['tls','starttls'],true),'ready'=>false];
    if($transport==='log')return $receipt;
    $session=new SmtpSession($budgetDb,'verification');
    try{
        if($kind==='verify'){
            if(!$message||!filter_var($message['email']??'',FILTER_VALIDATE_EMAIL)||!preg_match('/^[A-F0-9]{12}$/D',$message['code']??''))throw new InvalidArgumentException('Invalid sender verification payload.');
            $subject='Verify your MessageMonkey sending address';
            $body="Verify access to this email address for MOED MessageMonkey.\n\nVerification code: ".$message['code']."\n\nEnter this code in Email delivery → Verify a Domain. This code expires in 24 hours and can be used once. If you did not request this email, ignore it.\n\nVerifying this address does not subscribe it to marketing email.";
            $session->send($message['email'],$subject,$body);
        }elseif($kind!=='probe')throw new InvalidArgumentException('Invalid setup operation.');
        $receipt['ready']=true;return $receipt;
    }finally{$session->close();}
}

/** One bounded pass, also called by the existing mail worker. No automatic replay after DATA. */
function processAudienceDeliverySetup(mysqli $db,int $limit=5,?callable $deliver=null):int
{
    if(function_exists('audienceSesInstalled')&&audienceSesInstalled($db))return 0;
    $exists=$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_delivery_setup_jobs'")->fetch_assoc();if((int)$exists['n']!==1)return 0;
    $deliver??=static fn($kind,$message)=>audienceDeliverSetupMessage($kind,$message,$db);$count=0;$readyClause=audienceMailPolicyAvailable($db)?' AND next_attempt_at<=UTC_TIMESTAMP()':'';
    $db->query("UPDATE marketing_delivery_setup_jobs SET state=IF(delivery_started_at IS NULL,'failed','uncertain'),error_message='The setup worker stopped. Request another verification only after checking delivery.',payload_ciphertext='' WHERE audience_id=1 AND state='processing' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)");
    $db->query("UPDATE marketing_delivery_setup_jobs SET state='failed',error_message='This request expired. Start a new verification.',payload_ciphertext='' WHERE audience_id=1 AND state='queued' AND expires_at<=UTC_TIMESTAMP()");
    $db->query("UPDATE marketing_delivery_setup_jobs SET payload_ciphertext='' WHERE audience_id=1 AND expires_at<=UTC_TIMESTAMP() AND payload_ciphertext<>''");
    for($index=0;$index<max(1,min(20,$limit));$index++){
        $db->begin_transaction();
        try{
            $job=$db->query("SELECT * FROM marketing_delivery_setup_jobs WHERE state='queued' AND audience_id=1 AND expires_at>UTC_TIMESTAMP() $readyClause ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch_assoc();
            if(!$job){$db->commit();break;}$token=bin2hex(random_bytes(16));
            $db->execute_query("UPDATE marketing_delivery_setup_jobs SET state='processing',claim_token=?,started_at=UTC_TIMESTAMP() WHERE id=?",[$token,$job['id']]);$db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        $accepted=false;
        try{
            if($job['job_type']==='verify'){
                $sender=$db->execute_query('SELECT s.id FROM marketing_sending_addresses s JOIN marketing_sending_domains d ON d.id=s.domain_id AND d.audience_id=s.audience_id WHERE s.audience_id=? AND s.id=? AND s.generation=? AND s.code_hash IS NOT NULL AND s.deleted_at IS NULL AND d.deleted_at IS NULL',[$job['audience_id'],$job['sender_id'],$job['generation']])->fetch_assoc();
                if(!$sender){$db->execute_query("UPDATE marketing_delivery_setup_jobs SET state='cancelled',payload_ciphertext='' WHERE id=? AND claim_token=?",[$job['id'],$token]);$count++;continue;}
                $message=json_decode(\Dnr\Security\ApplicationKey::open($job['payload_ciphertext']),true,8,JSON_THROW_ON_ERROR);
            }else $message=null;
            $db->execute_query("UPDATE marketing_delivery_setup_jobs SET delivery_started_at=UTC_TIMESTAMP() WHERE id=? AND claim_token=? AND state='processing'",[$job['id'],$token]);if($db->affected_rows!==1)throw new RuntimeException('The setup claim expired.');
            $receipt=$deliver($job['job_type'],$message);$accepted=true;
            if(!is_array($receipt)||!in_array($receipt['transport']??'', ['smtp','log'],true)||!filter_var($receipt['from']??'',FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid relay result.');
            $safe=['transport'=>$receipt['transport'],'from'=>$receipt['from'],'tls'=>!empty($receipt['tls']),'ready'=>$receipt['transport']==='smtp'&&!empty($receipt['ready'])];
            $state=$safe['transport']==='smtp'?'accepted':'development';
            $db->execute_query("UPDATE marketing_delivery_setup_jobs SET state=?,result_json=?,payload_ciphertext=IF(?='development',payload_ciphertext,''),error_message='' WHERE id=? AND claim_token=? AND state='processing'",[$state,json_encode($safe,JSON_THROW_ON_ERROR),$state,$job['id'],$token]);$completed=(int)$db->query('SELECT ROW_COUNT() n')->fetch_assoc()['n'];if($completed!==1)throw new RuntimeException('Relay result could not be recorded.');
        }catch(MailBudgetDeferredException $e){$db->execute_query("UPDATE marketing_delivery_setup_jobs SET state='queued',delivery_started_at=NULL,next_attempt_at=?,error_message=?,claim_token=NULL WHERE id=? AND claim_token=?",[$e->retryAt,$e->getMessage(),$job['id'],$token]);
        }catch(Throwable $e){
            $uncertain=$accepted||$e instanceof SmtpUncertainDeliveryException;
            $error=$uncertain?'Delivery may have succeeded. Check relay records before requesting another verification.':'The relay could not complete this request. Check SMTP configuration and request a new verification.';
            $db->execute_query("UPDATE marketing_delivery_setup_jobs SET state=?,error_message=?,payload_ciphertext='' WHERE id=? AND claim_token=? AND state='processing'",[$uncertain?'uncertain':'failed',$error,$job['id'],$token]);
            if(function_exists('applicationLog'))applicationLog('error','MessageMonkey setup request failed',['job_id'=>(int)$job['id'],'exception'=>get_class($e)]);
        }
        $count++;
    }
    return $count;
}
