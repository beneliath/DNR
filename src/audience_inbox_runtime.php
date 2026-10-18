<?php
declare(strict_types=1);
require_once __DIR__.'/audience_store.php';

/** Consume a signed Inbox route before the ordinary Engagement/Inquiry filing path. */
function processAudienceInboxInbound(mysqli $db,int $id):?string
{
    if(!audienceInboxInstalled($db))return null;
    $m=$db->execute_query('SELECT * FROM inbound_email_messages WHERE id=?',[$id])->fetch_assoc();if(!$m)return null;
    $route=localAccountMailRoute($m);$targets=array_values(array_filter($route['targets'],static fn($t)=>$t['type']==='M'));
    $shaped=str_contains(strtolower((string)$m['to_addresses'].(string)$m['cc_addresses']),'+mm.')||preg_match('/\[MOED@[^\]]{1,100}#M/',$m['subject']."\n".$m['body_text']);
    if(!$targets&&!$shaped)return null;
    if($m['status']==='processed')return 'processed';
    $review=static function(string $reason)use($db,$id):string{$db->execute_query("UPDATE inbound_email_messages SET status='review',review_reason=?,processing_started_at=NULL,last_error=NULL WHERE id=? AND status IN ('pending','processing','review')",[$reason,$id]);return 'review';};
    if($route['account_key']!==currentAccountKey()||count($targets)!==1||count($route['targets'])!==1)return $review('Inbox routing must identify exactly one valid Account and reply source.');
    $target=$targets[0];$source=$db->execute_query('SELECT * FROM marketing_inbox_routes WHERE audience_id=1 AND id=? AND enabled=1',[$target['id']])->fetch_assoc();
    $store=new AudienceStore($db,1,0,'Inbox gateway');$settings=$store->inboxSettings();
    if(!$source||!$settings['gateway_confirmed'])return $review('The Inbox email source is not connected.');
    if(!in_array($m['status'],['pending','processing','review'],true))return $m['status'];
    $authentication=inboundEmailSenderAuthentication($m,true);
    if(empty($authentication['trusted']))return $review('Inbox requires a sender authenticated by the trusted receiving gateway.');
    if(audienceInboxAutoReply($m['raw_headers'])){$db->execute_query("UPDATE inbound_email_messages SET status='rejected',review_reason='Automatic reply filtered from MessageMonkey Inbox.',processing_started_at=NULL WHERE id=?",[$id]);return 'rejected';}
    $thread=$source['source']==='thread'?(int)$source['reference_id']:0;
    $attachments=[];$retained=[];
    // Parts have already passed the receiving parser's raw and aggregate size bounds.
    foreach($db->execute_query('SELECT filename,content FROM marketing_inbox_inbound_parts WHERE inbound_id=? ORDER BY part_number',[$id])->fetch_all(MYSQLI_ASSOC)as$a){try{$file=audienceInboxAttachment($a['filename'],$a['content']);$attachments[]=$file;$retained[]=$file['filename'];}catch(InvalidArgumentException){/* Retain the explicit missing-file notice, never an unsafe download. */}}
    $missing=array_diff(inboundEmailDecodeStringList($m['attachment_names']),$retained);$text=$m['body_text'];if($missing)$text.="\n\nAttachments not retained (unsupported type or size): ".implode(', ',$missing);
    $rfc=trim((string)$m['rfc_message_id'],'<>');$rfc=$rfc!==''?'<'.$rfc.'>':'';
    return $store->transaction(function()use($db,$store,$m,$id,$thread,$source,$attachments,$text,$rfc):string{
        $locked=$db->execute_query('SELECT status FROM inbound_email_messages WHERE id=? FOR UPDATE',[$id])->fetch_assoc();if(($locked['status']??'')==='processed')return 'processed';
        $conversation=$store->inboxReceive($source['source']==='thread'?'email':$source['source'],(int)$source['reference_id'],$m['sender_address'],$m['sender_name']??'',$m['subject'],$text,bin2hex($m['deduplication_hash']),true,audienceInboxReferences($m['raw_headers']),$thread,$attachments,true,$rfc);
        $details=json_encode(['message_monkey_inbox'=>$conversation,'account'=>currentAccountKey()],JSON_THROW_ON_ERROR);
        $db->execute_query("UPDATE inbound_email_messages SET status='processed',routing_details=?,review_reason=NULL,processing_started_at=NULL,last_error=NULL,processed_at=UTC_TIMESTAMP() WHERE id=?",[$details,$id]);return 'processed';
    });
}

/** Dispatch-time consent, identity and staff authorization are rechecked, independent of the web UI. */
function audienceInboxServiceReady(mysqli $db,array $job,array $message):void
{
    if(!audienceInboxInstalled($db))throw new DomainException('Inbox is unavailable.');
    if($job['kind']==='inbox-alert'){
        $user=$db->execute_query("SELECT u.email FROM users u JOIN marketing_inbox_user_settings s ON s.user_id=u.id AND s.audience_id=1 AND s.email_alerts=1 WHERE u.id=? AND u.role IN ('admin','editor') AND u.account_status='active' AND u.email_verified_at IS NOT NULL",[$message['inbox_notification_user']??0])->fetch_assoc();
        $notification=$db->execute_query('SELECT n.id FROM marketing_inbox_notifications n JOIN marketing_inbox_threads t ON t.audience_id=n.audience_id AND t.id=n.thread_id AND t.status<>\'trash\' JOIN marketing_inbox_user_settings p ON p.audience_id=n.audience_id AND p.user_id=n.user_id AND p.email_alerts=1 WHERE n.audience_id=1 AND n.id=? AND n.user_id=? AND n.read_at IS NULL AND n.created_at>=p.updated_at',[$message['inbox_notification_id']??0,$message['inbox_notification_user']??0])->fetch_assoc();
        if(!$user||!$notification||strtolower($user['email'])!==strtolower($message['email']))throw new DomainException('The Inbox notification is no longer eligible.');return;
    }
    $actor=$db->execute_query("SELECT id FROM users WHERE id=? AND role IN ('admin','editor') AND account_status='active'",[$job['actor_id']])->fetch_assoc();if(!$actor)throw new DomainException('The sender no longer has Inbox access.');
    $store=new AudienceStore($db,1,(int)$job['actor_id'],'Inbox worker');
    $eligible=$store->inboxRecipientEligibility(['contact_id'=>$job['contact_id'],'thread_id'=>$message['inbox_thread_id']??0]);
    if($eligible['reasons']||!$eligible['person']||strtolower($eligible['person']['email'])!==strtolower($message['email'])||empty($store->inboxSettings()['gateway_confirmed']))throw new DomainException('Inbox recipient, source, or permissions changed.');
}
function audienceInboxDeliveryMessage(array $message):array
{
    $files=[];$total=0;
    foreach($message['attachments']??[]as$file){$bytes=base64_decode($file['data_base64']??'',true);if($bytes===false)throw new DomainException('Invalid frozen attachment.');$attachment=audienceInboxAttachment($file['filename']??'',$bytes,false);$total+=$attachment['size'];if($total>AUDIENCE_INBOX_ATTACHMENTS_BYTES||count($files)>=10)throw new DomainException('Inbox attachments exceed the delivery limit.');$files[]=$attachment;}
    $message['attachments']=$files;return $message;
}

/** A signed SNS receipt can resolve uncertain acceptance without replaying a message. */
function audienceInboxProviderEvent(AudienceStore $store,array $settings,array $envelope,array $mail,string $correlation,string $providerId,string $type,string $at):string
{
    if(!$store->inboxAvailable())return 'ignored';
    $job=$store->one("SELECT * FROM marketing_service_mail WHERE audience_id=? AND kind IN ('inbox','inbox-alert') AND ses_correlation=? AND provider_account_id=? AND provider_region=? AND delivery_started_at IS NOT NULL FOR UPDATE",[$store->audienceId,$correlation,$settings['aws_account_id'],$settings['region']]);
    if(!$job||count($mail['destination']??[])!==1||!hash_equals($job['recipient_hash'],audienceDispatchEmailHash((string)$mail['destination'][0]))||($job['provider_message_id']!==null&&$job['provider_message_id']!==$providerId))return 'ignored';
    $store->write('INSERT IGNORE INTO marketing_ses_notifications(audience_id,notification_id,event_type) VALUES (?,?,?)',[$store->audienceId,$envelope['MessageId'],$type]);if($store->db->affected_rows!==1)return 'duplicate';
    $store->write('INSERT INTO marketing_inbox_delivery_events(audience_id,service_mail_id,event_type,event_at) VALUES (?,?,?,?)',[$store->audienceId,$job['id'],$type,$at]);
    $store->write("UPDATE marketing_service_mail SET provider_message_id=?,error_message=IF(state='uncertain','',error_message),state=IF(state='uncertain','accepted',state) WHERE audience_id=? AND id=?",[$providerId,$store->audienceId,$job['id']]);
    if($job['kind']==='inbox'&&in_array($type,['hard-bounce','complaint'],true)){
        $person=$store->one('SELECT id,email,email_status FROM marketing_contacts WHERE audience_id=? AND id=? AND deleted_at IS NULL FOR UPDATE',[$store->audienceId,$job['contact_id']]);
        $confirmed=$person?$store->one("SELECT created_at FROM marketing_contact_events WHERE contact_id=? AND event_type='subscription-confirmed' ORDER BY id DESC LIMIT 1",[$person['id']]):null;
        if($person&&hash_equals($job['recipient_hash'],audienceDispatchEmailHash($person['email']))&&(!$confirmed||new DateTimeImmutable($confirmed['created_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable($at,new DateTimeZone('UTC')))){
            $status=$type==='hard-bounce'||$person['email_status']==='cleaned'?'cleaned':'unsubscribed';$store->write('UPDATE marketing_contacts SET email_status=?,version=version+1 WHERE audience_id=? AND id=?',[$status,$store->audienceId,$person['id']]);
            if($type==='complaint')$store->write('INSERT IGNORE INTO marketing_email_optouts(audience_id,email_hash) VALUES (?,?)',[$store->audienceId,$job['recipient_hash']]);
            $store->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Authenticated event source','provider','status',?)",[$person['id'],json_encode(['from'=>$person['email_status'],'to'=>$status,'source'=>$type,'inbox_service_mail_id'=>(int)$job['id']],JSON_THROW_ON_ERROR)]);
        }
    }
    $store->write('UPDATE marketing_ses_settings SET last_event_at=UTC_TIMESTAMP() WHERE audience_id=? AND generation=?',[$store->audienceId,$settings['generation']]);return 'accepted';
}

/** Opt-in staff alerts use the same encrypted, quota-limited SES queue. No message body leaves Inbox. */
function queueAudienceInboxAlerts(mysqli $db,int $limit=10):int
{
    if(!audienceInboxInstalled($db)||!accountSecurityAccessAllowed())return 0;
    $s=$db->query('SELECT * FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc();if(!$s||audienceSesReasons($s,$s['from_email']))return 0;
    $count=0;$store=new AudienceStore($db,1,0,'Inbox notifications');
    foreach($store->all("SELECT n.id FROM marketing_inbox_notifications n JOIN marketing_inbox_threads t ON t.audience_id=n.audience_id AND t.id=n.thread_id AND t.status<>'trash' JOIN marketing_inbox_user_settings p ON p.user_id=n.user_id AND p.audience_id=n.audience_id AND p.email_alerts=1 JOIN users u ON u.id=n.user_id AND u.role IN ('admin','editor') AND u.account_status='active' AND u.email_verified_at IS NOT NULL WHERE n.audience_id=1 AND n.email_checked_at IS NULL AND n.read_at IS NULL AND n.created_at>=p.updated_at AND n.created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) ORDER BY n.id LIMIT ".min(20,max(1,$limit)))as$row){
        $count+=$store->transaction(function()use($store,$row,$s){$n=$store->one('SELECT * FROM marketing_inbox_notifications WHERE id=? AND audience_id=1 FOR UPDATE',[$row['id']]);if(!$n||$n['read_at']||$n['email_checked_at'])return 0;
            $u=$store->one("SELECT u.id,u.email FROM users u JOIN marketing_inbox_user_settings p ON p.user_id=u.id AND p.audience_id=1 AND p.email_alerts=1 WHERE u.id=? AND u.role IN ('admin','editor') AND u.account_status='active' AND u.email_verified_at IS NOT NULL",[$n['user_id']]);if(!$u)return 0;
            $url=rtrim(accountPrimaryPublicUrl(),'/').'/audience.php?view=inbox&thread='.$n['thread_id'];$messageId='<inbox-alert-'.bin2hex(random_bytes(24)).'@message-monkey.invalid>';
            $message=['email'=>audienceInboxEmail($u['email']),'subject'=>'New activity in MessageMonkey Inbox','text'=>'New Inbox activity is ready to review: '.$url,'html'=>null,'from_email'=>$s['from_email'],'from_name'=>'MessageMonkey Inbox','message_id'=>$messageId,'ses_binding'=>audienceSesBinding($s),'inbox_notification_user'=>(int)$u['id'],'inbox_notification_id'=>(int)$n['id']];
            $sealed=\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>$message],JSON_THROW_ON_ERROR));
            $store->write("INSERT INTO marketing_service_mail(audience_id,kind,actor_id,actor_label,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (1,'inbox-alert',?,'Inbox notifications',?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))",[$u['id'],hash('sha256','inbox-alert:'.$n['id']),$sealed,$messageId]);$mail=(int)$store->db->insert_id;
            $store->write('UPDATE marketing_inbox_notifications SET email_mail_id=?,email_checked_at=UTC_TIMESTAMP() WHERE id=? AND audience_id=1',[$mail,$n['id']]);return 1;
        });
    }return $count;
}
