<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('DNR_LOCAL_SAMPLE_DATA')!=='localhost:8080')exit("Explicit localhost sample-data opt-in required.\n");
if(!is_file('/usr/local/etc/php/conf.d/zz-dnr-development.ini'))throw new RuntimeException('Development bind-mounted runtime required.');
require_once '/var/www/html/bootstrap.php';require_once '/var/www/html/audience_store.php';
require_once '/opt/dnr/tests/audience_analytics_fixtures.php';
if(currentAccountKey()!=='shalom-in-messiah')throw new RuntimeException('Only the requested local account may be seeded.');
$db=applicationDatabaseConnection();$user=$db->query("SELECT id FROM users WHERE role='admin' AND account_status='active' ORDER BY id LIMIT 1")->fetch_assoc();
if(!$user)throw new RuntimeException('Existing administrator required.');
$store=new AudienceStore($db,1,(int)$user['id'],'Local sample fixture');
$before=$store->one('SELECT production_access,enabled FROM marketing_ses_settings WHERE audience_id=1');
$result=audienceAnalyticsFixture($store);
$reports=[];
foreach(['email'=>'Sample · Email performance','audience'=>'Sample · Audience growth']as$dataset=>$name){
    $existing=null;foreach($store->analyticsSavedReports(true)as$r)if($r['name']===$name){$existing=$r;break;}
    if($existing){$reports[]=$existing['key'];continue;}
    $presets=audienceAnalyticsPresets(false);$definition=['name'=>$name,'description'=>'Fictional local sample report for testing; no messages were sent.','dataset'=>$dataset,'start'=>$presets[90]['start'],'end'=>$presets[90]['end'],'shared'=>'1'];
    if($dataset==='email')$definition+=['messages_mode'=>'selected','messages'=>$result['campaigns'],'columns'=>['accepted','open_rate','click_rate','bounced'],'plots'=>['accepted','click_rate']];
    else$definition+=['source'=>'Local sample data'];
    $reports[]=$store->analyticsSaveReport($definition,true);
}
$threads=[];
foreach(['Question about the next gathering','Thank you for the newsletter','A story from our community']as$i=>$subject){
    $threads[]=$store->inboxReceive('direct',0,'sample-reader-'.($i+1).'@example.test','Sample reader '.($i+1),'Sample · '.$subject,"LOCAL SAMPLE DATA — fictional message for testing and viewing.\n\n".['Could you share more about the upcoming gathering?','Thank you for keeping our community connected.','We would love to share a short community update in a future edition.'][$i],hash('sha256','stage7-local-inbox-'.$i),false);
}
if($before!==$store->one('SELECT production_access,enabled FROM marketing_ses_settings WHERE audience_id=1'))throw new RuntimeException('Sending gates changed unexpectedly.');
echo json_encode($result+['sample_reports'=>$reports,'inbox_threads'=>$threads,'sending_gates_unchanged'=>true,'external_messages_sent'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
