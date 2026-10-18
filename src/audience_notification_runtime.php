<?php
declare(strict_types=1);
require_once __DIR__.'/audience_store.php';

/** Queue staff alerts only. Sending remains in the existing SES service worker. */
function queueAudienceChangeAlerts(mysqli $db,int $limit=100,?int $now=null):int
{
    if(function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())return 0;
    if((int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_audience_notification_state'")->fetch_assoc()['n']!==1)return 0;
    $now??=time();$store=new AudienceStore($db,1,0,'Audience notifications');
    return $store->transaction(function()use($store,$now,$limit):int{
        $audience=$store->one('SELECT name,settings_json FROM marketing_audiences WHERE id=1');$prefs=audienceJson($audience['settings_json'])['subscriber_notifications']??[];
        if(empty($prefs['generation']))return 0;
        $today=gmdate('Y-m-d',$now);
        $store->write('INSERT IGNORE INTO marketing_audience_notification_state(audience_id,generation,event_cursor,digest_day) VALUES (1,?,?,?)',[$prefs['generation'],$prefs['start_event'],substr($prefs['start_at'],0,10)]);
        $state=$store->one('SELECT * FROM marketing_audience_notification_state WHERE audience_id=1 FOR UPDATE');
        if($state['generation']!==$prefs['generation'])$state=['generation'=>$prefs['generation'],'event_cursor'=>$prefs['start_event'],'digest_day'=>substr($prefs['start_at'],0,10)];
        $ses=$store->one('SELECT * FROM marketing_ses_settings WHERE audience_id=1');$users=$store->audienceNotificationUsers();$queued=0;
        $queue=function(string $kind,string $key,string $text,?int $contact=null)use($store,$prefs,$ses,$users,$now,&$queued):void{
            if(!$ses||!$ses['enabled'])return;
            foreach($users as$user){
                if(!in_array(strtolower($user['email']),$prefs[$kind.'_emails']??[],true))continue;
                $operation=hash('sha256','audience:'.$prefs['generation'].':'.$key.':'.$user['id']);
                $messageId='<audience-alert-'.$operation.'@message-monkey.invalid>';
                $message=['email'=>$user['email'],'from_email'=>$ses['from_email'],'from_name'=>'MessageMonkey','subject'=>match($kind){'signup'=>'New audience subscription','unsubscribe'=>'Audience unsubscribe',default=>'Daily audience summary'},'text'=>$text,'html'=>null,'message_id'=>$messageId,'ses_binding'=>audienceSesBinding($ses),'audience_notice'=>['kind'=>$kind,'generation'=>$prefs['generation'],'user'=>(int)$user['id'],'contact'=>$contact]];
                $payload=\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>$message],JSON_THROW_ON_ERROR));
                $store->write("INSERT IGNORE INTO marketing_service_mail(audience_id,contact_id,kind,actor_id,actor_label,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (1,?,'audience-alert',?,'Audience notifications',?,?,?,?,?)",[$contact,$user['id'],$operation,$payload,$messageId,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+86400)]);$queued+=$store->db->affected_rows;
            }
        };
        $events=$store->all('SELECT e.id,e.contact_id,e.event_type,e.details_json,e.created_at,c.deleted_at FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=1 AND e.id>? ORDER BY e.id LIMIT '.max(1,min(500,$limit)),[$state['event_cursor']]);
        foreach($events as$e){
            $state['event_cursor']=(int)$e['id'];$kind=audienceSubscriptionChange($e['event_type'],audienceJson($e['details_json']));
            if(!$kind||$e['deleted_at']!==null||strtotime($e['created_at'].' UTC')<$now-86400)continue;
            $url=rtrim(accountPrimaryPublicUrl(),'/').'/audience.php?view=contact&id='.$e['contact_id'];
            $queue($kind,'event:'.$e['id'],$audience['name']."\n".($kind==='signup'?'A connection subscribed.':'A connection unsubscribed.')."\nReview this change: ".$url,(int)$e['contact_id']);
        }
        if($state['digest_day']<$today){
            $counts=audienceNotificationDigest($store->db,1,$prefs,$now);
            if(array_sum($counts))$queue('digest','digest:'.$today,$audience['name']."\nAudience changes for ".gmdate('Y-m-d',$now-86400).' (UTC): '.$counts['signup'].' subscriptions, '.$counts['unsubscribe']." unsubscribes.\n".rtrim(accountPrimaryPublicUrl(),'/').'/audience.php?view=analytics&section=audience');
            $state['digest_day']=$today;
        }
        $store->write('UPDATE marketing_audience_notification_state SET generation=?,event_cursor=?,digest_day=? WHERE audience_id=1',[$state['generation'],$state['event_cursor'],$state['digest_day']]);return $queued;
    });
}

function audienceChangeAlertReady(mysqli $db,array $message):void
{
    $notice=$message['audience_notice']??[];
    $row=$db->query('SELECT settings_json FROM marketing_audiences WHERE id=1')->fetch_assoc();$prefs=audienceJson($row['settings_json'])['subscriber_notifications']??[];
    $kind=$notice['kind']??'';
    $user=$db->execute_query("SELECT email FROM users WHERE id=? AND role IN ('admin','editor') AND account_status='active' AND email_verified_at IS NOT NULL",[(int)($notice['user']??0)])->fetch_assoc();
    if(!in_array($kind,['signup','unsubscribe','digest'],true)||!$user||($prefs['generation']??'')!==($notice['generation']??null)||strtolower($user['email'])!==strtolower($message['email'])||!in_array(strtolower($user['email']),$prefs[$kind.'_emails']??[],true))throw new DomainException('This audience notification is no longer eligible.');
    if(!empty($notice['contact'])&&!$db->execute_query('SELECT id FROM marketing_contacts WHERE audience_id=1 AND id=? AND deleted_at IS NULL',[$notice['contact']])->fetch_assoc())throw new DomainException('This connection is unavailable.');
}
