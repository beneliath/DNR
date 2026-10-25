<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
if(($argv[1]??'')==='normalize'){echo json_encode(audienceDesign(json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR);exit;}
$_SESSION=['user_id'=>11,'username'=>'fixture-editor','role'=>'editor','_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$image=imagecreatetruecolor(600,400);imagefill($image,0,0,0xff0000);imagefilledrectangle($image,240,160,359,239,0x0000ff);ob_start();imagepng($image);$source='data:image/png;base64,'.base64_encode(ob_get_clean());
$design=audienceDesign(['schema'=>1,'rows'=>[['id'=>'image-row','columns'=>[[['id'=>'image-block','type'=>'image','image'=>$source,'alt'=>'Synthetic crop target','padding'=>0]]]]]]);
if(($argv[1]??'')==='editor')$design=audienceDesign(json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR));
$kind='template';$record=['name'=>'Synthetic image editing','version'=>1];$themeKey='image-editing-fixture';$csrf=csrfInput();$aiConfig=['enabled'=>false];
$secondary='data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="120" viewBox="0 0 80 120"><rect width="80" height="120" fill="#007c89"/><circle cx="40" cy="40" r="20" fill="white"/></svg>');
$brandLogos=['logo'=>['label'=>'Primary logo','image'=>$source],'secondary_logo'=>['label'=>'Secondary logo','image'=>audienceDesignImage($secondary,2000000,true)]];
if(($argv[1]??'')==='no-logos')$brandLogos=[];elseif(($argv[1]??'')==='secondary-only')unset($brandLogos['logo']);
$designerConfig=['brandLogos'=>(object)$brandLogos,'kind'=>$kind,'design'=>$design,'version'=>1,'nonce'=>contentSecurityPolicyNonce(),'endpoint'=>'audience.php?view=designer','exit'=>'audience.php?view=templates','starters'=>[],'saved'=>[],'mergeTags'=>[],'dynamicFields'=>[],'recoveryKey'=>'synthetic-image-editing','canSend'=>false,'senders'=>[]];
echo '<!doctype html><html>';
renderPageHead('Image editing fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-designer.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js']]);
require __DIR__.'/../../src/templates/audience/designer.php';echo '</html>';
