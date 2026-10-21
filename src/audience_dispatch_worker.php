<?php
declare(strict_types=1);
require_once __DIR__.'/email_helpers.php';
require_once __DIR__.'/audience_design.php';
require_once __DIR__.'/audience_delivery_setup.php';
require_once __DIR__.'/audience_dispatch.php';
require_once __DIR__.'/audience_delivery_runtime.php';
require_once __DIR__.'/audience_delivery_lifecycle.php';

require_once __DIR__.'/audience_ses_worker.php';
require_once __DIR__.'/audience_flow_runtime.php';
function audienceDeliverCampaignMessage(array $message,?mysqli $budgetDb=null):string
{
    if(!$budgetDb)throw new DomainException('An account database is required for SES delivery.');
    return audienceSesDeliver($budgetDb,$message);
}

/** Dispatch-time authorization is independent of the web application's review receipt. */
function audienceDispatchWorkerReady(mysqli $db,int $audience,array $snapshot,?callable $resolver=null,bool $testOnly=false):void
{
    if(!$testOnly){$policy=$db->execute_query('SELECT * FROM marketing_delivery_policy WHERE audience_id=?',[$audience])->fetch_assoc();if(!$policy||!audienceMailPolicyReady($policy))throw new DomainException('Campaign sending awaits approval and a sending budget.');}
    if(!empty($snapshot['draft']['experiment'])||!empty($snapshot['draft']['design_variants']))throw new DomainException('A/B testing is not available in MessageMonkey.');
    if(audienceSesInstalled($db))audienceSesWorkerReady($db,$audience,$snapshot['draft']['from_email'],$snapshot['ses']??[],null,$resolver);
    else throw new DomainException('Configure this account’s SES connection before MessageMonkey delivery.');
    if(trim((string)($snapshot['identity']['LIST:ADDRESS']??''))==='')throw new DomainException('The mailing address is missing.');
    audienceDispatchBase($snapshot['base']);
}

/** Bounded, resumable pass. An ambiguous SMTP acceptance is never automatically replayed. */
function processAudienceCampaignDelivery(mysqli $db,int $limit=10,?callable $deliver=null,?callable $resolver=null):int
{
    if(function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())return 0;
    $exists=$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_campaign_deliveries'")->fetch_assoc();if((int)$exists['n']!==1)return 0;
    $deliver??=static fn($m)=>audienceDeliverCampaignMessage($m,$db);$processed=0;$runtime=audienceMailPolicyAvailable($db);if(!$runtime)return 0;processAudienceLifecycle($db);
    $db->query("UPDATE marketing_campaign_recipients SET state=IF(delivery_started_at IS NULL,IF(attempts<3,'queued','failed'),'uncertain'),error_message='The worker stopped before recording a final receipt.',claim_token=NULL WHERE audience_id=1 AND state='processing' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)");
    $flowClause=audienceFlowsInstalled($db)?" AND (flow_run_id=0 OR EXISTS(SELECT 1 FROM marketing_flow_runs fr JOIN marketing_flows f ON f.id=fr.flow_id AND f.audience_id=fr.audience_id WHERE fr.id=flow_run_id AND fr.audience_id=1 AND fr.state='active' AND f.state='running' AND f.deleted_at IS NULL AND NOT JSON_CONTAINS(f.paused_nodes_json,JSON_QUOTE(flow_node))))":'';
    for($i=0;$i<max(1,min(100,$limit));$i++){
        $job=$db->query("SELECT * FROM marketing_campaign_deliveries WHERE audience_id=1 AND state IN ('scheduled','sending') AND due_at<=UTC_TIMESTAMP() AND (EXISTS(SELECT 1 FROM marketing_campaign_recipients r WHERE r.delivery_id=marketing_campaign_deliveries.id AND r.state='queued' AND r.phase<>'hold' AND r.next_attempt_at<=UTC_TIMESTAMP()) OR NOT EXISTS(SELECT 1 FROM marketing_campaign_recipients r WHERE r.delivery_id=marketing_campaign_deliveries.id AND r.state IN ('queued','processing'))) ".$flowClause." ORDER BY due_at,id LIMIT 1")->fetch_assoc();if(!$job)break;
        $flowLock=null;$lock=audienceDispatchLock($db,1,(int)$job['campaign_id']);
        try{
            $job=$db->execute_query('SELECT * FROM marketing_campaign_deliveries WHERE audience_id=1 AND id=?',[$job['id']])->fetch_assoc();
            if(!in_array($job['state'],['scheduled','sending'],true))continue;
            if(!empty($job['flow_run_id'])){
                $flowRun=$db->execute_query('SELECT flow_id FROM marketing_flow_runs WHERE audience_id=1 AND id=?',[$job['flow_run_id']])->fetch_assoc();if(!$flowRun)continue;
                $flowLock='mm-flow-1-'.$flowRun['flow_id'];if((int)$db->execute_query('SELECT GET_LOCK(?,10) acquired',[$flowLock])->fetch_assoc()['acquired']!==1){$flowLock=null;continue;}
                if(!audienceFlowDeliveryAllowed($db,$job))continue;
            }
            $campaign=$db->execute_query('SELECT status,deleted_at FROM marketing_campaigns WHERE audience_id=1 AND id=?',[$job['campaign_id']])->fetch_assoc();
            try{
                if(empty($job['flow_run_id'])&&(!$campaign||$campaign['deleted_at']||!in_array($campaign['status'],['scheduled','sending'],true)))throw new DomainException('Campaign delivery is no longer active.');
                $snapshot=json_decode(\Dnr\Security\ApplicationKey::open($job['snapshot_ciphertext']),true,64,JSON_THROW_ON_ERROR);
                audienceDispatchWorkerReady($db,1,$snapshot,$resolver);
            }catch(Throwable){
                $db->execute_query("UPDATE marketing_campaign_deliveries SET state='paused',error_message='Sender, domain or campaign readiness needs attention. Review Email delivery setup before resuming.',version=version+1 WHERE id=?",[$job['id']]);
                if(empty($job['flow_run_id']))$db->execute_query("UPDATE marketing_campaigns SET status='paused',version=version+1 WHERE audience_id=1 AND id=?",[$job['campaign_id']]);else $db->execute_query("UPDATE marketing_flow_runs SET state='failed',error_message='Email delivery setup needs attention. Review the existing delivery receipt before restarting.' WHERE audience_id=1 AND id=?",[$job['flow_run_id']]);continue;
            }
            $db->begin_transaction();
            try{
                $recipient=$db->execute_query("SELECT * FROM marketing_campaign_recipients WHERE audience_id=1 AND delivery_id=? AND state='queued' AND phase<>'hold' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED",[$job['id']])->fetch_assoc();
                if($recipient){$claim=bin2hex(random_bytes(16));$db->execute_query("UPDATE marketing_campaign_recipients SET state='processing',claim_token=?,attempts=attempts+1,started_at=UTC_TIMESTAMP(),delivery_started_at=NULL WHERE id=?",[$claim,$recipient['id']]);}
                $db->commit();
            }catch(Throwable $e){$db->rollback();throw $e;}
            if(!$recipient){
                $remaining=(int)$db->execute_query("SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=? AND state IN ('queued','processing')",[$job['id']])->fetch_assoc()['n'];
                if($remaining)break;
                $db->begin_transaction();
                try{
                    $development=(int)$db->execute_query("SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=? AND state='development'",[$job['id']])->fetch_assoc()['n'];
                    $db->execute_query("UPDATE marketing_campaign_deliveries SET state='completed',completed_at=UTC_TIMESTAMP(),version=version+1 WHERE id=?",[$job['id']]);
                    if(empty($job['flow_run_id']))$db->execute_query("UPDATE marketing_campaigns SET status=?,sent_at=IF(?=0,UTC_TIMESTAMP(),NULL),version=version+1 WHERE audience_id=1 AND id=?",[$development?'paused':'sent',$development,$job['campaign_id']]);$db->commit();
                }catch(Throwable $e){$db->rollback();throw $e;}continue;
            }
            $state='failed';$error='';$started=false;$deferred=false;$retry=gmdate('Y-m-d H:i:s',time()+120);
            try{
                $sealed=json_decode(\Dnr\Security\ApplicationKey::open($recipient['context_ciphertext']),true,64,JSON_THROW_ON_ERROR);
                $messageSnapshot=$snapshot;if(($recipient['phase']??'')==='resend')$messageSnapshot['is_resend']=true;$message=audienceDispatchMessage($messageSnapshot,$sealed['context'],$sealed['token'],$recipient['message_id'],(string)($recipient['variant_key']??'0'));
                $person=$db->execute_query('SELECT id,audience_id,email,email_status,is_archived,deleted_at FROM marketing_contacts WHERE id=? AND audience_id=1',[$recipient['contact_id']])->fetch_assoc();
                $optout=$db->execute_query('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=1 AND email_hash=? UNION ALL SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=1 AND email_hash=?',[$recipient['email_hash'],$recipient['email_hash']])->fetch_assoc();
                if(!$person||$person['deleted_at']||$person['is_archived']||$person['email_status']!=='subscribed'||!hash_equals($recipient['email_hash'],audienceDispatchEmailHash($person['email']))||$optout){$state='suppressed';$error='No longer an eligible subscribed connection.';}
                else{
                    // Persist the ambiguity boundary before entering the external transport.
                    $db->execute_query('UPDATE marketing_campaign_recipients SET delivery_started_at=UTC_TIMESTAMP() WHERE id=? AND claim_token=? AND state=\'processing\'',[$recipient['id'],$claim]);if($db->affected_rows!==1)throw new RuntimeException('Delivery claim changed.');$started=true;
                    $message['native_recipient_id']=(int)$recipient['id'];$message['claim_token']=$claim;$message['ses_binding']=$snapshot['ses']??[];$state=$deliver($message);if(!in_array($state,['accepted','development'],true))throw new RuntimeException('Invalid transport receipt.');
                }
            }catch(MailBudgetDeferredException $e){$state='queued';$retry=$e->retryAt;$error=$e->getMessage();$deferred=true;}catch(SmtpPreDataException $e){$state=(int)$recipient['attempts']<2?'queued':'failed';$error='The SMTP connection failed before DATA. Safe retries are limited to three attempts.';}
            catch(DomainException|InvalidArgumentException $e){$state='failed';$error='The email was rejected or could not be rendered. Check the sender, content and recipient.';}
            catch(Throwable $e){$state=$started?'uncertain':'failed';$error=$started?'SES acceptance could not be confirmed. This recipient will not be retried automatically.':'Unable to prepare this recipient. No message was sent.';}
            if($state==='uncertain'&&(int)$db->execute_query("SELECT COUNT(*) n FROM marketing_message_events WHERE audience_id=1 AND recipient_id=? AND source='provider' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.provider'))='ses'",[$recipient['id']])->fetch_assoc()['n']>0){$state='accepted';$error='';}
            $db->execute_query("UPDATE marketing_campaign_recipients SET state=?,error_message=?,accepted_at=IF(?='accepted',UTC_TIMESTAMP(),NULL),next_attempt_at=?,attempts=attempts-?,claim_token=NULL WHERE id=? AND claim_token=? AND state='processing'",[$state,$error,$state,$retry,$deferred?1:0,$recipient['id'],$claim]);
            if($db->affected_rows!==1)throw new RuntimeException('Unable to record campaign delivery receipt.');
            $db->execute_query("UPDATE marketing_campaign_deliveries SET version=version+IF(state='scheduled',1,0),state='sending' WHERE id=?",[$job['id']]);
            if(empty($job['flow_run_id']))$db->execute_query("UPDATE marketing_campaigns SET status='sending' WHERE audience_id=1 AND id=? AND status='scheduled'",[$job['campaign_id']]);$processed++;
        }finally{if($flowLock!==null)$db->execute_query('SELECT RELEASE_LOCK(?)',[$flowLock]);$db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    return $processed;
}
