<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Audience HTTP tests skipped (disposable environment required).\n");
// Keep all authentication/session changes inside a labelled disposable deployment.
ob_start();
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once $source.'/audience_flow_runtime.php';require_once __DIR__.'/integration_auth_helpers.php';
$conn=applicationDatabaseConnection();$checks=0;
function audienceHttpExpect(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function audienceHttpCsrf(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$m);if(empty($m[1]))preg_match('/"csrf":"([^"]+)"/',$response['body'],$m);if(empty($m[1]))throw new RuntimeException('Missing Audience CSRF: '.$response['status']);return html_entity_decode($m[1],ENT_QUOTES);}
$clients=[];$cookies=[];$suffix=bin2hex(random_bytes(5));
try{
    foreach(['admin'=>'admin','editor'=>'editor','reviewer'=>'reviewer']as$clientKey=>$role){
        $username='audience-'.$clientKey.'-'.$suffix;$password=bin2hex(random_bytes(16));
        $conn->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$role]);
        $cookie=tempnam(sys_get_temp_dir(),'audience-cookie-');$cookies[]=$cookie;
        $client=static function(string $path,?array $post=null,bool $multipart=false)use($cookie):array{$curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$multipart?$post:http_build_query($post));$raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$result=['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];return $result;};
        $login=$client('login.php');$login=$client('login.php',['csrf_token'=>audienceHttpCsrf($login),'username'=>$username,'password'=>$password]);$login=finishIntegrationTestEnrollment($client,$login);audienceHttpExpect($login['status']===302,'Fixture login '.$clientKey);$clients[$clientKey]=$client;
    }
    $admin=$clients['admin'];$editor=$clients['editor'];$reviewer=$clients['reviewer'];
    $adminSessionId='';$adminSessionName='';
    foreach(file($cookies[0],FILE_IGNORE_NEW_LINES)as$line){$parts=explode("\t",$line);if(count($parts)===7&&($parts[5]===session_name()||str_starts_with($parts[5],'MOED_'))){$adminSessionName=$parts[5];$adminSessionId=$parts[6];}}
    audienceHttpExpect($adminSessionId!=='','Admin fixture has a session cookie');
    $store=new AudienceStore($conn,1,1,'Campaign lifecycle qualification');
    $send=static function(callable $client,string $path,array $values=[]):array{$page=$client($path);return $client($path,array_replace(['csrf_token'=>audienceHttpCsrf($page),'action'=>'flow_operation'],$values));};
    $unlock=static function(?int $at)use($adminSessionName,$adminSessionId):void{session_name($adminSessionName);session_id($adminSessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
    $path='audience.php?view=automations&tab=flows&q=Lifecycle&sort=updated';$archivedPath=$path.'&trash=1';
    $d=audienceFlowDefault();$d['trigger']=['type'=>'manual'];$d['nodes']=[['id'=>'finish','type'=>'exit']];$d['start']='finish';
    $id=$store->saveFlow('Lifecycle flow '.$suffix,$d);$store->flowOperation($id,1,'activate');$flow=$store->flow($id);$clock=time();
    $person=$store->savePerson(['email'=>'flow-lifecycle-'.$suffix.'@example.test','email_status'=>'non-subscribed']);$recipientPerson=$store->savePerson(['email'=>'flow-receipt-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    $run=$store->flowLock($id,fn()=>$store->transaction(fn()=>audienceFlowEnroll($store,$flow,$flow['document'],$person,'manual:'.$suffix,$clock)));
    $store->write("INSERT INTO marketing_flow_steps(audience_id,run_id,node_key,state,result_json) VALUES (1,?,'finish','waiting',JSON_OBJECT())",[$run]);
    $campaign=$store->createCampaign(['name'=>'Flow receipt campaign '.$suffix,'campaign_type'=>'plain-text']);$snapshot=\Dnr\Security\ApplicationKey::seal(json_encode(['draft'=>['text'=>'Previously sent mail'],'identity'=>['LIST:NAME'=>'Lifecycle fixture'],'base'=>'https://example.test'],JSON_THROW_ON_ERROR));
    $store->write("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label,flow_run_id,flow_node) VALUES (1,?,1,'paused',UTC_TIMESTAMP(),'America/Chicago',?,?,2,1,'Lifecycle fixture',?,'finish')",[$campaign,$snapshot,hash('sha256','flow-asset-'.$suffix),$run]);$delivery=(int)$conn->insert_id;
    $tokens=[];$receipts=[];
    foreach([$person=>'queued',$recipientPerson=>'accepted']as$contact=>$state){$token=bin2hex(random_bytes(32));$tokens[$state]=$token;$email=$store->person($contact)['email'];$store->write('INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at) VALUES (1,?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$delivery,$contact,audienceDispatchEmailHash($email),\Dnr\Security\ApplicationKey::seal('{}'),hash('sha256',$token),'<flow-lifecycle-'.$contact.'-'.$suffix.'@example.test>',$state]);$receipts[$state]=(int)$conn->insert_id;}
    $store->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (1,?,'delivered',?,'fixture',UTC_TIMESTAMP(),JSON_OBJECT())",[$receipts['accepted'],hash('sha256','flow-receipt-'.$suffix)]);
    $op=['operation'=>'archive','id'=>$id,'version'=>2];
    audienceHttpExpect($send($reviewer,$path,$op)['status']===403,'Reviewer cannot archive');
    audienceHttpExpect($editor($path,['csrf_token'=>'invalid','action'=>'flow_operation']+$op)['status']===400,'Archive requires CSRF');
    $stale=$send($editor,$path,array_replace($op,['version'=>1,'format'=>'json']));audienceHttpExpect($stale['status']===422&&$store->flow($id)['state']==='running','Archive rejects stale version without stopping the flow');
    $restoreActive=$send($editor,$path,array_replace($op,['operation'=>'restore','format'=>'json']));audienceHttpExpect($restoreActive['status']===422&&str_contains($restoreActive['body'],'Only archived'),'Restore rejects an active flow');
    $archive=$send($editor,$path,$op);$archived=$store->flow($id,false,true);
    audienceHttpExpect($archive['status']===302&&str_contains($archive['headers'],'q=Lifecycle')&&str_contains($archive['headers'],'sort=updated')&&$archived['deleted_at']&&$archived['state']==='paused','Editor archives flow and preserves list context');
    audienceHttpExpect($store->one('SELECT state FROM marketing_flow_runs WHERE id=?',[$run])['state']==='exited'&&$store->one('SELECT state FROM marketing_campaign_deliveries WHERE id=?',[$delivery])['state']==='cancelled'&&$store->one('SELECT state FROM marketing_campaign_recipients WHERE id=?',[$receipts['queued']])['state']==='cancelled','Archive stops queued execution and mail');
    audienceHttpExpect($store->flowReport($id)['total']===1&&$store->one('SELECT id FROM marketing_flow_revisions WHERE flow_id=?',[$id]),'Archive preserves revisions and execution history');
    $editorList=$editor($archivedPath);$adminList=$admin($archivedPath);audienceHttpExpect($editorList['status']===200&&str_contains($editorList['body'],'Restore Lifecycle flow')&&!str_contains($editorList['body'],'data-flow-lifecycle="delete"'),'Editor can restore archived flows without delete controls');
    audienceHttpExpect(str_contains($adminList['body'],'data-flow-lifecycle="delete"')&&str_contains($adminList['body'],'data-admin-unlock-required')&&!str_contains($adminList['body'],'>Trash</a>'),'Active/Archived tabs replace Trash and admin gets protected deletion');
    audienceHttpExpect(!str_contains($reviewer($archivedPath)['body'],'Restore Lifecycle flow'),'Reviewer cannot restore');
    $report=$editor('audience.php?view=flow-report&id='.$id);audienceHttpExpect($report['status']===200&&str_contains($report['body'],'Archived flows')&&!str_contains($report['body'],'data-open-dialog="flow-add-person"'),'Archived report is readable without entry controls');
    audienceHttpExpect($editor('audience.php?view=flow&id='.$id)['status']===404,'Archived flow cannot be edited');
    $retry=$send($editor,'audience.php?view=flow-report&id='.$id,['action'=>'flow_add','run_id'=>$run,'contact_id'=>$person,'entry_key'=>str_repeat('a',32),'format'=>'json','id'=>$id]);audienceHttpExpect($retry['status']===422,'Archived flows reject direct enrollment');
    $restored=$send($editor,$archivedPath,['operation'=>'restore','id'=>$id,'version'=>3]);$restoredFlow=$store->flow($id);audienceHttpExpect($restored['status']===302&&str_contains($restored['headers'],'trash=1')&&$restoredFlow['state']==='draft'&&$restoredFlow['published_revision']===null&&$restoredFlow['paused_nodes']===[],'Restore creates a private draft and retains Archived context');
    audienceHttpExpect($store->one('SELECT state FROM marketing_flow_runs WHERE id=?',[$run])['state']==='exited'&&$store->one('SELECT state FROM marketing_campaign_deliveries WHERE id=?',[$delivery])['state']==='cancelled','Restore does not resume prior runs or deliveries');
    $delete=['operation'=>'delete','id'=>$id,'version'=>4,'confirm'=>'DELETE','format'=>'json'];
    audienceHttpExpect($send($editor,$path,$delete)['status']===403&&$send($reviewer,$path,$delete)['status']===403,'Only admins can delete by direct POST');
    $unlock(time());$active=$send($admin,$path,$delete);audienceHttpExpect($active['status']===422&&str_contains($active['body'],'Only archived'),'Even unlocked admin must archive first');
    $send($editor,$path,['operation'=>'archive','id'=>$id,'version'=>4]);$delete['version']=5;
    $unlock(null);$locked=$send($admin,$archivedPath,$delete);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'admin_elevation.php'),'Deletion requires Admin Unlock');
    $unlock(time()-300);$expired=$send($admin,$archivedPath,$delete);audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php'),'Expired unlock cannot delete');
    $unlock(time());audienceHttpExpect($admin($archivedPath,['csrf_token'=>'bad','action'=>'flow_operation']+$delete)['status']===400,'Unlocked delete still requires CSRF');
    $blank=$send($admin,$archivedPath,array_replace($delete,['confirm'=>'']));audienceHttpExpect($blank['status']===422&&str_contains($blank['body'],'Type DELETE'),'Delete requires typed confirmation');
    $stale=$send($admin,$archivedPath,array_replace($delete,['version'=>3]));audienceHttpExpect($stale['status']===422&&$store->flow($id,false,true),'Delete rejects stale version');
    $conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Foreign flow lifecycle',JSON_OBJECT())");$other=new AudienceStore($conn,(int)$conn->insert_id,1,'Foreign fixture');$foreign=$other->saveFlow('Foreign lifecycle flow',$d);$other->flowOperation($foreign,1,'archive');
    $cross=$send($admin,$archivedPath,array_replace($delete,['id'=>$foreign,'version'=>2]));audienceHttpExpect($cross['status']===422&&$other->flow($foreign,false,true),'Delete cannot cross audience boundaries');
    $store->write("UPDATE marketing_campaign_recipients SET state='processing' WHERE id=?",[$receipts['accepted']]);$processing=$send($admin,$archivedPath,$delete);audienceHttpExpect($processing['status']===422&&str_contains($processing['body'],'processing email receipts')&&$store->flow($id,false,true),'Deletion waits for processing email receipts');$store->write("UPDATE marketing_campaign_recipients SET state='accepted' WHERE id=?",[$receipts['accepted']]);
    $deleted=$send($admin,$archivedPath,array_diff_key($delete,['format'=>true]));audienceHttpExpect($deleted['status']===302&&str_contains($deleted['headers'],'trash=1')&&str_contains($deleted['headers'],'q=Lifecycle'),'Delete returns to the same Archived filters');
    foreach(['marketing_flow_steps'=>'run_id','marketing_flow_runs'=>'flow_id','marketing_flow_revisions'=>'flow_id','marketing_flows'=>'id']as$table=>$column)audienceHttpExpect(!$store->one('SELECT id FROM '.$table.' WHERE '.$column.'=?',[$table==='marketing_flow_steps'?$run:$id]),'Delete cleans '.$table);
    audienceHttpExpect($store->person($person)&&$store->one("SELECT id FROM marketing_contact_events WHERE contact_id=? AND event_type='flow_started'",[$person]),'Delete preserves connections and their event history');
    audienceHttpExpect($store->one('SELECT id FROM marketing_campaign_deliveries WHERE id=?',[$delivery])&&$store->one('SELECT id FROM marketing_campaign_recipients WHERE id=?',[$receipts['accepted']])&&$store->one('SELECT id FROM marketing_message_events WHERE recipient_id=?',[$receipts['accepted']]),'Delete retains immutable delivery receipts');
    $unsubscribe=$editor('audience_subscription.php?token='.$tokens['accepted'],['List-Unsubscribe'=>'One-Click']);audienceHttpExpect($unsubscribe['status']===200&&$store->person($recipientPerson)['email_status']==='unsubscribed','Sent unsubscribe links still work after flow deletion');
    audienceHttpExpect($admin('audience.php?view=flow-report&id='.$id)['status']===404&&$other->flow($foreign,false,true),'Deleted reports are unavailable and other audiences are intact');
    audienceHttpExpect((int)$store->one('SELECT IS_FREE_LOCK(?) free',['mm-flow-1-'.$id])['free']===1,'Lifecycle releases the flow lock');
    $unlock(null);echo "Flow lifecycle HTTP checks passed: $checks\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
