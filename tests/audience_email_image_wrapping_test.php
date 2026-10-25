<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_design.php';
$image=imagecreatetruecolor(321,500);imagefill($image,0,0,0x346778);ob_start();imagepng($image);$source='data:image/png;base64,'.base64_encode(ob_get_clean());
$copy=str_repeat('A newsletter paragraph should wrap beside the photograph and continue across the full width below it. ',14);
$rich='<div>Text before the right cover. <a href="https://example.test/book"><img src="'.$source.'" width="150" align="right" alt="Right book cover"></a><br><span id="right-copy">'.$copy.'</span></div><p>Text before the left cover. <img src="'.$source.'" width="150" align="left" alt="Left book cover"><span id="left-copy">'.$copy.'</span></p><p>Final paragraph with an <a href="https://example.test/news">unchanged link</a>.</p>';
if(($argv[1]??'')==='--fixture-wide')$rich=str_replace('width="150"','width="600"',$rich);
$design=audienceDesignStarters()['blank']['design'];$design['rows'][0]['columns'][0][0]['id']='wrapping-copy';$design['rows'][0]['columns'][0][0]['html']=$rich;
$design=audienceDesign($design);$stored=$design['rows'][0]['columns'][0][0]['html'];
$context=['LIST:COMPANY'=>'Fixture organization','LIST:ADDRESS'=>'123 Fixture Street','UNSUB'=>'https://example.test/unsubscribe','UPDATE_PROFILE'=>'https://example.test/preferences'];
$html=audienceDesignHtml($design,$context);
if(in_array($argv[1]??'',['--fixture','--fixture-wide'],true)){echo json_encode(['html'=>$html,'stored'=>$stored],JSON_THROW_ON_ERROR);exit;}
$checks=0;$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$doc=new DOMDocument();@$doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new DOMXPath($doc);
$tables=$xpath->query('//table[@class="email-rich-image"]');$expect($tables->length===2,'Both wrapped images use presentation tables');
foreach(['right','left']as$index=>$side){$table=$tables->item($index);$img=$table->getElementsByTagName('img')->item(0);
    $expect($table->getAttribute('role')==='presentation'&&$table->getAttribute('align')===$side,'Legacy table alignment supports '.$side.' wrapping');
    $expect($table->getAttribute('width')==='162'&&$img->getAttribute('width')==='150','Wrapping reserves the authored image width and text gap');
    $expect(!str_contains($img->getAttribute('style'),'float:')&&!$img->hasAttribute('align'),'Wrapping does not rely on the image element itself');
    $expect(str_contains($table->getAttribute('style'),'float:'.$side.'!important'),'Inline priority preserves '.$side.' wrapping against Proton iOS stylesheet resets');
}
$expect($xpath->query('//table[@class="email-rich-image"]//a[@href="https://example.test/book"]')->length===1,'The linked cover remains clickable');
$expect($xpath->query('//p//table')->length===0,'Presentation tables never create invalid paragraph nesting');
$expect(str_contains($html,'class="email-rich-paragraph"')&&str_contains($html,'margin:0 0 16px'),'Converted image paragraphs retain paragraph spacing');
$expect(str_contains($html,'.wrapping-copy .email-rich-paragraph{margin-bottom:16px!important}'),'Mobile paragraph spacing also applies to converted paragraphs');
$expect(audienceDesign($design)['rows'][0]['columns'][0][0]['html']===$stored&&str_contains($stored,'align="right"')&&str_contains($stored,'float:left'),'Stored/editor content retains its image-alignment controls');
$expect(str_contains($html,'https://example.test/news')&&substr_count($doc->textContent,'A newsletter paragraph')===28,'Text and other links remain intact');
$plain=audienceDesignPlainText($design,$context);$expect(!str_contains($plain,'email-rich-image')&&str_contains($plain,'newsletter paragraph'),'Plain-text output remains independent of presentation markup');
$inline=audienceDesignRichText('<p><img src="'.$source.'" width="150" alt="Inline cover">Inline text.</p>');$expect(audienceDesignEmailRichText($inline)===$inline,'Images without wrapping keep their existing layout');
echo "Email image wrapping: $checks checks passed.\n";
