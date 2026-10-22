<?php
declare(strict_types=1);

final class MailBudgetDeferredException extends RuntimeException
{
    public function __construct(public readonly string $retryAt,string $reason){parent::__construct($reason);}
}

function audienceMailPolicyAvailable(mysqli $db):bool
{
    $exists=(int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_delivery_policy'")->fetch_assoc()['n']===1;
    return $exists&&(int)($db->query('SELECT runtime_version FROM marketing_delivery_policy WHERE audience_id=1')->fetch_assoc()['runtime_version']??0)===1;
}
function audienceMailPolicyInput(array $input):array
{
    $values=[];
    foreach(['hourly_budget'=>[1,1000000],'daily_budget'=>[1,1000000],'hourly_reserve'=>[0,1000000],'daily_reserve'=>[0,1000000],'interval_seconds'=>[1,3600]]as$key=>[$min,$max]){
        $value=filter_var($input[$key]??null,FILTER_VALIDATE_INT);if($value===false||$value<$min||$value>$max)throw new InvalidArgumentException('Enter valid delivery budgets, reserves and a sending interval.');$values[$key]=$value;
    }
    if($values['daily_budget']<$values['hourly_budget']||$values['hourly_reserve']>=$values['hourly_budget']||$values['daily_reserve']>=$values['daily_budget'])throw new InvalidArgumentException('The daily budget must cover the hourly budget, and each reserve must be smaller than its budget.');
    $values['bulk_approved']=($input['bulk_approved']??'')==='1'?1:0;return $values;
}
function audienceMailPolicyReady(array $policy):bool
{
    return !empty($policy['bulk_approved'])&&(int)$policy['hourly_budget']>0&&(int)$policy['daily_budget']>0;
}
/** Reservations count attempts conservatively; these are MOED allocations, not Proton remaining-quota claims. */
function audienceReserveMail(mysqli $db,string $messageId,string $lane='operational'):void
{
    if(!audienceMailPolicyAvailable($db))return;
    if(!in_array($lane,['operational','marketing','test','confirmation','verification'],true))throw new InvalidArgumentException('Invalid mail purpose.');
    $db->begin_transaction();
    try{
        $policy=$db->query('SELECT * FROM marketing_delivery_policy WHERE audience_id=1 FOR UPDATE')->fetch_assoc();
        if(!$policy)throw new RuntimeException('Mail allocation is unavailable.');
        $now=time();$retry=$now;$reason='';$marketing=in_array($lane,['marketing','test'],true);
        if($marketing&&!audienceMailPolicyReady($policy)){$retry=$now+3600;$reason='Enter the confirmed paid-account sending budget in Email delivery before dispatch.';}
        if($policy['blocked_until']&&strtotime($policy['blocked_until'].' UTC')>$retry){$retry=strtotime($policy['blocked_until'].' UTC');$reason='The relay has requested a sending pause.';}
        if((int)$policy['hourly_budget']>0&&(int)$policy['daily_budget']>0){
            $usage=$db->query("SELECT SUM(created_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)) hourly,COUNT(*) daily,MIN(created_at) oldest FROM marketing_relay_usage WHERE audience_id=1 AND created_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)")->fetch_assoc();
            $hourLimit=(int)$policy['hourly_budget']-($marketing?(int)$policy['hourly_reserve']:0);$dayLimit=(int)$policy['daily_budget']-($marketing?(int)$policy['daily_reserve']:0);
            if((int)$usage['hourly']>=$hourLimit){$oldest=$db->query("SELECT MIN(created_at) oldest FROM marketing_relay_usage WHERE audience_id=1 AND created_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 HOUR)")->fetch_assoc()['oldest'];$retry=max($retry,strtotime($oldest.' UTC')+3601);$reason='The MOED hourly recipient allocation is used. Delivery will resume as capacity becomes available.';}
            if((int)$usage['daily']>=$dayLimit){$retry=max($retry,strtotime($usage['oldest'].' UTC')+86401);$reason='The MOED daily recipient allocation is used. Delivery will resume as capacity becomes available.';}
            if($marketing&&$policy['last_permit_at']){$pace=strtotime($policy['last_permit_at'].' UTC')+(int)$policy['interval_seconds'];if($pace>$retry){$retry=$pace;$reason='Waiting for the configured sending interval.';}}
        }
        if($retry>$now){$db->commit();throw new MailBudgetDeferredException(gmdate('Y-m-d H:i:s',$retry),$reason);}
        $db->execute_query('INSERT INTO marketing_relay_usage(audience_id,message_id,lane) VALUES (1,?,?)',[$messageId,$lane]);
        $db->query('UPDATE marketing_delivery_policy SET last_permit_at=UTC_TIMESTAMP(6) WHERE audience_id=1');$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function audienceDeferOperationalMail(mysqli $db,string $table,int $id,?string $claim,Throwable $error):bool
{
    if(!$error instanceof MailBudgetDeferredException)return false;
    if(!in_array($table,['email_outbox','notification_outbox','engagement_email_deliveries','reimbursement_email_deliveries'],true))throw new InvalidArgumentException('Unknown outbound queue.');
    $db->execute_query("UPDATE $table SET status='retry',attempts=GREATEST(0,attempts-1),processing_started_at=NULL,delivery_started_at=NULL,next_attempt_at=?,last_error=?,claim_token=NULL WHERE id=? AND claim_token<=>? AND status='processing'",[$error->retryAt,$error->getMessage(),$id,$claim]);return true;
}
