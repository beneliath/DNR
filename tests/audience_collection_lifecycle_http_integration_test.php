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
    $store=new AudienceStore($conn,1,1,'Form lifecycle qualification');
    $send=static function(callable $client,string $path,array $values=[]):array{$page=$client($path);return $client($path,['csrf_token'=>audienceHttpCsrf($page),'action'=>'collection_operation']+$values);};
    $unlock=static function(?int $at)use($adminSessionName,$adminSessionId):void{session_name($adminSessionName);session_id($adminSessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
    $public=static function(string $key):int{$curl=curl_init('http://127.0.0.1/audience_collect_public.php?key='.$key);curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);curl_exec($curl);return curl_getinfo($curl,CURLINFO_RESPONSE_CODE);};
    $fixtures=[];
    foreach(AUDIENCE_COLLECTION_KINDS as$kind){
        $id=$store->saveCollection(['kind'=>$kind,'name'=>'Lifecycle '.$kind.' '.$suffix]);$draft=$store->collectionResource($id)['draft'];$draft['origins']=['https://example.test'];
        $store->saveCollection(['name'=>'Lifecycle '.$kind.' '.$suffix,'document'=>$draft],$id,1);$store->collectionOperation($id,2,'publish');$row=$store->collectionResource($id);$fixtures[$kind]=$row;
        $path='audience.php?view='.match($kind){'survey'=>'surveys','landing'=>'landing-pages','preferences'=>'preferences',default=>'forms'}.'&q=Lifecycle&type='.$kind.'&per_page=10&direction=asc&page=1';
        $archived=$send($editor,$path,['id'=>$id,'version'=>$row['version'],'operation'=>'archive']);
        audienceHttpExpect($archived['status']===302&&str_contains($archived['headers'],'q=Lifecycle')&&str_contains($archived['headers'],'type='.$kind)&&str_contains($archived['headers'],'per_page=10')&&str_contains($archived['headers'],'direction=asc'),'Archiving preserves list context for '.$kind);
        $archivedRow=$store->collectionResource($id,false,true);audienceHttpExpect($archivedRow['deleted_at']!==null&&$archivedRow['state']==='paused'&&$public($row['public_key'])===404,'Archive stops public collection for '.$kind);
        $archivedPath=$path.'&trash=1';$archivePage=$editor($archivedPath);
        audienceHttpExpect($archivePage['status']===200&&str_contains($archivePage['body'],'Restore Lifecycle '.$kind)&&!str_contains($archivePage['body'],'data-collection-lifecycle="delete"'),'Editors can restore archived '.$kind.' without delete controls');
        $restored=$send($editor,$archivedPath,['id'=>$id,'version'=>$archivedRow['version'],'operation'=>'restore']);
        $restoredRow=$store->collectionResource($id);audienceHttpExpect($restored['status']===302&&str_contains($restored['headers'],'trash=1')&&$restoredRow['state']==='draft'&&$restoredRow['published_revision']===null&&$public($row['public_key'])===404,'Restore keeps '.$kind.' private until republished');
        $store->collectionOperation($id,(int)$restoredRow['version'],'publish');audienceHttpExpect($store->collectionPublished($row['public_key'])!==null,'Restored '.$kind.' can republish the same public link');
    }
    $target=$store->collectionResource((int)$fixtures['hosted']['id']);$id=(int)$target['id'];$path='audience.php?view=forms&q=Lifecycle&type=hosted&per_page=10&direction=asc';
    $contact=$store->savePerson(['email'=>'lifecycle-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    $store->event($contact,'signup-permission',['resource_id'=>$id,'permission'=>'Keep this consent evidence']);
    $store->write('INSERT INTO marketing_collection_responses(audience_id,resource_id,revision_id,receipt_hash,visitor_hash,contact_id,payload_ciphertext) VALUES (1,?,?,?,?,?,?)',[$id,$target['published_revision'],hash('sha256','receipt-'.$suffix),hash('sha256','visitor-'.$suffix),$contact,\Dnr\Security\ApplicationKey::seal('{"note":"Keep this result while archived"}')]);
    $store->write('INSERT INTO marketing_collection_visits(resource_id,day,visitor_hash) VALUES (?,UTC_DATE(),?)',[$id,hash('sha256','visit-'.$suffix)]);
    $mutate=['id'=>$id,'version'=>$target['version'],'operation'=>'archive'];
    audienceHttpExpect($send($reviewer,$path,$mutate)['status']===403,'Reviewers cannot archive forms');
    audienceHttpExpect($editor($path,['csrf_token'=>'invalid','action'=>'collection_operation']+$mutate)['status']===400,'Archive requires CSRF');
    $bad=$send($editor,$path,array_replace($mutate,['version'=>1]));audienceHttpExpect(str_contains($bad['body'],'This form changed.')&&$store->collectionResource($id),'Stale archive is rejected without changing the form');
    $send($editor,$path,$mutate);$archived=$store->collectionResource($id,false,true);$archivedPath=$path.'&trash=1';
    audienceHttpExpect($editor('audience.php?view=collection-report&id='.$id)['status']===200&&count(iterator_to_array($store->collectionResponses($id)))===1,'Archive preserves readable results');
    $delete=['id'=>$id,'version'=>$archived['version'],'operation'=>'delete','confirm'=>'DELETE'];
    audienceHttpExpect($send($editor,$archivedPath,$delete)['status']===403,'Editors cannot delete forms by direct POST');
    audienceHttpExpect($send($reviewer,$archivedPath,$delete)['status']===403,'Reviewers cannot delete forms by direct POST');
    $unlock(null);$locked=$send($admin,$archivedPath,$delete);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'admin_elevation.php'),'Permanent deletion requires Admin Unlock');
    $unlock(time()-300);$expired=$send($admin,$archivedPath,$delete);audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php'),'Expired unlock cannot delete forms');
    $unlock(time());
    audienceHttpExpect($admin($archivedPath,['csrf_token'=>'invalid','action'=>'collection_operation']+$delete)['status']===400,'Unlocked deletion still requires CSRF');
    $blank=$send($admin,$archivedPath,array_replace($delete,['confirm'=>'']));audienceHttpExpect(str_contains($blank['body'],'Type DELETE')&&$store->collectionResource($id,false,true),'Permanent deletion requires typed confirmation');
    $stale=$send($admin,$archivedPath,array_replace($delete,['version'=>1]));audienceHttpExpect(str_contains($stale['body'],'This form changed.')&&$store->collectionResource($id,false,true),'Permanent deletion rejects stale versions');
    $active=$store->collectionResource((int)$fixtures['popup']['id']);$activeDelete=$send($admin,$archivedPath,array_replace($delete,['id'=>$active['id'],'version'=>$active['version']]));audienceHttpExpect(str_contains($activeDelete['body'],'Only archived forms')&&$store->collectionResource((int)$active['id']),'Active forms must be archived before deletion');
    $conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Lifecycle foreign audience',JSON_OBJECT())");$other=new AudienceStore($conn,(int)$conn->insert_id,1,'Foreign lifecycle');$foreign=$other->saveCollection(['kind'=>'hosted','name'=>'Foreign lifecycle']);$other->collectionOperation($foreign,1,'archive');
    $foreignDelete=$send($admin,$archivedPath,array_replace($delete,['id'=>$foreign,'version'=>2]));audienceHttpExpect(str_contains($foreignDelete['body'],'unavailable')&&$other->collectionResource($foreign,false,true),'Deletion cannot cross audience boundaries');
    // A delivered confirmation remains valid after its originating form is permanently deleted.
    $confirmation=bin2hex(random_bytes(32));$operation=hash('sha256',$confirmation);
    $store->write("INSERT INTO marketing_service_mail(audience_id,contact_id,kind,operation_key,payload_ciphertext,message_id,state,next_attempt_at,expires_at) VALUES (1,?,'confirmation',?,'','<lifecycle@example.test>','accepted',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))",[$contact,$operation]);$mail=(int)$conn->insert_id;
    $store->write('INSERT INTO marketing_consent_challenges(audience_id,contact_id,email_hash,token_hash,service_mail_id,expires_at) VALUES (1,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))',[$contact,audienceDispatchEmailHash('lifecycle-'.$suffix.'@example.test'),hash('sha256',$confirmation),$mail]);$challenge=(int)$conn->insert_id;
    $store->event($contact,'collection-confirmation',['resource_id'=>$id,'revision_id'=>(int)$target['published_revision'],'variant_key'=>'A','challenge_id'=>$challenge]);
    $deleted=$send($admin,$archivedPath,$delete);audienceHttpExpect($deleted['status']===302&&str_contains($deleted['headers'],'trash=1')&&str_contains($deleted['headers'],'q=Lifecycle'),'Deletion returns to the same Archived filters');
    foreach(['resources'=>'id','responses'=>'resource_id','revisions'=>'resource_id','visits'=>'resource_id']as$table=>$column)audienceHttpExpect(!$store->one('SELECT '.$column.' FROM marketing_collection_'.$table.' WHERE '.$column.'=?',[$id]),'Permanent deletion cleans form-owned '.$table);
    audienceHttpExpect($store->person($contact)['email_status']==='non-subscribed'&&$store->one("SELECT id FROM marketing_contact_events WHERE contact_id=? AND event_type='signup-permission'",[$contact]),'Form deletion preserves contacts and consent history');
    audienceHttpExpect($other->collectionResource($foreign,false,true)&&$store->collectionResource((int)$active['id']),'Form deletion preserves other forms and audiences');
    audienceHttpExpect($store->confirmSubscription($confirmation)&&$store->person($contact)['email_status']==='subscribed'&&!$store->confirmSubscription($confirmation),'Delivered opt-in confirmation still works exactly once after form deletion');
    audienceHttpExpect($admin('audience.php?view=collection-report&id='.$id)['status']===404&&$public($target['public_key'])===404,'Deleted form results and public routes are unavailable');
    $unlock(null);
    echo "Form lifecycle HTTP checks passed: $checks\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
