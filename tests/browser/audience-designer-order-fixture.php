<?php
declare(strict_types=1);
// Actual designer template with synthetic content and no database, AI, or email.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$_SESSION=['user_id'=>11,'username'=>'order-fixture','role'=>'admin','_csrf_token'=>'fixture-token'];
$_SERVER['PHP_SELF']='audience.php';
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$themeKey='designer-order-fixture';$kind='template';$record=['id'=>7,'name'=>'Ordering example','version'=>1];$csrf=csrfInput();
$starters=audienceDesignStarters();$design=$starters['blank']['design'];$design['rows']=[];
foreach(['Alpha','Bravo','Charlie']as$i=>$name){
    $blocks=[];foreach(['first','second']as$j=>$text)$blocks[]=['id'=>'block-'.$i.'-'.$j,'type'=>'text','text'=>$name.' '.$text,'padding'=>20];
    $design['rows'][]=['id'=>'section-'.$i,'name'=>$name,'columns'=>[$blocks]];
}
$design=audienceDesign($design);
$designerConfig=['design'=>$design,'version'=>1,'nonce'=>'fixture-nonce','endpoint'=>'audience.php','exit'=>'audience.php?view=templates','starters'=>$starters,'saved'=>[],'mergeTags'=>[],'dynamicFields'=>[],'recoveryKey'=>'order-fixture','kind'=>'template','canSend'=>false,'brandLogos'=>(object)[],'brandFont'=>[]];
$aiConfig=['enabled'=>false];
echo '<!doctype html><html lang="en">';
renderPageHead('Order fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-designer.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js']]);
require __DIR__.'/../../src/templates/audience/designer.php';
echo '</html>';
