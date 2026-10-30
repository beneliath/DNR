<?php
declare(strict_types=1);
// Database qualification is restricted to the disposable manual/mobile preview.
if(PHP_SAPI!=='cli'||getenv('DNR_MANUAL_FIXTURE')!=='disposable'||getenv('DNR_ACCOUNT_KEY')!=='message-monkey-guide')exit("Notification state tests require the disposable manual/mobile preview.\n");
require '/var/www/html/bootstrap.php';require '/var/www/html/audience_store.php';require '/var/www/html/audience_notification_state.php';
$db=applicationDatabaseConnection();$users=[];$audience=0;$checks=0;
$expect=static function(bool $value,string $message)use(&$checks):void{if(!$value)throw new RuntimeException($message);$checks++;};
try{
    foreach(['one','two'] as $suffix){$db->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')",['notification-test-'.$suffix.'-'.bin2hex(random_bytes(4)),password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$users[]=(int)$db->insert_id;}
    $db->execute_query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Notification isolation fixture',JSON_OBJECT())");$audience=(int)$db->insert_id;
    $first=new AudienceStore($db,1,$users[0],'Fixture');$second=new AudienceStore($db,1,$users[1],'Other user');$other=new AudienceStore($db,$audience,$users[0],'Other audience');
    foreach([$first,$first,$second,$other] as $store)$store->write("INSERT INTO marketing_jobs(audience_id,actor_user_id,actor_label,job_type,name,state,params_json) VALUES (?,?,'Fixture','export','Notification test','complete',JSON_OBJECT())",[$store->audienceId,$store->actorId]);
    $keys=array_column(audienceBellItems($first),'key');$foreign=array_column(audienceBellItems($second),'key');
    audienceDismissBellItems($first,[$keys[0],$foreign[0],str_repeat('f',64)]);
    $expect(audienceBellDismissals($first)===[$keys[0]],'Only eligible notifications can be dismissed.');
    $expect(audienceBellDismissals($second)===[]&&audienceBellDismissals($other)===[],'User and audience scope are isolated.');
    audienceDismissBellItems($first,[$keys[1]]);audienceDismissBellItems($first,[$keys[0]]);
    $expect(count(audienceBellDismissals($first))===2,'Independent additive writes merge and remain idempotent.');
    $job=$first->one('SELECT id FROM marketing_jobs WHERE actor_user_id=? AND audience_id=1 ORDER BY id DESC LIMIT 1',[$users[0]]);
    $first->write("UPDATE marketing_jobs SET state='failed' WHERE id=?",[$job['id']]);
    $state=audienceBellState($first);$expect(count($state['cleared'])===1,'A changed job version is a new notification.');
    try{audienceDismissBellItems($first,['bad']);$expect(false,'Malformed keys accepted.');}catch(InvalidArgumentException){$checks++;}
    try{audienceDismissBellItems($first,array_fill(0,501,str_repeat('a',64)));$expect(false,'Oversized batch accepted.');}catch(InvalidArgumentException){$checks++;}
    $first->write('DELETE FROM marketing_jobs WHERE actor_user_id=?',[$users[0]]);
    $first->write('DELETE FROM users WHERE id=?',[$users[0]]);
    $expect(audienceBellDismissals($first)===[],'Deleting a user removes their dismissal records.');
    echo "Notification state: $checks checks passed.\n";
}finally{
    foreach($users as $id){$db->execute_query('DELETE FROM marketing_notification_dismissals WHERE user_id=?',[$id]);$db->execute_query('DELETE FROM marketing_jobs WHERE actor_user_id=?',[$id]);$db->execute_query('DELETE FROM users WHERE id=?',[$id]);}
    if($audience)$db->execute_query('DELETE FROM marketing_audiences WHERE id=?',[$audience]);
}
