<?php
declare(strict_types=1);
require __DIR__.'/../src/audience_design.php';
$image=static function(int $color):string{$im=imagecreatetruecolor(8,8);imagefill($im,0,0,$color);ob_start();imagepng($im);return 'data:image/png;base64,'.base64_encode(ob_get_clean());};
$original=$image(0xff0000);$edited=$image(0x0000ff);$checks=0;
foreach(['image','image-group']as$type){
    $block=['id'=>'reset-image','type'=>$type,'image'=>$edited,'originalImage'=>$original];
    if($type==='image-group')$block['images']=[['image'=>$edited,'originalImage'=>$original]];
    $doc=audienceDesign(['schema'=>1,'rows'=>[['id'=>'reset-row','columns'=>[[$block]]]]]);
    $saved=audienceDesign(json_decode(json_encode($doc,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR));
    $target=$type==='image'?$saved['rows'][0]['columns'][0][0]:$saved['rows'][0]['columns'][0][0]['images'][0];
    if($target['originalImage']!==$original||$target['image']!==$edited)throw new RuntimeException('Original image did not survive save.');++$checks;
    $html=audienceDesignHtml($saved);if(str_contains($html,$original)||!str_contains($html,$edited))throw new RuntimeException('Only the applied image may appear in email output.');++$checks;
    $bad=$doc;$bad['rows'][0]['columns'][0][0]['originalImage']='javascript:bad()';$rejected=false;try{audienceDesign($bad);}catch(InvalidArgumentException){$rejected=true;}if(!$rejected)throw new RuntimeException('Invalid original image accepted.');++$checks;
}
echo "Original image preservation: $checks checks passed.\n";
