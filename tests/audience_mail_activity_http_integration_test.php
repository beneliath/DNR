<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Mail activity checks require a disposable deployment.\n");
ob_start();$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once __DIR__.'/integration_auth_helpers.php';
$conn=applicationDatabaseConnection();$checks=0;$suffix=bin2hex(random_bytes(6));$cookie=tempnam(sys_get_temp_dir(),'mail-activity-');$mailIds=[];
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$client=static function(string $path,?array $post=null)use($cookie):array{
    $curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$headerSize),'headers'=>substr($raw,0,$headerSize)];
};
$load=static function(array $params=[])use($client,$expect):array{
    $response=$client('audience.php?'.http_build_query(['view'=>'delivery','tab'=>'activity']+$params));
    $expect($response['status']===200&&!preg_match('/(?:Warning|Fatal error|Deprecated):/',$response['body']),'Activity renders without runtime errors');
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response['body']);$xpath=new DOMXPath($dom);
    $nav=$xpath->query('//nav[@data-mm-pagination and @aria-label="Test and confirmation activity pages"]')->item(0);$expect($nav!==null,'Activity uses the standard MessageMonkey pager');
    return [$xpath,$nav];
};
try{
    $username='mail-activity-'.$suffix;$password=bin2hex(random_bytes(16));$conn->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),'admin']);$actor=(int)$conn->insert_id;
    $login=$client('login.php');preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$login['body'],$csrf);
    $login=finishIntegrationTestEnrollment($client,$client('login.php',['csrf_token'=>html_entity_decode($csrf[1],ENT_QUOTES),'username'=>$username,'password'=>$password]));$expect($login['status']===302,'Synthetic administrator signs in');
    $store=new AudienceStore($conn,1,$actor,'Pagination fixture');
    if((int)$store->one('SELECT COUNT(*) n FROM marketing_service_mail WHERE audience_id=1')['n']===0){
        [$xpath,$nav]=$load();$expect(trim($xpath->query('.//*[@class="mm-pagination-range"]',$nav)->item(0)->textContent)==='0 of 0','Empty activity has a zero range');
        $expect($xpath->query('.//button[@disabled]',$nav)->length===2,'Empty activity disables both page arrows');
    }
    for($i=1;$i<=70;$i++){
        // Cancelled fixtures exercise retained receipts without queueing any email.
        $payload=$i===1?'':\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>['email'=>'activity-'.$i.'@example.test']],JSON_THROW_ON_ERROR));
        $conn->execute_query("INSERT INTO marketing_service_mail(audience_id,kind,actor_label,operation_key,payload_ciphertext,message_id,state,next_attempt_at,expires_at) VALUES (1,'confirmation','Pagination fixture',?,?,?,'cancelled',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[hash('sha256',$suffix.':'.$i),$payload,'<activity-'.$suffix.'-'.$i.'@example.test>']);$mailIds[]=(int)$conn->insert_id;
    }
    $allIds=array_map('intval',array_column($store->all('SELECT id FROM marketing_service_mail WHERE audience_id=1 ORDER BY id DESC'),'id'));$total=count($allIds);
    foreach([25,10,50,100]as$size){
        $page=min(2,(int)ceil($total/$size));[$xpath,$nav]=$load(['activity_size'=>$size,'activity_page'=>2,'page_size_change'=>'activity_size']);
        $actual=[];foreach($xpath->query('//tr[@data-service-mail-id]')as$row)$actual[]=(int)$row->getAttribute('data-service-mail-id');
        $expect($actual===array_slice($allIds,($page-1)*$size,$size),'Requested page size returns the exact newest-first slice: '.$size);
        $range=(($page-1)*$size+1).'–'.min($page*$size,$total).' of '.$total;$expect(trim($xpath->query('.//*[@class="mm-pagination-range"]',$nav)->item(0)->textContent)===$range,'Range counts all receipts, including those older than the former 25-row cap');
        $expect($xpath->query('.//select/option[@selected and @value="'.$size.'"]',$nav)->length===1,'The selected size is shown');
        $expect($xpath->query('.//input[@name="activity_page" and @value="1"]',$nav)->length===1,'Changing size returns to the first page');
        foreach($xpath->query('.//a',$nav)as$link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);$expect($params['view']==='delivery'&&$params['tab']==='activity'&&(int)$params['activity_size']===$size,'Page links retain the delivery Activity tab and size');}
    }
    [$xpath,$nav]=$load();$expect($xpath->query('.//select/option[@selected and @value="100"]',$nav)->length===1,'Returning to Activity remembers the size for this administrator');
    [$xpath,$nav]=$load(['activity_size'=>10,'activity_page'=>999999]);$expect(trim($xpath->query('.//*[@class="mm-pagination-page"]',$nav)->item(0)->textContent)===ceil($total/10).' / '.ceil($total/10),'An out-of-range page is clamped to the final page');
    $expect($xpath->query('.//button[@aria-label="Next page" and @disabled]',$nav)->length===1,'Final page disables Next');
    [$xpath,$nav]=$load(['activity_page'=>(int)floor(array_search($mailIds[0],$allIds,true)/10)+1]);
    $expect(str_contains($xpath->query('//tr[@data-service-mail-id="'.$mailIds[0].'"]')->item(0)->textContent,'Recipient unavailable'),'Retired payloads remain visible in older activity');
    [$xpath,$nav]=$load(['activity_size'=>'invalid','activity_page'=>-1]);$expect(trim($xpath->query('.//*[@class="mm-pagination-page"]',$nav)->item(0)->textContent)==='1 / '.ceil($total/10),'Invalid inputs retain the saved size and safely reset the page');
    $expect(array_column($store->serviceMailActivity(10,0),'id')!==array_column($store->serviceMailActivity(10,10),'id'),'Adjacent database pages contain different receipts');
    echo "Mail activity pagination HTTP: $checks checks passed.\n";
}finally{
    // Delivery history is append-only for the web role. These cancelled fixtures
    // remain only in this disposable database and can never be sent.
    if(is_file($cookie))unlink($cookie);
}
ob_end_flush();
