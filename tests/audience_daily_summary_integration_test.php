<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Daily summary checks require a disposable database.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_notification_runtime.php';require_once $source.'/audience_delivery_lifecycle.php';
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Daily summary qualification');$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
foreach([
    ['2026-10-29 04:10:59','2026-10-28 04:11:00'],
    ['2026-10-29 04:11:00','2026-10-29 04:11:00'],
    ['2026-10-29 05:00:00','2026-10-29 04:11:00'],
    ['2026-11-02 05:10:59','2026-11-01 04:11:00'],
    ['2026-11-02 05:11:00','2026-11-02 05:11:00'],
    ['2027-03-15 04:10:59','2027-03-14 05:11:00'],
    ['2027-03-15 04:11:00','2027-03-15 04:11:00'],
]as[$now,$due])$expect(gmdate('Y-m-d H:i:s',audienceDailySummaryDue(strtotime($now.' UTC')))===$due,'23:11 Chicago slot, midnight and DST boundary: '.$now);
$oldAudience=$store->one('SELECT * FROM marketing_audiences WHERE id=1');$oldSettings=audienceJson($oldAudience['settings_json']);$oldState=$store->one('SELECT * FROM marketing_audience_notification_state WHERE audience_id=1');$oldSes=$store->sesSettings();
$oldQueued=$store->all("SELECT id,next_attempt_at FROM marketing_service_mail WHERE state='queued'");
foreach($oldQueued as$row)$store->write('UPDATE marketing_service_mail SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY) WHERE id=?',[$row['id']]);
$suffix=bin2hex(random_bytes(6));$email='daily-summary-'.$suffix.'@example.test';$actor=0;$person=0;
try{
    $db->execute_query("INSERT INTO users(username,password,role,account_status,email,email_verified_at) VALUES (?,?,'admin','active',?,UTC_TIMESTAMP())",['daily-summary-'.$suffix,password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),$email]);$actor=(int)$db->insert_id;
    $store=new AudienceStore($db,1,$actor,'Daily summary qualification');
    // Isolate the fixture's original observation point from other suites.
    $initial=$oldSettings;unset($initial['subscriber_notifications']);$store->write('UPDATE marketing_audiences SET settings_json=? WHERE id=1',[json_encode($initial,JSON_THROW_ON_ERROR)]);
    $input=['name'=>$oldAudience['name'],'from_name'=>'Fixture','from_email'=>'sender@example.test','default_subject'=>'Fixture','permission_reminder'=>'Fixture','mailing_address'=>'Fixture','notifications_present'=>'1','signup_emails'=>'','unsubscribe_emails'=>'','digest_emails'=>$email,'in_app_digest'=>'1'];
    $save=static function(array $values)use($store):array{$version=(int)$store->one('SELECT version FROM marketing_audiences WHERE id=1')['version'];$store->saveAudienceDefaults($values,$version);return audienceJson($store->one('SELECT settings_json FROM marketing_audiences WHERE id=1')['settings_json'])['subscriber_notifications'];};
    $prefs=$save($input);$baseline=$prefs['digest_start_event'];
    $store->write("UPDATE marketing_ses_settings SET enabled=1,from_email='sender@example.test',access_key_tail='TEST',check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=1000,max_rate=1000,subscription_arn='fixture-confirmed' WHERE audience_id=1");
    $day=gmdate('Y-m-d');$first=(new DateTimeImmutable($day.' 23:11:00',new DateTimeZone('America/Chicago')))->getTimestamp();$before=$first-1;$marker=gmdate('Y-m-d',audienceDailySummaryDue($before));
    $store->write('INSERT INTO marketing_audience_notification_state(audience_id,generation,event_cursor,digest_day) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE generation=VALUES(generation),event_cursor=VALUES(event_cursor),digest_day=VALUES(digest_day)',[$prefs['generation'],$prefs['start_event'],$marker]);
    $jobs=static fn()=>$store->all("SELECT * FROM marketing_service_mail WHERE audience_id=1 AND kind='audience-alert' AND actor_id=? ORDER BY id",[$actor]);
    $message=static fn(array $job)=>audienceJson(\Dnr\Security\ApplicationKey::open($job['payload_ciphertext']))['message'];
    $last=static function()use($jobs):array{$rows=$jobs();return $rows[array_key_last($rows)];};
    $checkpoint=static fn()=>$store->one('SELECT digest_day FROM marketing_audience_notification_state WHERE audience_id=1')['digest_day'];
    $expect(queueAudienceChangeAlerts($db,100,$before)===0&&$jobs()===[],'No daily summary before 23:11');
    $expect(queueAudienceChangeAlerts($db,100,$first)===1,'A quiet day queues a summary at 23:11');
    $quiet=$last();$quietMessage=$message($quiet);
    $expect(str_contains($quietMessage['text'],'0 subscriptions, 0 unsubscribes.')&&str_contains($quietMessage['text'],'No subscriptions or unsubscribes were recorded.'),'Quiet-day email explicitly reports no changes');
    $expect(str_contains($quietMessage['text'],'Audience changes for '.$day.' (UTC)'),'The summary covers the previous complete UTC day');
    $expect(queueAudienceChangeAlerts($db,100,$first+60)===0&&count($jobs())===1,'Repeated passes do not duplicate a daily email');
    $changed=$input;$changed['in_app_signup']='1';$prefs=$save($changed);
    audienceChangeAlertReady($db,$quietMessage);$expect(true,'An unrelated preference edit does not invalidate a prepared daily summary');
    $expect(queueAudienceChangeAlerts($db,100,$first+120)===0&&$checkpoint()===gmdate('Y-m-d',$first),'A new preference generation preserves the daily checkpoint');
    // Exercise the real service-mail worker with a controlled callback, never SES.
    $store->write('UPDATE marketing_service_mail SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?',[$quiet['id']]);$delivered=[];
    processAudienceServiceMail($db,5,static function(array $outgoing,string $kind)use(&$delivered,$email):string{if($kind!=='audience-alert'||$outgoing['email']!==$email)throw new RuntimeException('Unexpected outgoing fixture.');$delivered[]=$outgoing;return 'accepted';});
    $expect(count($delivered)===1&&$store->one('SELECT state FROM marketing_service_mail WHERE id=?',[$quiet['id']])['state']==='accepted','The worker prepares and accepts a zero-change summary');
    $person=$store->savePerson(['email'=>'summary-contact-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    $eventDate=gmdate('Y-m-d',$first).' 12:00:00';
    foreach([['subscription_changed',['from'=>'pending','to'=>'subscribed']],['subscription_changed',['from'=>'subscribed','to'=>'unsubscribed']],['contact_added',['status'=>'subscribed','origin'=>'Import']],['subscription-confirmed',['from'=>'subscribed','to'=>'subscribed']]]as[$type,$details])$store->write('INSERT INTO marketing_contact_events(contact_id,event_type,details_json,actor_label,created_at) VALUES (?,?,?,?,?)',[$person,$type,json_encode($details,JSON_THROW_ON_ERROR),'Summary fixture',$eventDate]);
    $changed['in_app_unsubscribe']='1';$prefs=$save($changed);
    $expect($prefs['digest_start_event']===$baseline&&$prefs['start_event']>$baseline,'Preference edits retain the summary baseline while renewing individual alerts');
    $second=(new DateTimeImmutable($day.' 23:11:00',new DateTimeZone('America/Chicago')))->modify('+1 day')->getTimestamp();
    $expect(queueAudienceChangeAlerts($db,100,$second)===1,'Next daily email is queued after preference edits');$changes=$message($last());
    $expect(str_contains($changes['text'],'1 subscriptions, 1 unsubscribes.'),'Earlier changes survive a later preference save; imports and repeat confirmations are excluded');
    $expect(!str_contains($changes['text'],'summary-contact-'),'The summary does not expose contact email addresses');
    $third=(new DateTimeImmutable('@'.$second))->setTimezone(new DateTimeZone('America/Chicago'))->modify('+1 day')->getTimestamp();
    $store->write('UPDATE marketing_ses_settings SET enabled=0 WHERE audience_id=1');$priorDay=$checkpoint();
    $expect(queueAudienceChangeAlerts($db,100,$third)===0&&$checkpoint()===$priorDay,'Disabled sending does not silently consume a summary day');
    $store->write('UPDATE marketing_ses_settings SET enabled=1 WHERE audience_id=1');
    $expect(queueAudienceChangeAlerts($db,100,$third+30)===1&&queueAudienceChangeAlerts($db,100,$third+60)===0,'Sending recovery queues the due summary exactly once');
    $expect(str_contains($message($last())['text'],'No subscriptions or unsubscribes were recorded.'),'A subsequent quiet day also produces a no-change report');
    $fourth=(new DateTimeImmutable('@'.$third))->setTimezone(new DateTimeZone('America/Chicago'))->modify('+1 day')->getTimestamp();
    $store->write('UPDATE users SET email_verified_at=NULL WHERE id=?',[$actor]);$priorDay=$checkpoint();
    $expect(queueAudienceChangeAlerts($db,100,$fourth)===0&&$checkpoint()===$priorDay,'Ineligible recipients do not silently consume a due summary');
    $store->write('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=?',[$actor]);
    $expect(queueAudienceChangeAlerts($db,100,$fourth+30)===1,'An eligible recipient can receive the still-due summary');
    $prepared=$message($last());$changed['digest_emails']='';$save($changed);$blocked=false;try{audienceChangeAlertReady($db,$prepared);}catch(DomainException){$blocked=true;}
    $expect($blocked,'Removing the recipient revokes a prepared daily summary');
    $count=count($jobs());$fifth=(new DateTimeImmutable('@'.$fourth))->setTimezone(new DateTimeZone('America/Chicago'))->modify('+1 day')->getTimestamp();
    $expect(queueAudienceChangeAlerts($db,100,$fifth)===0&&count($jobs())===$count,'An empty recipient list keeps daily email disabled');
    echo "Daily summary: $checks checks passed; no external email.\n";
}finally{
    if($actor){$store->write("UPDATE marketing_service_mail SET state='cancelled' WHERE kind='audience-alert' AND actor_id=? AND state IN ('queued','processing')",[$actor]);$store->write("UPDATE users SET account_status='inactive' WHERE id=?",[$actor]);}
    $store->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=1',[$oldAudience['settings_json']]);
    $restore=$oldState??['generation'=>str_repeat('0',32),'event_cursor'=>0,'digest_day'=>gmdate('Y-m-d',audienceDailySummaryDue(time()))];
    $store->write('UPDATE marketing_audience_notification_state SET generation=?,event_cursor=?,digest_day=? WHERE audience_id=1',[$restore['generation'],$restore['event_cursor'],$restore['digest_day']]);
    $keys=['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','subscription_arn'];
    $store->write('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($key)=>$key.'=?',$keys)).' WHERE audience_id=1',array_map(static fn($key)=>$oldSes[$key],$keys));
    foreach($oldQueued as$row)$store->write('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$row['next_attempt_at'],$row['id']]);
}
