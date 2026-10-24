<?php
declare(strict_types=1);
// Real editor/preview templates with synthetic records; no database or mail.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
require __DIR__.'/../../src/audience_collection_render.php';
function collectionFixtureImage(string $label,int $color):string {
    $canvas=imagecreatetruecolor(260,90);imagefill($canvas,0,0,$color);imagestring($canvas,5,20,35,$label,0xffffff);
    ob_start();imagepng($canvas);return 'data:image/png;base64,'.base64_encode(ob_get_clean());
}
$images=['primary'=>collectionFixtureImage('Primary',0x007c89),'secondary'=>collectionFixtureImage('Secondary',0x5b3980),'library'=>collectionFixtureImage('Content image',0xb05822)];
if(($argv[1]??'')==='images'){echo json_encode($images,JSON_THROW_ON_ERROR);exit;}
$document=audienceCollectionDefaults('preferences');$document['title']='Preference center';
if(in_array($argv[1]??'',['preview','public'],true)){
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $document=audienceCollectionDocument($input['document'],'preferences',[],[],[]);
    echo audienceCollectionHtml(['kind'=>'preferences','document'=>$document],[],[],'','',[],'','',null,$argv[1]==='preview','',$input['nonce']??'');exit;
}
$editorInput=($argv[1]??'')==='editor'?json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR):null;
if($editorInput)$document=audienceCollectionDocument($editorInput['document'],'preferences',[],[],[]);
$recover=!empty($editorInput['recovered'])?['document_json'=>json_encode($document,JSON_THROW_ON_ERROR)]:null;
$view='form';$role=$editorInput?($editorInput['role']??'admin'):($argv[1]??'admin');$id=7;$audienceId=1;
$_SESSION=['user_id'=>11,'username'=>'fixture-'.$role,'role'=>$role,'_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');$url=static fn(array $p=[])=>'audience.php?'.http_build_query($p);
$_SERVER['PHP_SELF']='audience.php';$_SERVER['QUERY_STRING']='view=form&id=7';
$themeKey='collection-image-fixture';$displayName='Example User';$pageTitle='Preference center';
$canManage=$role!=='reviewer';$canDelete=$role==='admin';$message=$error='';$csrf=csrfInput();
$audience=['name'=>'Example audience','settings_json'=>'{}','version'=>1];$tags=$groups=$fields=$catalog=$query=$recentDesigns=[];
$rules=['match'=>'all','rules'=>[]];$filters=['q'=>'','status'=>[],'source'=>[],'tags'=>[],'archived'=>'0'];$segment=$job=null;
$available=true;$resource=['id'=>7,'version'=>1,'name'=>$editorInput['name']??'Preference center','kind'=>'preferences','state'=>'draft','draft'=>$document,'public_key'=>str_repeat('a',48),'deleted_at'=>null];
$collectionBrand=['logo'=>$images['primary'],'secondary_logo'=>$images['secondary']];$publicBase='https://collection.test';$publicUrl=$publicBase.'/audience_collect_public.php?key='.$resource['public_key'];$signupEmailBlocked=false;
$resources=$blockedSignupIds=$listQuery=[];$q=$type='';$trash=false;$kinds=['preferences'];$creationKind='preferences';$collectionDirection='desc';$collectionPage=$collectionPages=1;$collectionPerPage=25;
echo '<!doctype html><html>';renderPageHead('Preference editor fixture',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-collection.min.js']]);
require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
