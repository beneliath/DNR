<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("Restore session HTTP tests skipped (disposable environment required).\n");
}
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once __DIR__.'/integration_auth_helpers.php';
$checks=0;$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$username='restore-session-'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(16));
$conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'reviewer')",[$username,password_hash($password,PASSWORD_DEFAULT)]);
$jar=tempnam(sys_get_temp_dir(),'restore-session-cookie-');
$request=static function(string $path,?array $post=null)use($jar):array{
    $c=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,
        CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>15]);
    if($post!==null)curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post));
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException('Synthetic session request failed.');
    $head=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);
    return ['status'=>(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$head),'body'=>substr($raw,$head)];
};
$login=static function()use($request,$username,$password):void{
    $form=$request('login.php');preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$form['body'],$csrf);
    if(empty($csrf[1]))throw new RuntimeException('Synthetic login CSRF is unavailable.');
    finishIntegrationTestEnrollment($request,$request('login.php',['csrf_token'=>html_entity_decode($csrf[1],ENT_QUOTES),'username'=>$username,'password'=>$password]));
};
$state=null;
try{
    $login();$before=$request('contacts.php');
    $expect($before['status']===200&&str_contains($before['body'],'id="app-sidebar"'),'A real MFA-authenticated session is established.');
    $state=prepareAccountRestoreSecurity();
    $after=$request('contacts.php');
    $expect($after['status']===302&&str_contains($after['headers'],'Location: login.php'),'The same cookie must reauthenticate after a recovery generation change.');
    $login();$fresh=$request('contacts.php');
    $expect($fresh['status']===200&&str_contains($fresh['body'],'id="app-sidebar"'),'Fresh password/MFA authentication works while delivery remains held.');
    $expect(accountOutboundMailHeld(),'Signing in cannot release the operator mail hold.');
    echo "Restore generation HTTP/session regression: $checks checks passed.\n";
}finally{
    if($state!==null&&accountRestoreGeneration()===$state['generation'])releaseAccountRestoreMailHold($state['generation'],'RECONCILED');
    unlink($jar);
}
