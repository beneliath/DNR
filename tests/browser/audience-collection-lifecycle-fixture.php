<?php
declare(strict_types=1);
// Render real form lifecycle controls using a synthetic session and records only.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$view=$argv[4]??'forms';$role=$argv[1]??'admin';$tab=$argv[2]??'active';$unlocked=($argv[3]??'locked')==='unlocked';
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
$available=true;$resource=null;$creationKind='hosted';$trash=$tab==='archived';$q='';$type='';$kinds=match($view){'surveys'=>['survey'],'landing-pages'=>['landing'],'preferences'=>['preferences'],'form-settings'=>['hosted','preferences'],default=>['hosted','embedded','popup','landing']};
$collectionDirection='desc';$collectionPerPage=100;$collectionPage=1;$collectionPages=1;$blockedSignupIds=[];
$listQuery=['view'=>$view,'q'=>$q,'type'=>$type,'trash'=>$trash?'1':'0','per_page'=>100,'direction'=>'desc'];
$resources=[];foreach(array_slice($kinds,0,2)as$i=>$kind)$resources[]=['id'=>7+$i,'name'=>($kind==='popup'?'Website popup':'Newsletter signup'),'kind'=>$kind,'version'=>3,'state'=>$trash?'paused':'draft','deleted_at'=>$trash?'2026-10-22 12:00:00':null,'updated_at'=>'2026-10-22 12:00:00'];
echo '<!doctype html><html>';
renderPageHead('Form lifecycle fixture',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-collection.min.js']]);
require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
