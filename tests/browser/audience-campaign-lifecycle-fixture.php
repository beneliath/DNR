<?php
declare(strict_types=1);
// Render Campaign and Home controls using a synthetic session and records only.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$view=($argv[4]??'campaigns')==='home'?'home':'campaigns';$role=$argv[1]??'admin';$tab=$argv[2]??'active';$unlocked=($argv[3]??'locked')==='unlocked';
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
$folders=[['id'=>4,'name'=>'Newsletters','version'=>1,'campaign_count'=>0]];
$store=new class{public function analyticsAudienceHistory(array $w,string $source=''):array{return ['current'=>[],'previous'=>[]];}public function analyticsReportRows(array $w,array $definition):array{return [];}};
$window=['start'=>'2026-10-01','end'=>'2026-10-22','compare'=>'none','bots'=>'filtered','messages_mode'=>'all','messages'=>[]];$showDrafts=false;$section='home';$rangeKey='custom';$presets=[];
$totals=array_fill_keys(['accepted','delivery_rate','open_rate','click_rate','clicks_per_open','unsubscribe_rate','awaiting_receipt','detected_bot'],0);$overview=['totals'=>$totals,'previous'=>$totals,'audience'=>array_fill_keys(['visible_total','subscribed','added'],0),'includes_samples'=>false,'campaigns'=>[]];
echo '<!doctype html><html>';renderPageHead('Campaign actions fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-campaign-list.min.css','assets/css/pages/audience-analytics.min.css'],'scripts'=>['assets/js/audience.min.js']]);require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
