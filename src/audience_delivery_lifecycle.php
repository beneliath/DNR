<?php
declare(strict_types=1);

/** Released hold-outs reuse the confirmed snapshot and are never selected from the live audience. */
function audienceChooseWinner(mysqli $db,int $delivery,string $winner):void
{
    $job=$db->execute_query('SELECT * FROM marketing_campaign_deliveries WHERE audience_id=1 AND id=?',[$delivery])->fetch_assoc();if(!$job||$job['winner_key']!==null)return;
    $snapshot=json_decode(\Dnr\Security\ApplicationKey::open($job['snapshot_ciphertext']),true,64,JSON_THROW_ON_ERROR);$variants=audienceExperimentVariants($snapshot['draft']);if(!ctype_digit($winner)||!isset($variants[(int)$winner]))throw new InvalidArgumentException('Choose an available test combination.');
    $db->begin_transaction();try{
        $db->execute_query('UPDATE marketing_campaign_deliveries SET winner_key=?,version=version+1 WHERE id=? AND winner_key IS NULL',[$winner,$delivery]);
        if($db->affected_rows===1)$db->execute_query("UPDATE marketing_campaign_recipients SET variant_key=?,phase='main',next_attempt_at=UTC_TIMESTAMP() WHERE delivery_id=? AND phase='hold' AND state='queued'",[$winner,$delivery]);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function processAudienceLifecycle(mysqli $db):void
{
    $jobs=$db->query("SELECT * FROM marketing_campaign_deliveries WHERE audience_id=1 AND state NOT IN ('paused','cancelled') AND ((experiment_due_at<=UTC_TIMESTAMP() AND winner_key IS NULL) OR (state='completed' AND resend_due_at<=UTC_TIMESTAMP() AND resend_created_at IS NULL)) ORDER BY id LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    foreach($jobs as$job){$lock=audienceDispatchLock($db,1,(int)$job['campaign_id']);try{
        $job=$db->execute_query('SELECT * FROM marketing_campaign_deliveries WHERE id=?',[$job['id']])->fetch_assoc();if(in_array($job['state'],['paused','cancelled'],true))continue;
        $snapshot=json_decode(\Dnr\Security\ApplicationKey::open($job['snapshot_ciphertext']),true,64,JSON_THROW_ON_ERROR);$draft=$snapshot['draft'];
        if($job['experiment_due_at']&&$job['winner_key']===null&&strtotime($job['experiment_due_at'].' UTC')<=time()){
            if(($draft['experiment']['winner']??'manual')==='manual')continue;
            $pending=(int)$db->execute_query("SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=? AND phase='test' AND state IN ('queued','processing')",[$job['id']])->fetch_assoc()['n'];if($pending)continue;
            $type=$draft['experiment']['winner']==='opens'?'open':'click';$scores=$db->execute_query("SELECT r.variant_key,COUNT(DISTINCT CASE WHEN e.event_type=? AND COALESCE(JSON_EXTRACT(e.details_json,'$.bot'),false)=false THEN r.contact_id END) engaged,COUNT(DISTINCT CASE WHEN r.state='accepted' THEN r.contact_id END) sent FROM marketing_campaign_recipients r LEFT JOIN marketing_message_events e ON e.recipient_id=r.id WHERE r.delivery_id=? AND r.phase='test' GROUP BY r.variant_key",[$type,$job['id']])->fetch_all(MYSQLI_ASSOC);
            usort($scores,static fn($a,$b)=>(($b['sent']?(float)$b['engaged']/$b['sent']:0)<=>($a['sent']?(float)$a['engaged']/$a['sent']:0))?:((int)$a['variant_key']<=>(int)$b['variant_key']));
            audienceChooseWinner($db,(int)$job['id'],(string)($scores[0]['variant_key']??'0'));
        }
        if($job['state']==='completed'&&$job['resend_due_at']&&!$job['resend_created_at']&&strtotime($job['resend_due_at'].' UTC')<=time()){
            $db->begin_transaction();try{
                $locked=$db->execute_query('SELECT resend_created_at FROM marketing_campaign_deliveries WHERE id=? FOR UPDATE',[$job['id']])->fetch_assoc();if($locked['resend_created_at']){$db->commit();continue;}
                $kind=($draft['resend']['segment']??'non-openers')==='non-clickers'?'click':'open';$ignore=!empty($draft['resend']['ignore_bots']);$membershipOnly=isset($snapshot['resend_allowed_ids']);$filter=$ignore?" AND COALESCE(JSON_EXTRACT(e.details_json,'$.bot'),false)=false":'';
                $engagementWhere=$membershipOnly?'':" AND NOT EXISTS(SELECT 1 FROM marketing_message_events e WHERE e.recipient_id=r.id AND e.event_type=? $filter)";
                $rows=$db->execute_query("SELECT r.* FROM marketing_campaign_recipients r WHERE r.delivery_id=? AND r.state='accepted' AND r.phase<>'resend' $engagementWhere",$membershipOnly?[$job['id']]:[$job['id'],$kind])->fetch_all(MYSQLI_ASSOC);
                $n=0;foreach($rows as$r){if(isset($snapshot['resend_allowed_ids'])&&!in_array((int)$r['contact_id'],$snapshot['resend_allowed_ids'],true))continue;$sealed=json_decode(\Dnr\Security\ApplicationKey::open($r['context_ciphertext']),true,64,JSON_THROW_ON_ERROR);$sealed['token']=bin2hex(random_bytes(32));$mid='<resend-'.bin2hex(random_bytes(20)).'@message-monkey.invalid>';
                    $db->execute_query("INSERT IGNORE INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,next_attempt_at,variant_key,phase,original_recipient_id) VALUES (1,?,?,?,?,?,?,UTC_TIMESTAMP(),?,'resend',?)",[$job['id'],$r['contact_id'],$r['email_hash'],\Dnr\Security\ApplicationKey::seal(json_encode($sealed,JSON_THROW_ON_ERROR)),hash('sha256',$sealed['token']),$mid,$r['variant_key'],$r['id']]);$n+=$db->affected_rows;
                }
                $db->execute_query("UPDATE marketing_campaign_deliveries SET resend_created_at=UTC_TIMESTAMP(),state=IF(?,'sending',state),version=version+1 WHERE id=?",[$n,$job['id']]);if($n)$db->execute_query("UPDATE marketing_campaigns SET status='sending' WHERE audience_id=1 AND id=?",[$job['campaign_id']]);$db->commit();
            }catch(Throwable $e){$db->rollback();throw $e;}
        }
    }finally{$db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}}
}
function processAudienceServiceMail(mysqli $db,int $limit=5,?callable $deliver=null):int
{
    if(function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())return 0;
    if(!audienceMailPolicyAvailable($db))return 0;
    require_once __DIR__.'/audience_ses_worker.php';
    $deliver??=static fn(array $message,string $kind):string=>audienceSesDeliver($db,$message,$kind);
    $db->query("UPDATE marketing_service_mail SET state=IF(delivery_started_at IS NULL,'failed','uncertain'),payload_ciphertext=IF(kind='confirmation','',payload_ciphertext),error_message='The worker stopped before a final receipt.' WHERE audience_id=1 AND state='processing' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)");
    $db->query("UPDATE marketing_service_mail SET state='failed',payload_ciphertext=IF(kind='confirmation','',payload_ciphertext),error_message='This request expired.' WHERE audience_id=1 AND state='queued' AND expires_at<=UTC_TIMESTAMP()");
    $count=0;
    for($i=0;$i<min(20,max(1,$limit));$i++){
        $db->begin_transaction();try{$job=$db->query("SELECT * FROM marketing_service_mail WHERE audience_id=1 AND state='queued' AND next_attempt_at<=UTC_TIMESTAMP() AND expires_at>UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch_assoc();if(!$job){$db->commit();break;}$claim=bin2hex(random_bytes(16));$db->execute_query("UPDATE marketing_service_mail SET state='processing',attempts=attempts+1,claim_token=?,started_at=UTC_TIMESTAMP(),delivery_started_at=NULL WHERE id=?",[$claim,$job['id']]);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
        $state='failed';$error='';$retry=gmdate('Y-m-d H:i:s',time()+120);$deferred=false;$started=false;
        try{$payload=json_decode(\Dnr\Security\ApplicationKey::open($job['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR);$message=$payload['message'];if($job['kind']==='test')audienceDispatchWorkerReady($db,1,$payload['snapshot']);else audienceSesWorkerReady($db,1,$message['from_email'],$message['ses_binding']??[]);
            if($job['kind']==='confirmation'){$person=$db->execute_query('SELECT email,email_status,is_archived,deleted_at FROM marketing_contacts WHERE audience_id=1 AND id=?',[$job['contact_id']])->fetch_assoc();$challenge=$db->execute_query('SELECT id FROM marketing_consent_challenges WHERE service_mail_id=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$job['id']])->fetch_assoc();if(!$person||!$challenge||$person['deleted_at']||$person['is_archived']||$person['email_status']==='cleaned'||strtolower($person['email'])!==strtolower($message['email']))throw new DomainException('Confirmation is no longer eligible.');}
            $db->execute_query('UPDATE marketing_service_mail SET delivery_started_at=UTC_TIMESTAMP() WHERE id=? AND claim_token=?',[$job['id'],$claim]);$started=true;$message['native_service_id']=(int)$job['id'];$message['claim_token']=$claim;if($job['kind']==='test')$message['ses_binding']=$payload['snapshot']['ses']??[];$state=$deliver($message,$job['kind']);if(!in_array($state,['accepted','development'],true))throw new RuntimeException('Invalid mail receipt.');
        }catch(MailBudgetDeferredException $e){$state='queued';$retry=$e->retryAt;$error=$e->getMessage();$deferred=true;}
        catch(SmtpPreDataException $e){$state=(int)$job['attempts']<2?'queued':'failed';$error='SMTP connection failed before DATA.';}
        catch(DomainException|InvalidArgumentException $e){$state='failed';$error='The request is no longer eligible or SES rejected it.';}
        catch(Throwable $e){$state=$started?'uncertain':'failed';$error=$started?'SES acceptance is uncertain. This message will not be replayed automatically.':'Unable to prepare this message.';}
        $db->execute_query("UPDATE marketing_service_mail SET state=?,error_message=?,next_attempt_at=?,attempts=attempts-?,payload_ciphertext=IF(kind='confirmation' AND ?<>'queued','',payload_ciphertext),claim_token=NULL WHERE id=? AND claim_token=? AND state='processing'",[$state,$error,$retry,$deferred?1:0,$state,$job['id'],$claim]);$count++;
    }return $count;
}
