<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable') {echo "Coach worker test requires disposable services.\n";exit;}
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';require_once $source.'/ai_coach_helpers.php';require_once $source.'/ai_coach_history_helpers.php';
$conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')",['coach-worker-'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
$uid=(int)$conn->insert_id; $session='';
require_once __DIR__.'/integration_auth_helpers.php';
try {
    $ids=[];
    for($i=0;$i<4;$i++){
        $uuid=bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
        $r=aiCoachValidateRequest(['question'=>'Explain calendar subscriptions','request_id'=>$uuid],'editor');$created=false;
        $id=aiCoachRecordRequest($conn,$r,$uid,$created);if(!$id||!aiCoachEnqueue($conn,$id,$r))throw new RuntimeException('Could not enqueue synthetic worker job');$ids[]=$id;
    }
    $deadline=microtime(true)+12;
    do {
        $rows=$conn->execute_query('SELECT outcome,response_json,duration_ms FROM ai_coach_requests WHERE user_id=?',[$uid])->fetch_all(MYSQLI_ASSOC);
        $pending=array_filter($rows,static fn($r)=>$r['outcome']==='pending');
        if(!$pending)break;usleep(150000);
    }while(microtime(true)<$deadline);
    if($pending)throw new RuntimeException('Worker processes did not complete the isolated jobs');
    foreach($rows as $row){$answer=json_decode($row['response_json'],true);if($answer['reason']!=='unavailable'||!isset($answer['telemetry']['queue_ms']))throw new RuntimeException('Workers must record backend-offline results and queue delay');}
    // Exercise real authenticated POST -> 202 -> worker -> owner-scoped GET recovery.
    $user=fetchAuthenticationUserById($conn,$uid); $csrf=bin2hex(random_bytes(32));
    session_start(); $_SESSION=['user_id'=>$uid,'username'=>$user['username'],'role'=>'editor','auth_version'=>(int)$user['auth_version'],'auth_complete'=>true,'_csrf_token'=>$csrf];
    completeIntegrationTestMfaSession(); $session=session_id(); session_write_close();
    $uuid=bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
    $http=static function(string $path,?array $body=null)use($session,$csrf):array {
        $h=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>4,CURLOPT_COOKIE=>'PHPSESSID='.$session,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$csrf]]);
        if($body!==null)curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body)]);
        $raw=curl_exec($h);return ['status'=>curl_getinfo($h,CURLINFO_RESPONSE_CODE),'body'=>$raw];
    };
    $accepted=$http('ai_coach.php',['question'=>'How does a private calendar feed work?','page'=>'help.php','request_id'=>$uuid]);
    if($accepted['status']!==202 || !json_decode($accepted['body'],true)['pending']) throw new RuntimeException('Generic question must return a durable queued acknowledgement');
    if($http('help.php')['status']!==200)throw new RuntimeException('Navigation must remain available after enqueue');
    $end=microtime(true)+5;
    do { $status=$http('ai_coach.php?request_id='.$uuid);$data=json_decode($status['body'],true);if(($data['state']??'')==='complete')break;usleep(100000); }while(microtime(true)<$end);
    if(($data['state']??'')!=='complete'||$data['response']['failure']['code']!=='connection')throw new RuntimeException('Worker failure must resolve as a diagnostic response');
    echo "Real coach workers passed four-job execution and authenticated asynchronous HTTP recovery/navigation.\n";
} finally {if($session!==''){session_id($session);session_start();$_SESSION=[];session_destroy();}$conn->execute_query('DELETE FROM ai_coach_requests WHERE user_id=?',[$uid]);$conn->execute_query('DELETE FROM users WHERE id=?',[$uid]);}
