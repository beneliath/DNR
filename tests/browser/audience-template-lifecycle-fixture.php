<?php
declare(strict_types=1);
// Render actual template controls with synthetic data and a synthetic session only.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$role=$argv[1]??'admin';$tab=$argv[2]??'saved';$unlocked=($argv[3]??'locked')==='unlocked';
$_SESSION=['user_id'=>11,'username'=>'fixture-'.$role,'role'=>$role,'_csrf_token'=>'fixture-token'];
if($unlocked)$_SESSION['_admin_elevated_at']=time();
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$_SERVER['PHP_SELF']='audience.php';$_SERVER['QUERY_STRING']=http_build_query(['view'=>'templates','tab'=>$tab]);
$view='templates';$themeKey='template-lifecycle-fixture';$displayName='Example User';$pageTitle='Email Templates';
$canManage=in_array($role,['admin','editor'],true);$canDelete=$role==='admin';
$message=$error='';$csrf=csrfInput();$audienceId=1;
$audience=['name'=>'Example audience','settings_json'=>'{}'];
$tags=$groups=$fields=$catalog=$query=$recentDesigns=[];
$rules=['match'=>'all','rules'=>[]];$filters=['q'=>'','status'=>[],'source'=>[],'tags'=>[],'archived'=>'0'];$segment=$job=null;
$starters=audienceDesignStarters();
$templates=[['id'=>7,'name'=>'Reusable design','folder'=>'Newsletters','version'=>3,'updated_at'=>'2026-10-21 12:00:00','deleted_at'=>$tab==='archived'?'2026-10-21 12:00:00':null,'design_json'=>json_encode($starters['blank']['design'])]];
echo '<!doctype html><html>';
renderPageHead('Template lifecycle fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-designer.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js']]);
require __DIR__.'/../../src/templates/audience/workspace.php';
echo '</html>';
