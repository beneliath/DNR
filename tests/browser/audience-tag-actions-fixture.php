<?php
declare(strict_types=1);
// Render Tag actions using a synthetic session and records only.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$view='tags';$role=$argv[1]??'admin';$tab=$argv[2]??'active';$unlocked=($argv[3]??'locked')==='unlocked';
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

echo '<!doctype html><html>';renderPageHead('Tag actions fixture',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js']]);require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
