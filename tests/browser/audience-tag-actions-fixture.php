<?php
declare(strict_types=1);
// Render Tag actions using a synthetic session and records only.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$view=$argv[7]??'tags';$role=$argv[1]??'admin';$tab=$argv[2]??'active';$unlocked=($argv[3]??'locked')==='unlocked';
parse_str($argv[5]??'',$_GET);
$_SESSION=['user_id'=>11,'username'=>'fixture-'.$role,'role'=>$role,'_csrf_token'=>'fixture-token'];
if($unlocked)$_SESSION['_admin_elevated_at']=time();
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$_SERVER['PHP_SELF']='audience.php';$_SERVER['QUERY_STRING']=http_build_query(['view'=>$view,'tab'=>$tab]);
$themeKey='template-lifecycle-fixture';$displayName='Example User';$pageTitle=ucwords(str_replace('-',' ',$view));
$canManage=in_array($role,['admin','editor'],true);$canDelete=$role==='admin';$canExport=$canManage;
$message=$error='';$csrf=csrfInput();$audienceId=1;
$audience=['name'=>'Example audience','settings_json'=>'{}','version'=>1];
$groups=$fields=$catalog=$query=$recentDesigns=[];$tags=[['id'=>7,'version'=>3,'name'=>'Newsletter subscribers','contact_count'=>128,'created_at'=>'2026-10-22 12:00:00'],['id'=>8,'version'=>5,'name'=>'Community friends & volunteers with a longer tag name','contact_count'=>24,'created_at'=>'2026-10-21 12:00:00']];
foreach(array_slice(['Prayer team','Recent donors','Devotions','Israel tour interest','Fundraising volunteers','Monthly donors','Community updates','Welcome team','Prayer letter','SMS','Event attendees','Ministry partners','New connections','Youth group','Christmas outreach','2025 donors','Launch donors','Website signup','Newsletter archive','Spring event','Summer event','Fall event','Winter event','Regional partners','International partners'],0,max(0,min(25,(int)($argv[4]??2)-2)))as$index=>$name)$tags[]=['id'=>9+$index,'version'=>1,'name'=>$name,'contact_count'=>$index*7,'created_at'=>null];
if(($argv[6]??'')==='unknown-dates')foreach($tags as&$tag)$tag['created_at']=null;
unset($tag);
$rules=['match'=>'all','rules'=>[]];$filters=['q'=>'','status'=>[],'source'=>[],'tags'=>[],'archived'=>'0'];$segment=$job=null;

if($view==='add-person'){
    $view='tags';ob_start();require __DIR__.'/../../src/templates/audience/workspace.php';ob_end_clean();$view='add-person';
    $contact=['id'=>0,'version'=>0,'first_name'=>'','last_name'=>'','email'=>'','custom'=>[],'email_status'=>'non-subscribed','consent_note'=>'','tag_ids'=>[],'group_ids'=>[],'language'=>'en','timezone'=>'America/Chicago','source'=>'Manual'];$groups=$fields=[];$baseForm=[];$id=0;$personModal=true;
    echo '<!doctype html><html>';renderPageHead('Add a connection fixture',['styles'=>['assets/css/pages/audience.min.css']]);echo '<body class="audience-app"><dialog id="add-person" class="audience-wide-dialog"><h2>Add a connection</h2>';require __DIR__.'/../../src/templates/audience/person-form.php';echo '</dialog></body></html>';exit;
}
if($view==='brand'){
    $svg=static fn($body)=>'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="160" viewBox="0 0 240 160">'.$body.'</svg>');
    $brand=['logo'=>$svg('<rect x="20" y="55" width="200" height="60" fill="#007c89"/>'),'secondary_logo'=>$svg('<rect x="102" y="15" width="36" height="130" fill="#315ea8"/>'),'organization_name'=>'Example organization','mailing_address'=>'123 Example Street','styles'=>[]];
    $contentContext=['version'=>1,'settings'=>['brand'=>$brand]];$brandTemplates=[];$brandPreview=audienceDesignStarters()['blank']['design'];$campaignRecovery=null;
}
if($view==='groups')foreach(['Daily updates','Community news','Subscriber interests']as$i=>$name)$groups[]=['id'=>7+$i,'version'=>1,'name'=>$name,'display_type'=>'checkboxes','options'=>[['id'=>30+$i,'name'=>'Newsletter updates','contact_count'=>4714],['id'=>40+$i,'name'=>'Events and reminders','contact_count'=>128]]];
if($view==='fields')$fields=[['id'=>17,'version'=>1,'label'=>'Interest','field_type'=>'text','merge_tag'=>'INTEREST','default_value'=>'','options_json'=>'[]','required'=>0,'is_public'=>1,'position'=>0]];
if(in_array($view,['jobs','job'],true)){
    $_GET['kind']='export';$job=$view==='job'?['id'=>8,'job_type'=>'import','state'=>'complete','name'=>'Example import','actor_label'=>'Example User','created_at'=>'2026-10-24 12:00:00','error_message'=>'','total'=>1,'processed'=>1,'added'=>1,'updated'=>0,'skipped'=>0,'errors'=>0]:null;
    $store=new class {
        public function one(string $sql,array $params=[]):array{return ['n'=>1];}
        public function all(string $sql,array $params=[]):array{return str_contains($sql,'FROM marketing_jobs')?[['id'=>9,'job_type'=>'export','state'=>'complete','name'=>'Connections export','created_at'=>'2026-10-24 12:00:00','total'=>4,'errors'=>0]]:[];}
    };
}
echo '<!doctype html><html>';renderPageHead('Tag actions fixture',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js']]);require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
