<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Audience HTTP tests skipped (disposable environment required).\n");
// Sub-suites print progress before later checks update synthetic session state.
ob_start();
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once $source.'/audience_flow_runtime.php';require_once __DIR__.'/integration_auth_helpers.php';
$conn=applicationDatabaseConnection();$checks=0;
function audienceHttpExpect(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function audienceHttpCsrf(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$m);if(empty($m[1]))preg_match('/"csrf":"([^"]+)"/',$response['body'],$m);if(empty($m[1]))throw new RuntimeException('Missing Audience CSRF: '.$response['status']);return html_entity_decode($m[1],ENT_QUOTES);}
$clients=[];$cookies=[];$suffix=bin2hex(random_bytes(5));
try{
    foreach(['admin'=>'admin','editor'=>'editor','reviewer'=>'reviewer','queue-editor'=>'editor']as$clientKey=>$role){
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
    $fs=new AudienceStore($conn,1,1,'Flow unlock qualification');$clock=time();
    $flowPerson=$fs->savePerson(['email'=>'flow-unlock-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    $newFlow=static function(array $d,string $name)use($fs):array{$id=$fs->saveFlow($name,$d);$fs->flowOperation($id,1,'activate');return $fs->flow($id);};
    $enroll=static fn(array $flow)=>$fs->flowLock((int)$flow['id'],fn()=>$fs->transaction(fn()=>audienceFlowEnroll($fs,$flow,$flow['document'],$flowPerson,'manual:'.bin2hex(random_bytes(8)),$clock)));
    $d=audienceFlowDefault();$d['trigger']=['type'=>'manual'];$d['nodes']=[['id'=>'finish','type'=>'exit']];$d['start']='finish';
    $conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Flow unlock isolation fixture',JSON_OBJECT())");$other=(int)$conn->insert_id;
    $otherStore=new AudienceStore($conn,$other,1,'Flow unlock isolation');$foreignFlow=$otherStore->saveFlow('Other audience flow',$d);

// Flow removal is an administrator action, including direct requests and an expired unlock.
$removeFlow=$newFlow($d,'Protected removal fixture');$removeId=(int)$removeFlow['id'];$removeRun=$enroll($removeFlow);
$removePost=['action'=>'flow_operation','operation'=>'remove','id'=>$removeId,'version'=>$removeFlow['version'],'confirm'=>'REMOVE'];
$setFlowUnlock=static function(?int $at)use($adminSessionName,$adminSessionId):void{session_name($adminSessionName);session_id($adminSessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
$setFlowUnlock(null);
foreach(['automations','flow','flow-report']as$removeView){
    $removePath='audience.php?view='.$removeView.'&id='.$removeId;
    $editorPage=$editor($removePath);$adminPage=$admin($removePath);
    audienceHttpExpect($editorPage['status']===200&&str_contains($editorPage['body'],'data-flow-lifecycle="archive"'),'Editors can archive flows on '.$removeView);
    audienceHttpExpect($adminPage['status']===200&&str_contains($adminPage['body'],'data-flow-lifecycle="archive"'),'Admins use the archive workflow on '.$removeView);
    audienceHttpExpect(!str_contains($adminPage['body'],'id="flow-remove"')&&!str_contains($adminPage['body'],'id="flow-list-remove"'),'Legacy removal dialogs are replaced on '.$removeView);
    $denied=$editor($removePath,$removePost+['csrf_token'=>audienceHttpCsrf($editorPage)]);
    audienceHttpExpect($denied['status']===403,'Editors cannot bypass flow removal by direct POST on '.$removeView);
    $locked=$admin($removePath,$removePost+['csrf_token'=>audienceHttpCsrf($adminPage)]);
    audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'admin_elevation.php'),'Locked admins must unlock before flow removal on '.$removeView);
}
$removePath='audience.php?view=flow-report&id='.$removeId;$removeToken=audienceHttpCsrf($admin($removePath));
$setFlowUnlock(time()-300);
$expired=$admin($removePath,$removePost+['csrf_token'=>$removeToken,'format'=>'json']);
audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php'),'Expired unlock also blocks direct JSON removal');
audienceHttpExpect((int)$fs->flow($removeId)['version']===(int)$removeFlow['version']&&$fs->one('SELECT state FROM marketing_flow_runs WHERE id=?',[$removeRun])['state']==='active','Denied removals leave the flow and queued connection unchanged');
$setFlowUnlock(time());
audienceHttpExpect($admin($removePath,array_replace($removePost,['csrf_token'=>'invalid']))['status']===400,'Unlocked removal still requires a valid CSRF token');
$removeToken=audienceHttpCsrf($admin($removePath));
audienceHttpExpect($admin($removePath,array_replace($removePost,['csrf_token'=>$removeToken,'format'=>'json','confirm'=>'']))['status']===422,'Unlocked removal still requires typed confirmation');
audienceHttpExpect($admin($removePath,array_replace($removePost,['csrf_token'=>$removeToken,'format'=>'json','version'=>1]))['status']===422,'Unlocked removal still rejects a stale flow version');
audienceHttpExpect($admin($removePath,array_replace($removePost,['csrf_token'=>$removeToken,'format'=>'json','id'=>$foreignFlow,'version'=>1]))['status']===422&&$otherStore->flow($foreignFlow),'Unlocked removal cannot cross audience boundaries');
$removed=$admin($removePath,$removePost+['csrf_token'=>$removeToken]);
audienceHttpExpect($removed['status']===302&&str_contains($removed['headers'],'view=automations')&&!empty($fs->flow($removeId,false,true)['deleted_at'])&&$fs->one('SELECT state FROM marketing_flow_runs WHERE id=?',[$removeRun])['state']==='exited','Unlocked administrators can remove the flow and stop its queued connection');
$setFlowUnlock(null);

    echo "Flow removal unlock HTTP checks passed: $checks\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
