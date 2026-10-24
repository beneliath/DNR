<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable planner HTTP environment required.\n");
ob_start();
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once __DIR__.'/integration_auth_helpers.php';
$conn=applicationDatabaseConnection();$checks=0;$cookies=[];$clients=[];$suffix=bin2hex(random_bytes(5));
function plannerExpect(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function plannerCsrf(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$match);if(empty($match[1]))preg_match('/"csrf":"([^"]+)"/',$response['body'],$match);if(empty($match[1]))throw new RuntimeException('Planner CSRF form unavailable.');return html_entity_decode($match[1],ENT_QUOTES);}
try{
    foreach(['admin','editor','reviewer']as$role){
        $username='planner-'.$role.'-'.$suffix;$password=bin2hex(random_bytes(16));
        $conn->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$role]);
        $cookie=tempnam('/tmp','planner-cookie-');$cookies[$role]=$cookie;
        $client=static function(string $path,?array $post=null)use($cookie):array{
            $curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
            if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
            $raw=curl_exec($curl);if($raw===false)throw new RuntimeException('Planner HTTP request failed.');
            $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$result=['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];unset($curl);return $result;
        };
        $login=$client('login.php');$login=$client('login.php',['csrf_token'=>plannerCsrf($login),'username'=>$username,'password'=>$password]);$login=finishIntegrationTestEnrollment($client,$login);
        plannerExpect($login['status']===302,'Fixture login '.$role);$clients[$role]=$client;
    }
    $admin=$clients['admin'];$editor=$clients['editor'];$reviewer=$clients['reviewer'];
    $sessionId=$sessionName='';foreach(file($cookies['admin'],FILE_IGNORE_NEW_LINES)as$line){$parts=explode("\t",$line);if(count($parts)===7&&($parts[5]===session_name()||str_starts_with($parts[5],'MOED_'))){$sessionName=$parts[5];$sessionId=$parts[6];}}
    plannerExpect($sessionId!=='','Admin fixture session is isolated');
    $unlock=static function(?int $at)use($sessionId,$sessionName):void{session_name($sessionName);session_id($sessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
    $path='audience.php?view=campaign-manager&planner_layout=list';$archive=$path.'&plan_trash=1';$agenda='audience.php?view=campaign-manager&planner_layout=agenda&day=2026-10-24&scale=month';
    $send=static function(callable $client,string $path,string $action,int $id,int $version,string $operation,array $extra=[])use(&$checks):array{$page=$client($path);plannerExpect($page['status']===200,'Planner page loads');return $client($path,['csrf_token'=>plannerCsrf($page),'action'=>$action,'id'=>$id,'version'=>$version,'operation'=>$operation]+$extra);};
    $store=new AudienceStore($conn,1,1,'Isolated planner qualification');
    $plan=$store->saveCampaignPlan(['name'=>'Planner lifecycle '.$suffix,'goal'=>'Other','start'=>'2026-10-01','end'=>'2026-10-31','notes'=>'Preserve this plan'],0,0);
    $email=$store->createCampaign(['name'=>'Planner draft '.$suffix,'campaign_type'=>'regular']);
    $point=$store->saveCampaignTouchpoint(['name'=>'Planner email '.$suffix,'touchpoint_type'=>'email','plan_id'=>(string)$plan,'campaign_id'=>(string)$email,'start_at'=>'2026-10-24T09:00','end_at'=>'2026-10-24T10:00','notes'=>'Preserve this touchpoint'],0,0);
    $planRow=static fn(int $id)=>$store->one('SELECT * FROM marketing_campaign_plans WHERE id=?',[$id]);
    $pointRow=static fn(int $id)=>$store->one('SELECT * FROM marketing_campaign_touchpoints WHERE id=?',[$id]);
    plannerExpect($send($reviewer,$path,'campaign_plan_operation',$plan,1,'archive')['status']===403,'Reviewer cannot archive plans');
    plannerExpect($send($editor,$path,'campaign_plan_operation',$plan,1,'archive')['status']===302,'Editor archives plans');
    plannerExpect($planRow($plan)['deleted_at']!==null&&(int)$pointRow($point)['plan_id']===$plan,'Archive preserves associations');
    plannerExpect(!str_contains($editor($path)['body'],'Planner lifecycle '.$suffix)&&str_contains($editor($archive)['body'],'Planner lifecycle '.$suffix),'Plan moves from Active to Archive');
    plannerExpect(!str_contains($editor($agenda)['body'],'Planner email '.$suffix),'Archived plan touchpoints leave active planning');
    plannerExpect($send($editor,$archive,'campaign_plan_operation',$plan,2,'restore')['status']===302&&$planRow($plan)['deleted_at']===null,'Editor restores plans');
    plannerExpect(str_contains($editor($agenda)['body'],'Planner email '.$suffix),'Restoring plan restores calendar visibility');
    plannerExpect(str_contains($send($editor,$path,'campaign_plan_operation',$plan,1,'archive')['body'],'This plan changed'),'Stale plan writes fail');
    $unlock(time());plannerExpect(str_contains($send($admin,$path,'campaign_plan_operation',$plan,3,'delete')['body'],'Archive this campaign plan first'),'Active plan cannot be permanently deleted');
    $send($editor,$path,'campaign_plan_operation',$plan,3,'archive');
    plannerExpect($send($editor,$archive,'campaign_plan_operation',$plan,4,'delete')['status']===403,'Editor cannot delete plans');
    foreach([null,time()-600]as$at){$unlock($at);$r=$send($admin,$archive,'campaign_plan_operation',$plan,4,'delete');plannerExpect($r['status']===302&&str_contains($r['headers'],'admin_elevation.php'),'Locked or expired admin cannot delete plans');}
    $unlock(time());plannerExpect($admin($archive,['csrf_token'=>'bad','action'=>'campaign_plan_operation','id'=>$plan,'version'=>4,'operation'=>'delete'])['status']===400,'Unlocked plan deletion still requires CSRF');
    $conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Foreign planner',JSON_OBJECT())");$foreignStore=new AudienceStore($conn,(int)$conn->insert_id,1,'Foreign planner');$foreign=$foreignStore->saveCampaignPlan(['name'=>'Foreign plan','goal'=>'Other','start'=>'2026-10-01','end'=>'2026-10-31'],0,0);$foreignStore->campaignPlanOperation($foreign,1,'archive');
    plannerExpect(str_contains($send($admin,$archive,'campaign_plan_operation',$foreign,2,'delete')['body'],'unavailable')&&$foreignStore->campaignPlan($foreign,false,true),'Plan deletion cannot cross audiences');
    plannerExpect($send($admin,$archive,'campaign_plan_operation',$plan,4,'delete')['status']===302,'Unlocked admin deletes archived plan');
    plannerExpect(!empty(audienceJson($planRow($plan)['data_json'])['_permanently_deleted'])&&$pointRow($point)['plan_id']===null&&$store->campaign($email)['status']==='draft','Deletion removes only the plan definition');
    plannerExpect(str_contains($send($editor,$archive,'campaign_plan_operation',$plan,5,'restore')['body'],'unavailable'),'Deleted plan cannot be restored');
    plannerExpect($send($editor,$agenda,'campaign_touchpoint_operation',$point,2,'archive')['status']===302,'Editor archives touchpoints');
    plannerExpect($pointRow($point)['deleted_at']!==null&&(int)$pointRow($point)['campaign_id']===$email,'Touchpoint archive preserves linked email');
    plannerExpect(str_contains($editor($agenda.'&touchpoint_trash=1')['body'],'Planner email '.$suffix)&&!str_contains($editor($agenda)['body'],'Planner email '.$suffix),'Touchpoint appears only in Archive');
    plannerExpect($send($editor,$agenda,'campaign_touchpoint_operation',$point,3,'restore')['status']===302&&$pointRow($point)['deleted_at']===null,'Editor restores touchpoints');
    plannerExpect($send($editor,$agenda,'campaign_touchpoint_operation',$point,4,'delete')['status']===403,'Editor cannot delete touchpoints');
    foreach([null,time()-600]as$at){$unlock($at);$r=$send($admin,$agenda,'campaign_touchpoint_operation',$point,4,'delete');plannerExpect($r['status']===302&&str_contains($r['headers'],'admin_elevation.php'),'Locked or expired admin cannot delete touchpoints');}
    $unlock(time());plannerExpect($admin($agenda,['csrf_token'=>'bad','action'=>'campaign_touchpoint_operation','id'=>$point,'version'=>4,'operation'=>'delete'])['status']===400,'Touchpoint deletion requires CSRF');
    plannerExpect(str_contains($send($admin,$agenda,'campaign_touchpoint_operation',$point,1,'delete')['body'],'This touchpoint changed'),'Touchpoint deletion rejects stale versions');
    plannerExpect($send($admin,$agenda,'campaign_touchpoint_operation',$point,4,'delete')['status']===302,'Unlocked admin deletes touchpoints');
    plannerExpect(!empty(audienceJson($pointRow($point)['data_json'])['_permanently_deleted'])&&$store->campaign($email)['status']==='draft','Deleting a touchpoint preserves its email draft');
    plannerExpect(str_contains($send($editor,$agenda,'campaign_touchpoint_operation',$point,5,'restore')['body'],'unavailable'),'Deleted touchpoint cannot be restored');
    $unlock(null);echo "Campaign plans and touchpoints: $checks HTTP lifecycle/security checks passed.\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
