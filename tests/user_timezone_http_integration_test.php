<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("User timezone HTTP tests require a disposable environment.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once $source.'/email_template_helpers.php';require_once $source.'/ai_coach_history_helpers.php';require_once __DIR__.'/integration_auth_helpers.php';
$db=applicationDatabaseConnection();$suffix=bin2hex(random_bytes(5));$checks=0;$cookies=[];
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$csrf=static function(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$match);if(empty($match[1]))throw new RuntimeException('Missing timezone fixture CSRF');return html_entity_decode($match[1],ENT_QUOTES);};
try{
    $clients=[];$users=[];
    foreach(['editor','reviewer','admin']as$role){
        $name='timezone-'.$role.'-'.$suffix;$password=bin2hex(random_bytes(16));
        $db->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$name,password_hash($password,PASSWORD_DEFAULT),$role]);$users[$role]=(int)$db->insert_id;
        if($role==='admin')$db->execute_query('UPDATE users SET is_superadmin=1 WHERE id=?',[$users[$role]]);
        $cookie=tempnam(sys_get_temp_dir(),'timezone-cookie-');$cookies[]=$cookie;
        $client=static function(string $path,?array $post=null)use($cookie):array{
            $curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>15]);
            if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
            $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);
            return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
        };
        $login=$client('login.php');$login=$client('login.php',['csrf_token'=>$csrf($login),'username'=>$name,'password'=>$password]);$login=finishIntegrationTestEnrollment($client,$login);
        $expect($login['status']===302,'Synthetic '.$role.' signs in');$clients[$role]=$client;
    }
    $editor=$clients['editor'];$reviewer=$clients['reviewer'];
    $store=new AudienceStore($db,1,$users['editor'],'Timezone fixture');
    $templateId=saveEmailMessageTemplate($db,['name'=>'Timezone template '.$suffix,'subject_template'=>'Timezone test','body_template'=>'Synthetic fixture','suggested_roles'=>[],'sort_order'=>999],$users['editor']);
    $db->execute_query('UPDATE email_message_templates SET updated_at=? WHERE id=?',['2026-10-26 12:00:00',$templateId]);
    $email='timezone-contact-'.$suffix.'@example.test';$person=$store->savePerson(['email'=>$email,'email_status'=>'non-subscribed']);
    $db->execute_query('UPDATE marketing_contacts SET created_at=?,updated_at=? WHERE id=?',['2026-10-26 12:00:00','2026-10-26 12:01:00',$person]);
    $db->execute_query('UPDATE marketing_contact_events SET created_at=? WHERE contact_id=?',['2026-10-26 12:00:00',$person]);
    $path='audience.php?view=contacts&q='.rawurlencode($email);
    $page=$editor('profile.php');
    $expect($page['status']===200&&str_contains($page['body'],'name="display_timezone"')&&str_contains($page['body'],'value="America/Chicago"')&&str_contains($page['body'],'Not selected — UTC'),'Profile offers canonical timezone choices and explicit fallback');
    $expect(str_contains($page['body'],'name="user-timezone" content="UTC"'),'Unset preference reaches browser configuration as UTC');
    $audience=$editor($path);
    $expect(str_contains($audience['body'],'2026-10-26 12:00 UTC')&&str_contains($audience['body'],'2026-10-26 12:01 UTC'),'Audience Date Added and Last Changed explicitly show UTC by default');
    $templates=$editor('email_templates.php?q='.rawurlencode('Timezone template '.$suffix));
    $expect(str_contains($templates['body'],'Oct 26, 2026, 12:00 PM UTC'),'Email Templates lists the full Updated timestamp with explicit UTC');
    $save=static function(callable $client,mixed $timezone,array $extra=[])use($csrf):array{
        $page=$client('profile.php');return $client('profile.php',$extra+['csrf_token'=>$csrf($page),'action'=>'save','first_name'=>'Timezone','last_name'=>'Fixture','phone_country_code'=>'+1','phone'=>'','reimbursement_reviewer_email'=>'','display_timezone'=>$timezone]);
    };
    $expect($save($editor,'America/Chicago')['status']===302,'Timezone saves with the normal profile form');
    $expect($db->execute_query('SELECT display_timezone FROM users WHERE id=?',[$users['editor']])->fetch_assoc()['display_timezone']==='America/Chicago','Preference persists to the current user');
    $audience=$editor($path);
    $expect(str_contains($audience['body'],'2026-10-26 07:00 CDT')&&str_contains($audience['body'],'2026-10-26 07:01 CDT'),'Audience list converts both timestamps to the profile timezone');
    $profile=$editor('audience.php?view=contact&id='.$person);
    $expect(str_contains($profile['body'],'Oct 26, 2026, 7:00 AM CDT')&&str_contains($profile['body'],'name="user-timezone" content="America/Chicago"'),'Connection profile and activity use the same timezone as the Audience list');
    $templates=$editor('email_templates.php?q='.rawurlencode('Timezone template '.$suffix));
    $expect(str_contains($templates['body'],'Oct 26, 2026, 7:00 AM CDT'),'Email Templates includes time of day in the selected timezone');
    $campaignId=$store->createCampaign(['name'=>'Timezone calendar '.$suffix,'campaign_type'=>'plain-text']);
    $db->execute_query('UPDATE marketing_campaigns SET created_at=?,updated_at=? WHERE id=?',['2026-10-26 02:00:00','2026-10-26 02:00:00',$campaignId]);
    $calendar=$editor('audience.php?view=campaigns&layout=calendar&month=2026-10&search='.rawurlencode('Timezone calendar '.$suffix));
    $calendarDom=new DOMDocument();@$calendarDom->loadHTML($calendar['body']);$calendarXpath=new DOMXPath($calendarDom);
    $day=$calendarXpath->query('//table[contains(@class,"campaign-calendar")]//td[a[contains(@href,"view=campaign&id='.$campaignId.'")]]/time')->item(0);
    $expect($calendar['status']===200&&$day!==null&&trim($day->textContent)==='25','Campaign calendar groups an instant by the user’s local day');
    $store->saveCampaignTouchpoint(['touchpoint_type'=>'task','name'=>'Timezone midnight task '.$suffix,'start_at'=>'2026-10-26T02:00','end_at'=>'2026-10-26T02:30'],0,0);
    $agenda=$editor('audience.php?view=campaign-manager&planner_layout=agenda&month=2026-10&day=2026-10-25&scale=day');
    $expect($agenda['status']===200&&str_contains($agenda['body'],'Timezone midnight task '.$suffix)&&str_contains($agenda['body'],'21:00')&&str_contains($agenda['body'],'CDT'),'Planner day bounds and agenda timestamps follow the profile timezone across midnight');
    $other=$reviewer($path);
    $expect(str_contains($other['body'],'2026-10-26 12:00 UTC')&&!str_contains($other['body'],'2026-10-26 07:00 CDT'),'Another user does not inherit the first user’s timezone');
    foreach(['Not/AZone',['America/Chicago'],"UTC\n"]as$invalid){
        $response=$save($editor,$invalid);
        $expect($response['status']===200&&str_contains($response['body'],'Choose a timezone from the list.')&&$db->execute_query('SELECT display_timezone FROM users WHERE id=?',[$users['editor']])->fetch_assoc()['display_timezone']==='America/Chicago','Invalid or array-shaped timezone is rejected without changing saved preferences');
    }
    $bad=$editor('profile.php',['csrf_token'=>'invalid','action'=>'save','display_timezone'=>'Asia/Tokyo']);$expect($bad['status']===400,'Timezone update enforces CSRF');
    $expect($save($reviewer,'Asia/Kolkata',['user_id'=>$users['editor']])['status']===302,'Reviewer may update their own display preference');
    $expect($db->execute_query('SELECT display_timezone FROM users WHERE id=?',[$users['editor']])->fetch_assoc()['display_timezone']==='America/Chicago','A submitted foreign user ID cannot change another profile');
    $other=$reviewer($path);$expect(str_contains($other['body'],'2026-10-26 17:30 IST'),'Audience supports fractional timezone offsets');
    $expect($save($editor,'')['status']===302&&$db->execute_query('SELECT display_timezone FROM users WHERE id=?',[$users['editor']])->fetch_assoc()['display_timezone']===null,'Clearing the preference stores NULL');
    $audience=$editor($path);$expect(str_contains($audience['body'],'2026-10-26 12:00 UTC'),'Clearing preference restores explicit UTC throughout Audience');
    $db->execute_query('UPDATE users SET display_timezone=? WHERE id=?',['Asia/Tokyo',$users['editor']]);
    $audience=$editor($path);$expect(str_contains($audience['body'],'2026-10-26 21:00 JST'),'Existing sessions reload changes made on another device');
    $stored=$store->person($person);$expect(str_starts_with($stored['created_at'],'2026-10-26 12:00:00')&&str_starts_with($stored['updated_at'],'2026-10-26 12:01:00'),'Display preferences never rewrite stored UTC instants');
    $admin=$clients['admin'];$expect($save($admin,'America/Chicago')['status']===302,'SuperAdmin display preference saves');
    $request=aiCoachValidateRequest(['question'=>'Timezone request '.$suffix,'page'=>'help.php'],'admin');$requestId=aiCoachRecordRequest($db,$request,$users['admin']);
    $expect($requestId!==null,'Synthetic request history is recorded without calling an AI provider');
    $db->execute_query('UPDATE ai_coach_requests SET created_at=? WHERE id=?',['2026-10-26 12:00:00',$requestId]);
    $requests=$admin('ai_coach_requests.php');$dom=new DOMDocument();@$dom->loadHTML($requests['body']);$xpath=new DOMXPath($dom);
    $stamp=$xpath->query('//tr[td/a[@href="ai_coach_requests.php?id='.$requestId.'"]]//small[contains(@class,"coach-request-timestamp")]')->item(0);
    if(accountsEnabled())$expect($requests['status']===200&&$stamp!==null&&substr_count($stamp->textContent,'7:00 AM CDT')===1&&str_contains($stamp->textContent,'Oct 26, 2026'),'AI Coach Requests shows one full timestamp without a redundant time line');
    else $expect($requests['status']===403,'SuperAdmin request history stays protected on an account-disabled test server');
    echo "User timezone HTTP: $checks checks passed.\n";
}finally{
    if(isset($users['admin']))$db->execute_query("UPDATE users SET role='editor',is_superadmin=0 WHERE id=?",[$users['admin']]);
    foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);
}
