<?php
declare(strict_types=1);
// Real Connections template with fictional records only; no database access.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
if(PHP_SAPI==='cli')parse_str($argv[1]??'',$_GET);
$fixtureRole=($_GET['fixture_role']??'')==='admin'?'admin':'reviewer';
$_SESSION=['user_id'=>11,'username'=>'Fixture User','role'=>$fixtureRole,'_csrf_token'=>'fixture-token'];
$_GET=audienceRememberPageSizes($_GET,'contacts',11);
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$view='contacts';$themeKey='audience-counts-fixture';$displayName='Example User';$pageTitle='Connections';
$canManage=$canDelete=$canExport=$fixtureRole==='admin';$message=$error='';$csrf=csrfInput();$audienceId=1;
$id=0;
$contact=['id'=>0,'version'=>0,'email'=>'','first_name'=>'','last_name'=>'','email_status'=>'non-subscribed','custom'=>[],'tag_ids'=>[],'group_ids'=>[],'language'=>'','timezone'=>'','source'=>'Manual','consent_note'=>''];
$audience=['name'=>'Example audience','settings_json'=>'{}','version'=>1];
$groups=$fields=$tags=$sources=$segments=$query=$rowTags=$rowGroups=[];
$rules=['match'=>'all','rules'=>[]];$catalog=audienceRuleCatalog([],[],[]);$segment=$job=null;
$filters=audienceListFilters($_GET);$query=$_GET;$sort='created_at';$direction='desc';$page=1;$pageSize=audiencePageSize($_GET);
$sortColumns=['email','first_name','last_name','email_status','source','created_at','updated_at'];
$counts=['records'=>22,'total'=>19,'subscribed'=>16,'unsubscribed'=>1,'non_subscribed'=>2,'pending'=>2,'cleaned'=>1,'archived'=>2];
$rows=[];
foreach([...array_fill(0,16,'subscribed'),'unsubscribed','non-subscribed','non-subscribed','pending','pending','cleaned','archived','archived']as$i=>$status){
    $archived=$status==='archived';if($archived!==($filters['archived']==='1'))continue;
    $status=$archived?'subscribed':$status;
    if($filters['status']&&!in_array($status,$filters['status'],true))continue;
    $email='person-'.($i+1).'@example.test';if($filters['q']!==''&&!str_contains($email,$filters['q']))continue;
    $rows[]=['id'=>$i+1,'email'=>$email,'first_name'=>'Example','last_name'=>(string)($i+1),'email_status'=>$status,'source'=>'Fixture','custom_json'=>'{}','created_at'=>'2026-10-26 12:00:00','updated_at'=>'2026-10-26 12:00:00'];
    $rowTags[$i+1]=$rowGroups[$i+1]=[];
}
$total=count($rows);
echo '<!doctype html><html>';renderPageHead('Audience count pills fixture',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js']]);require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
