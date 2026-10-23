<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Audience HTTP tests skipped (disposable environment required).\n");
// Sub-suites print progress before later checks update synthetic session state.
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
    $store=new AudienceStore($conn,1,1,'Tag unlock qualification');$path='audience.php?view=tags';
    $unlock=static function(?int $at)use($adminSessionName,$adminSessionId):void{session_name($adminSessionName);session_id($adminSessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
    $send=static function(callable $client,array $values)use($path):array{$page=$client($path);return $client($path,['csrf_token'=>audienceHttpCsrf($page)]+$values);};
    $person=$store->savePerson(['email'=>'tag-unlock-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    foreach(['single','bulk']as$mode){
        $tag=$store->saveTag('Protected '.$mode.' '.$suffix,0,0);$store->write('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$person,$tag]);
        $input=$mode==='single'?['action'=>'delete_tag','id'=>$tag,'version'=>1]:['action'=>'delete_selected_definitions','kind'=>'tags','selected_ids'=>[$tag],'versions'=>[$tag=>1]];
        foreach([$reviewer,$editor]as$client)audienceHttpExpect($send($client,$input)['status']===403,$mode.' tag deletion denies non-admin roles');
        $unlock(null);$locked=$send($admin,$input);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'Location: admin_elevation.php?'),$mode.' direct deletion requires Admin Unlock');
        audienceHttpExpect((bool)$store->one('SELECT id FROM marketing_tags WHERE id=?',[$tag])&&$store->person($person)['tag_ids']===[$tag],$mode.' locked deletion preserves the tag and assignments');
        $unlock(time()-301);$expired=$send($admin,$input);audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php?'),$mode.' expired unlock cannot delete');
        $unlock(time());$page=$admin($path);$invalid=$admin($path,['csrf_token'=>'invalid']+$input);audienceHttpExpect($invalid['status']===400,$mode.' deletion still enforces CSRF');
        $stale=$input;if($mode==='single')$stale['version']=99;else$stale['versions']=[$tag=>99];$changed=$send($admin,$stale);audienceHttpExpect(str_contains($changed['body'],'changed')&&(bool)$store->one('SELECT id FROM marketing_tags WHERE id=?',[$tag]),$mode.' deletion rejects stale versions');
        $deleted=$send($admin,$input);audienceHttpExpect($deleted['status']===302&&!str_contains($deleted['headers'],'admin_elevation.php')&&!$store->one('SELECT id FROM marketing_tags WHERE id=?',[$tag]),$mode.' unlocked admin can delete');
        audienceHttpExpect($store->person($person)['tag_ids']===[],$mode.' deletion keeps the connection');
    }
    $tag=$store->saveTag('Visibility '.$suffix,0,0);
    foreach(['admin'=>$admin,'editor'=>$editor,'reviewer'=>$reviewer]as$role=>$client){$page=$client($path);audienceHttpExpect($page['status']===200&&!preg_match('/(?:Warning|Fatal error):/',$page['body']),$role.' Tags page renders');audienceHttpExpect(str_contains($page['body'],'data-tag-delete-form')===($role==='admin'),$role.' sees only permitted single/bulk delete controls');}
    $segment=$store->saveSegment('Segment unchanged '.$suffix,['match'=>'all','rules'=>[]],0,0);$segmentDelete=$send($editor,['action'=>'delete_selected_definitions','kind'=>'segments','selected_ids'=>[$segment],'versions'=>[$segment=>1]]);audienceHttpExpect($segmentDelete['status']===302&&!$store->one('SELECT id FROM marketing_segments WHERE id=?',[$segment]),'Existing editor segment deletion is unaffected');
    $unlock(null);echo 'Tag deletion unlock: '.$checks." checks passed.\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
ob_end_flush();
