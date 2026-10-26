<?php
declare(strict_types=1);
// Render Campaign and Home controls using a synthetic session and records only.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$view=in_array($argv[4]??'campaigns',['home','campaign-manager'],true)?$argv[4]:'campaigns';$role=$argv[1]??'admin';$tab=$argv[2]??'active';$unlocked=($argv[3]??'locked')==='unlocked';
$_SESSION=['user_id'=>11,'username'=>'fixture-'.$role,'role'=>$role,'_csrf_token'=>'fixture-token'];
if($unlocked)$_SESSION['_admin_elevated_at']=time();
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$_SERVER['PHP_SELF']='audience.php';$_SERVER['QUERY_STRING']=http_build_query(['view'=>$view,'tab'=>$tab]);
$themeKey='template-lifecycle-fixture';$displayName='Example User';$pageTitle=ucwords(str_replace('-',' ',$view));
$canManage=in_array($role,['admin','editor'],true);$canDelete=$role==='admin';
$message=$error='';$csrf=csrfInput();$audienceId=1;
$audience=['name'=>'Example audience','settings_json'=>'{}','version'=>1];
$tags=$groups=$fields=$catalog=$query=$recentDesigns=[];
$rules=['match'=>'all','rules'=>[]];$filters=['q'=>'','status'=>[],'source'=>[],'tags'=>[],'archived'=>'0'];$segment=$job=null;

$campaignFilters=audienceCampaignFilters(['trash'=>$tab==='archived'?'1':'0']);$campaignUrl=static fn(array $changes=[])=>$url(array_replace(['view'=>'campaigns']+$campaignFilters,$changes));$campaignJson=static fn($value)=>$h(json_encode($value,JSON_THROW_ON_ERROR));
$campaignRows=[];foreach(['draft','scheduled','sent']as$i=>$status)$campaignRows[]=['id'=>7+$i,'version'=>3,'name'=>ucfirst($status).' newsletter','status'=>$status,'campaign_type'=>'regular','draft_json'=>json_encode(['subject'=>'Example subject']),'updated_at'=>'2026-10-22 12:00:00','created_at'=>'2026-10-21 12:00:00','sent_at'=>$status==='sent'?'2026-10-22 12:00:00':null,'folder_name'=>'','folder_id'=>null,'actor_label'=>'Example editor'];
$campaignSnapshot=array_column($campaignRows,'version','id');$campaignMetrics=[];$campaignTotal=($argv[3]??'')==='middle'?70:(($argv[3]??'')==='empty'?0:count($campaignRows));if(!$campaignTotal)$campaignRows=[];$campaignPage=($argv[3]??'')==='middle'?2:1;$campaignRecovery=null;$recentCampaigns=[];$canSend=false;
if(($argv[3]??'')==='recent'){
    for($i=0;$i<9;$i++)$recentCampaigns[]=['id'=>70+$i,'name'=>['Community newsletter','Special update','Prayer letter'][$i%3].' '.($i+1),'version'=>3,'status'=>'sent','sent_at'=>sprintf('2026-10-%02d 14:00:00',25-$i)];
}
$folders=[['id'=>4,'name'=>'Newsletters','version'=>1,'campaign_count'=>0]];
$store=new class{public function analyticsAudienceHistory(array $w,string $source=''):array{return ['current'=>[],'previous'=>[]];}public function analyticsReportRows(array $w,array $definition):array{return [];}};
$window=['start'=>'2026-10-01','end'=>'2026-10-22','compare'=>'none','bots'=>'filtered','messages_mode'=>'all','messages'=>[]];$showDrafts=false;$section='home';$rangeKey='custom';$presets=[];
$totals=array_fill_keys(['accepted','delivery_rate','open_rate','click_rate','clicks_per_open','unsubscribe_rate','awaiting_receipt','detected_bot'],0);$overview=['totals'=>$totals,'previous'=>$totals,'audience'=>array_fill_keys(['visible_total','subscribed','added'],0),'includes_samples'=>false,'campaigns'=>[]];
if($view==='campaign-manager'){
    $plan=null;$planRows=$planChoices=[];$plannerEmails=[['id'=>42,'name'=>'October community update'],['id'=>43,'name'=>'Thank you to our volunteers']];
    $plannerLayout=in_array($argv[5]??'agenda',['calendar','agenda','list'],true)?($argv[5]??'agenda'):'agenda';$plannerScale='month';$plannerDate='2026-10-01';$month=new DateTimeImmutable($plannerDate,new DateTimeZone('UTC'));$campaignFilters['holidays']='0';
    $planArchived=$touchpointArchived=($argv[6]??'')==='archive';
    foreach(['Community outreach','November newsletter','Volunteer appreciation']as$i=>$name)$planRows[]=['id'=>51+$i,'version'=>2,'name'=>$name,'data_json'=>json_encode(['goal'=>'Other','start'=>'2026-10-01','end'=>'2026-10-31','notes'=>'Synthetic plan']),'deleted_at'=>$planArchived?'2026-10-20 12:00:00':null];
    $planChoices=$planArchived?[]:$planRows;$planTotal=count($planRows);
    $managerUrl=static fn(array $changes=[])=>$url(array_replace(['view'=>'campaign-manager','planner_layout'=>$plannerLayout,'month'=>'2026-10','day'=>$plannerDate,'scale'=>'month'],$changes));
    $touchpoints=[];
    foreach([
        ['task','Review newsletter copy','in-progress','2026-10-23 09:00:00','2026-10-23 10:00:00','Check links, images and the final audience segment.',0,null],
        ['email','October community update','not-started','2026-10-24 09:00:00','2026-10-24 09:00:00','Share upcoming events and this month’s highlights.',42,'scheduled'],
        ['event','Community webinar','not-started','2026-10-24 17:00:00','2026-10-24 18:00:00','An hour of conversation with our community.',0,null],
        ['task','Finalize November calendar','complete','2026-10-25 13:00:00','2026-10-25 14:00:00','Confirm dates and owners for next month.',0,null],
        ['email','Thank you to our volunteers','not-started','2026-10-26 10:00:00','2026-10-26 10:00:00','A personal note recognizing everyone who helped.',43,'draft'],
        ['event','Campaign wrap-up','complete','2026-10-28 15:00:00','2026-10-29 16:00:00','Review results and capture ideas for the next campaign.',0,null],
    ]as$i=>[$type,$name,$state,$start,$end,$notes,$email,$emailStatus])$touchpoints[]=['id'=>21+$i,'version'=>2,'plan_id'=>null,'touchpoint_type'=>$type,'name'=>$name,'state'=>$state,'start_at'=>$start,'end_at'=>$end,'data_json'=>json_encode(['notes'=>$notes]),'campaign_id'=>$email?:null,'email_status'=>$emailStatus,'email_deleted'=>false];
    if(($argv[3]??'')==='empty')$touchpoints=$planRows=[];
}
echo '<!doctype html><html>';renderPageHead('Campaign actions fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-campaign-list.min.css','assets/css/pages/audience-analytics.min.css'],'scripts'=>['assets/js/audience.min.js']]);require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
