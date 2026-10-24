<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Audience HTTP tests skipped (disposable environment required).\n");
// Keep all authentication/session changes inside a labelled disposable deployment.
ob_start();
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once __DIR__.'/integration_auth_helpers.php';
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
    $send=static function(callable $client,string $path,array $values=[]):array{$page=$client($path);return $client($path,['csrf_token'=>audienceHttpCsrf($page),'action'=>'campaign_operation']+$values);};
    $unlock=static function(?int $at)use($adminSessionName,$adminSessionId):void{session_name($adminSessionName);session_id($adminSessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
    $path='audience.php?view=campaigns&search=Lifecycle&sort=name&direction=asc';$archivedPath=$path.'&trash=1';
    $ids=[];
    foreach(['draft','sent','paused']as$status){
        $id=$store->createCampaign(['name'=>'Lifecycle '.$status.' '.$suffix,'campaign_type'=>'plain-text']);$ids[$status]=$id;
        $store->write('UPDATE marketing_campaigns SET status=? WHERE id=?',[$status,$id]);
        $op=['operation'=>'archive','selected'=>[$id],'versions'=>[$id=>1]];
        audienceHttpExpect($send($reviewer,$path,$op)['status']===403,'Reviewer cannot archive '.$status);
        $response=$send($editor,$path,$op);$row=$store->campaign($id,false,true);
        audienceHttpExpect($response['status']===302&&str_contains($response['headers'],'search=Lifecycle')&&str_contains($response['headers'],'sort=name')&&$row['deleted_at']!==null,'Editor archives '.$status.' and retains filters');
        audienceHttpExpect($editor('audience.php?view=campaign&id='.$id)['status']===404,'Archived '.$status.' cannot be edited');
        $report=$editor('audience.php?view=campaign-delivery&id='.$id);
        audienceHttpExpect($report['status']===200&&str_contains($report['body'],'This campaign is archived')&&!preg_match('/(?:Warning|Fatal error):/',$report['body']),'Archived '.$status.' report is readable');
        $response=$send($editor,$archivedPath,['operation'=>'restore','selected'=>[$id],'versions'=>[$id=>2]]);$row=$store->campaign($id);
        audienceHttpExpect($response['status']===302&&str_contains($response['headers'],'trash=1')&&$row['status']===$status&&$row['deleted_at']===null,'Restore preserves '.$status.' without resuming or resending');
    }
    $draft=$ids['draft'];$sent=$ids['sent'];$paused=$ids['paused'];
    $op=['operation'=>'archive','selected'=>[$draft],'versions'=>[$draft=>3]];
    audienceHttpExpect($editor($path,['csrf_token'=>'bad','action'=>'campaign_operation']+$op)['status']===400,'Archive requires CSRF');
    $bad=$send($editor,$path,array_replace($op,['versions'=>[$draft=>1]]));audienceHttpExpect(str_contains($bad['body'],'selected campaign changed')&&$store->campaign($draft),'Archive rejects stale versions');
    $activeDelete=$send($editor,$path,array_replace($op,['operation'=>'delete','confirm'=>'DELETE']));audienceHttpExpect($activeDelete['status']===403,'Editor cannot directly delete campaigns');
    $unlock(time());$activeDelete=$send($admin,$path,array_replace($op,['operation'=>'delete','confirm'=>'DELETE']));audienceHttpExpect(str_contains($activeDelete['body'],'Only archived campaigns')&&$store->campaign($draft),'Deletion requires archiving first');
    $store->write("UPDATE marketing_campaigns SET status='scheduled' WHERE id=?",[$paused]);$scheduled=$send($editor,$path,['operation'=>'archive','selected'=>[$draft,$paused],'versions'=>[$draft=>3,$paused=>3]]);audienceHttpExpect(str_contains($scheduled['body'],'Pause delivery')&&$store->campaign($draft)&&$store->campaign($paused),'Active deliveries block the whole archive batch atomically');$store->write("UPDATE marketing_campaigns SET status='paused' WHERE id=?",[$paused]);
    // A completed delivery and its recipient token must survive irreversible deletion.
    $person=$store->savePerson(['email'=>'campaign-lifecycle-'.$suffix.'@example.test','email_status'=>'subscribed','consent_note'=>'Disposable lifecycle fixture permission']);$token=bin2hex(random_bytes(32));
    $snapshot=\Dnr\Security\ApplicationKey::seal(json_encode(['draft'=>['subject'=>'Sent subject','text'=>'Already sent content'],'identity'=>['LIST:NAME'=>'Example'],'base'=>'https://example.test'],JSON_THROW_ON_ERROR));
    $store->write("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label) VALUES (1,?,3,'completed',UTC_TIMESTAMP(),'America/Chicago',?,?,1,1,'Lifecycle fixture')",[$sent,$snapshot,hash('sha256','asset-'.$suffix)]);$delivery=(int)$conn->insert_id;
    $store->write("INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at,delivery_started_at,accepted_at) VALUES (1,?,?,?,?,?,?,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$delivery,$person,audienceDispatchEmailHash('campaign-lifecycle-'.$suffix.'@example.test'),\Dnr\Security\ApplicationKey::seal('{}'),hash('sha256',$token),'<lifecycle-'.$suffix.'@example.test>']);$recipient=(int)$conn->insert_id;
    $store->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (1,?,'delivered',?,'fixture',UTC_TIMESTAMP(),JSON_OBJECT())",[$recipient,hash('sha256','event-'.$suffix)]);
    $key=hash('sha256','publication-'.$suffix);$store->write("INSERT INTO marketing_campaign_publications(audience_id,campaign_id,public_key,enabled,archive_listed,title,image_data,html) VALUES (1,?,?,1,1,'Saved title','','Saved published content')",[$sent,$key]);
    $plan=$store->saveCampaignPlan(['name'=>'Lifecycle plan','goal'=>audienceCampaignGoals()[0],'start'=>'2026-10-01','end'=>'2026-10-31'],0,0);
    $store->write("INSERT INTO marketing_campaign_touchpoints(audience_id,plan_id,campaign_id,touchpoint_type,name,start_at,end_at,state,data_json,actor_label) VALUES (1,?,?,'email','Kept calendar item','2026-10-01','2026-10-02','complete',JSON_OBJECT(),'Lifecycle fixture')",[$plan,$sent]);$touchpoint=(int)$conn->insert_id;
    $send($editor,$path,['operation'=>'archive','selected'=>[$sent],'versions'=>[$sent=>3]]);
    $archive=$editor($archivedPath);audienceHttpExpect(str_contains($archive['body'],'data-campaign-op="restore"')&&!str_contains($archive['body'],'data-campaign-op="delete"'),'Editor sees restore without delete controls');
    audienceHttpExpect(!str_contains($reviewer($archivedPath)['body'],'data-campaign-op="restore"'),'Reviewer sees archived records without mutation controls');
    $report=$editor('audience.php?view=campaign-delivery&id='.$sent);audienceHttpExpect($report['status']===200&&str_contains($report['body'],'Accepted'),'Archive preserves sent receipts');
    $delete=['operation'=>'delete','selected'=>[$sent],'versions'=>[$sent=>4],'confirm'=>'DELETE'];
    audienceHttpExpect($send($reviewer,$archivedPath,$delete)['status']===403,'Reviewer cannot delete');
    $unlock(null);$locked=$send($admin,$archivedPath,$delete);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'admin_elevation.php'),'Locked admin cannot delete');
    $unlock(time()-300);$expired=$send($admin,$archivedPath,$delete);audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php'),'Expired unlock cannot delete');
    $unlock(time());audienceHttpExpect($admin($archivedPath,['csrf_token'=>'bad','action'=>'campaign_operation']+$delete)['status']===400,'Unlocked delete still requires CSRF');
    $blank=$send($admin,$archivedPath,array_replace($delete,['confirm'=>'']));audienceHttpExpect(str_contains($blank['body'],'Type DELETE')&&$store->campaign($sent,false,true),'Delete requires typed confirmation');
    $stale=$send($admin,$archivedPath,array_replace($delete,['versions'=>[$sent=>1]]));audienceHttpExpect(str_contains($stale['body'],'selected campaign changed')&&$store->campaign($sent,false,true),'Delete rejects stale versions');
    $conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Foreign campaign lifecycle',JSON_OBJECT())");$other=new AudienceStore($conn,(int)$conn->insert_id,1,'Foreign fixture');$foreign=$other->createCampaign(['name'=>'Foreign lifecycle','campaign_type'=>'regular']);$other->campaignOperation('archive',[$foreign],[$foreign=>1]);
    $cross=$send($admin,$archivedPath,array_replace($delete,['selected'=>[$foreign],'versions'=>[$foreign=>2]]));audienceHttpExpect(str_contains($cross['body'],'unavailable')&&$other->campaign($foreign,false,true),'Delete cannot cross audience boundaries');
    $response=$send($admin,$archivedPath,$delete);audienceHttpExpect($response['status']===302&&str_contains($response['headers'],'trash=1')&&str_contains($response['headers'],'search=Lifecycle'),'Delete keeps archived list filters');
    $row=$store->one('SELECT * FROM marketing_campaigns WHERE id=?',[$sent]);audienceHttpExpect(audienceJson($row['draft_json'])===['_permanently_deleted'=>true]&&$row['name']==='Deleted campaign'&&$row['folder_id']===null,'Delete erases the editable definition');
    $restore=$send($editor,$archivedPath,['operation'=>'restore','selected'=>[$sent],'versions'=>[$sent=>5]]);audienceHttpExpect(str_contains($restore['body'],'unavailable'),'Deleted campaign cannot be restored');
    audienceHttpExpect($admin('audience.php?view=campaign-delivery&id='.$sent)['status']===404&&!str_contains($admin($archivedPath)['body'],'Lifecycle sent '.$suffix),'Deleted campaign is absent from reports and Archived');
    $publication=$store->one('SELECT * FROM marketing_campaign_publications WHERE campaign_id=?',[$sent]);audienceHttpExpect((int)$publication['enabled']===0&&$publication['html']===''&&$store->one('SELECT campaign_id FROM marketing_campaign_touchpoints WHERE id=?',[$touchpoint])['campaign_id']===null,'Delete clears public content and keeps an unassociated calendar item');
    audienceHttpExpect($store->one('SELECT id FROM marketing_campaign_recipients WHERE id=?',[$recipient])&&$store->one('SELECT id FROM marketing_message_events WHERE recipient_id=?',[$recipient]),'Immutable delivery and provider receipts are retained');
    $unsubscribe=$editor('audience_subscription.php?token='.$token,['List-Unsubscribe'=>'One-Click']);audienceHttpExpect($unsubscribe['status']===200&&$store->person($person)['email_status']==='unsubscribed'&&$store->one('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=1 AND email_hash=?',[audienceDispatchEmailHash('campaign-lifecycle-'.$suffix.'@example.test')]),'Previously sent unsubscribe still works after deletion');
    // Selection snapshots do not silently expand to newer records.
    $a=$store->createCampaign(['name'=>'Lifecycle snapshot A '.$suffix,'campaign_type'=>'regular']);$b=$store->createCampaign(['name'=>'Lifecycle snapshot B '.$suffix,'campaign_type'=>'regular']);$late=$store->createCampaign(['name'=>'Lifecycle snapshot late '.$suffix,'campaign_type'=>'regular']);
    $snapshotPost=['operation'=>'archive','selection_scope'=>'all','selection_filters'=>json_encode(audienceCampaignFilters(['search'=>'Lifecycle snapshot'])),'selection_snapshot'=>json_encode([$a=>1,$b=>1])];$r=$send($editor,$path,$snapshotPost);audienceHttpExpect($r['status']===302&&$store->campaign($a,false,true)['deleted_at']&&$store->campaign($b,false,true)['deleted_at']&&$store->campaign($late),'All-matching archive uses the exact selected snapshot');
    $snapshotPost['operation']='restore';$snapshotPost['selection_filters']=json_encode(audienceCampaignFilters(['search'=>'Lifecycle snapshot','trash'=>'1']));$snapshotPost['selection_snapshot']=json_encode([$a=>2,$b=>2]);$r=$send($editor,$archivedPath,$snapshotPost);audienceHttpExpect($r['status']===302&&$store->campaign($a)&&$store->campaign($b),'All-matching restore is scoped to Archived');
    // Large archived designs must not be carried through MySQL's filesort.
    $large=$store->createCampaign(['name'=>'Lifecycle large design '.$suffix,'campaign_type'=>'regular']);$store->write('UPDATE marketing_campaigns SET draft_json=? WHERE id=?',[json_encode(['design'=>['image'=>str_repeat('x',2000000)]]),$large]);$store->campaignOperation('archive',[$large],[$large=>1]);
    foreach(['','regular']as$type){$page=$admin('audience.php?view=campaigns&trash=1&type='.$type);audienceHttpExpect($page['status']===200&&str_contains($page['body'],'Lifecycle large design '.$suffix),'Archived large-design sort stays bounded for type '.$type);}
    audienceHttpExpect((int)$store->one('SELECT IS_FREE_LOCK(?) free',['mm-dispatch-1-'.$sent])['free']===1,'Lifecycle releases dispatch locks after success and rejection');
    $unlock(null);echo "Campaign lifecycle HTTP checks passed: $checks\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
