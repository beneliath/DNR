<?php
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
parse_str($argv[1]??'',$_GET);
$_SESSION=['user_id'=>11,'username'=>'fixture-admin','role'=>'admin','_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$view=($_GET['view']??'')==='assistant'?'assistant':'contacts';$themeKey='audience-status-fixture';$displayName='Example User';$pageTitle=$view==='assistant'?'AI assistant':'Connections';
$canManage=$canDelete=$canExport=true;$message=$error='';$csrf=csrfInput();$audienceId=1;
$audience=['id'=>1,'name'=>'Example audience','settings_json'=>'{}','version'=>1];
$tags=$groups=$fields=$catalog=$recentDesigns=$segments=$sources=[];$segment=$job=null;
$filters=audienceListFilters($_GET);$rules=['match'=>'all','rules'=>[]];
$pageSize=50;$page=1;$sort=$_GET['sort']??'email';$direction=$_GET['direction']??'asc';$sortColumns=['email','first_name','last_name','email_status','source','created_at','updated_at'];
$query=$filters+['rules'=>json_encode($rules),'sort'=>$sort,'direction'=>$direction,'per_page'=>$pageSize];
$counts=['records'=>10894,'total'=>9069,'subscribed'=>7213,'archived'=>12,'cleaned'=>1825];
if(isset($_GET['empty']))$counts=array_fill_keys(array_keys($counts),0);
$rows=$rowTags=$rowGroups=[];
if(!isset($_GET['empty'])){
 $id=$filters['archived']==='1'?2:1;
 $rows[]=['id'=>$id,'version'=>1,'email'=>($id===2?'archived':'community-newsletter').'@example.test','first_name'=>'Alexandria','last_name'=>'Montgomery','email_status'=>'subscribed','source'=>'Manual entry','custom_json'=>'{}','created_at'=>'2026-10-24 09:00:00','updated_at'=>'2026-10-25 09:00:00'];
 $rowTags[$id]=$rowGroups[$id]=[];
}
$total=isset($_GET['empty'])?0:($filters['archived']==='1'&&$filters['q']===''&&$filters['status']===[]&&$filters['tags']===[]?12:count($rows));
$id=0;$contact=['id'=>0,'version'=>0,'email'=>'','first_name'=>'','last_name'=>'','email_status'=>'non-subscribed','source'=>'Manual entry','custom'=>[],'tag_ids'=>[],'group_ids'=>[],'language'=>'en','timezone'=>'America/Chicago','is_archived'=>0,'consent_note'=>''];
echo '<!doctype html><html lang="en">';
if($view==='assistant'){
 require_once __DIR__.'/../../src/audience_ai.php';
 $aiMode=($_GET['mode']??'')==='analytics'?'analytics':'content';$aiText=$aiSubject=$aiSourceName=$aiPrompt='';$aiImages=[];$aiInline=false;
 $aiWindow=['start'=>'2026-10-01','end'=>'2026-10-28','dataset'=>'email'];$aiConfig=['mode'=>$aiMode,'endpoint'=>'audience_ai_api.php','window'=>$aiWindow,'enabled'=>false,'key'=>'mobile-ai-fixture'];
}
renderPageHead('Audience status fixture',['styles'=>['assets/css/pages/audience.min.css',...($view==='assistant'?['assets/css/pages/audience-ai.min.css']:[])],'scripts'=>['assets/js/audience.min.js',...($view==='assistant'?['assets/js/audience-ai.min.js']:[])]]);
require __DIR__.'/../../src/templates/audience/workspace.php';
echo '</html>';
