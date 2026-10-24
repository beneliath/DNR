<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';
$checks=0;
$expect=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;};
$image=static function(int $width,int $height,string $type='png'):string{
    $canvas=imagecreatetruecolor($width,$height);imagefill($canvas,0,0,imagecolorallocate($canvas,0,170,0));
    ob_start();match($type){'jpeg'=>imagejpeg($canvas), 'webp'=>imagewebp($canvas),default=>imagepng($canvas)};$bytes=ob_get_clean();unset($canvas);
    return 'data:image/'.$type.';base64,'.base64_encode($bytes);
};
$decode=static fn(string $data)=>imagecreatefromstring(base64_decode(explode(',',$data,2)[1],true));
$primary=$image(240,120);
foreach(['png','jpeg','webp']as$type){
    $pair=audienceBrandLogos(['logo'=>$primary,'secondary_logo'=>$image(40,80,$type)]);$matched=$decode($pair['secondary_logo']);
    $expect($pair['logo']===$primary&&imagesx($matched)===240&&imagesy($matched)===120,'Both logo canvases match, preserving the default image: '.$type);
    $expect((imagecolorat($matched,0,60)>>24)===127&&(imagecolorat($matched,239,60)>>24)===127,'Padding stays transparent: '.$type);
    $expect((imagecolorat($matched,89,60)>>24)===127&&(imagecolorat($matched,90,60)>>24)===0&&(imagecolorat($matched,149,60)>>24)===0&&(imagecolorat($matched,150,60)>>24)===127,'Artwork remains centered with its original proportions: '.$type);
    $expect(audienceBrandLogos($pair)===$pair,'Normalization is idempotent: '.$type);unset($matched);
}
$secondary=$image(100,50);
$expect(audienceBrandLogos(['secondary_logo'=>$secondary])===['logo'=>'','secondary_logo'=>$secondary],'A secondary-only kit preserves the uploaded image');
$expect(audienceBrandLogos(['logo'=>$primary])===['logo'=>$primary,'secondary_logo'=>''],'Removing the secondary logo stays supported');
$gif='data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
try{audienceBrandLogos(['logo'=>$primary,'secondary_logo'=>$gif]);throw new RuntimeException('Animated logo was flattened');}catch(InvalidArgumentException $error){$expect(str_contains($error->getMessage(),'Animated logos'),'Animation requires matching dimensions instead of losing frames');}
$svg=static fn(string $xml):string=>'data:image/svg+xml;base64,'.base64_encode($xml);
$vector=$svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 80"><style>.mark{fill:#00aa00}</style><path class="mark" d="M0 0h40v80H0z"/></svg>');
$pair=audienceBrandLogos(['logo'=>$primary,'secondary_logo'=>$vector]);$xml=base64_decode(explode(',',$pair['secondary_logo'],2)[1]);
$expect(str_starts_with($pair['secondary_logo'],'data:image/svg+xml;')&&audienceLogoDimensions($pair['secondary_logo'])===[240,120],'SVG matching keeps the vector format and common dimensions');
$expect(str_contains($xml,'viewBox="0 0 40 80"')&&str_contains($xml,'preserveAspectRatio="xMidYMid meet"')&&str_contains($xml,'d="M0 0h40v80H0z"')&&str_contains($xml,'.mark{fill:#00aa00}'),'SVG matching preserves vector paths, styling, and original coordinates');
$expect(audienceBrandLogos($pair)===$pair,'Saved SVG normalization is idempotent');
$svgDefault=audienceSvgImage($svg('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="120"><rect width="240" height="120" fill="blue"/></svg>'));
$expect(audienceLogoDimensions(audienceBrandLogos(['logo'=>$svgDefault,'secondary_logo'=>$image(40,80)])['secondary_logo'])===[240,120],'Raster secondary logos match a vector default');
$svgBoth=audienceBrandLogos(['logo'=>$svgDefault,'secondary_logo'=>$vector]);
$expect(audienceLogoDimensions($svgBoth['logo'])===audienceLogoDimensions($svgBoth['secondary_logo'])&&str_starts_with($svgBoth['logo'],'data:image/svg+xml;'),'Both logos can stay vector while sharing canvas dimensions');
$unsafe=$svg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 40 80" onload="alert(1)"><script>alert(1)</script><foreignObject><p xmlns="http://www.w3.org/1999/xhtml">bad</p></foreignObject><image href="https://example.test/tracker.png"/><use xlink:href="https://example.test/other.svg#mark"/><path d="M0 0h40v80H0z" fill="url(https://example.test/paint.svg)" style="stroke:#123456;fill:url(https://example.test/paint.svg)"/></svg>');
$safeXml=base64_decode(explode(',',audienceSvgImage($unsafe),2)[1]);
$expect(!preg_match('/script|onload|foreignObject|https:\/\/example|<image/',$safeXml)&&str_contains($safeXml,'stroke:#123456'),'SVG uploads remove active markup and external resources while retaining safe styling');
$local=audienceSvgImage($svg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 20"><defs><linearGradient id="ink"><stop offset="0" stop-color="red"/></linearGradient></defs><path fill="url(#ink)" d="M0 0h10v20H0z"/></svg>'));
$expect(str_contains(base64_decode(explode(',',$local)[1]),'fill="url(#ink)"'),'Local gradients survive SVG sanitizing');
$design=audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[['type'=>'image','image'=>$pair['secondary_logo']]]]]]]);
$expect(str_contains(audienceDesignHtml($design),'data:image/svg+xml;base64,'),'Brand SVG logos remain available in email design rendering');
$landing=audienceCollectionDefaults('landing');$landing['logo']=$pair['secondary_logo'];
$expect(audienceCollectionDocument($landing,'landing',[],[],[])['logo']===$pair['secondary_logo'],'Landing pages retain the saved SVG logo snapshot');
foreach(['<svg/>','<svg viewBox="0 0 0 20"/>','<svg width="100%" height="100%"/>','<!DOCTYPE svg [<!ENTITY leak SYSTEM "file:///etc/passwd">]><svg viewBox="0 0 10 20">&leak;</svg>','<svg xmlns="https://example.test/not-svg" viewBox="0 0 10 20"/>']as$invalid){try{audienceSvgImage($svg($invalid));throw new RuntimeException('Invalid SVG accepted');}catch(InvalidArgumentException){$expect(true,'Malformed, unbounded, entity, or foreign-namespace SVG rejected');}}
echo "Brand logo dimensions: $checks checks passed.\n";
