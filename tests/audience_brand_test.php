<?php
declare(strict_types=1);
require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_store.php';
final class BrandSettingsFixture
{
    use AudienceContent;
    public int $audienceId=1;
    public array $row=['name'=>'Example organization','version'=>1,'settings_json'=>'{"from_name":"Example organization","unrelated":"retained"}'];
    public function transaction(callable $callback):mixed{return $callback();}
    public function one(string $sql,array $params=[]):array{return $this->row;}
    public function write(string $sql,array $params=[]):void{$this->row['settings_json']=$params[0];$this->row['version']++;}
    public function settings():array{return json_decode($this->row['settings_json'],true,flags:JSON_THROW_ON_ERROR);}
}
$checks=0;
$expect=static function(bool $value,string $message)use(&$checks):void{if(!$value)throw new RuntimeException($message);$checks++;};
$store=new BrandSettingsFixture();
$font='data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/../src/assets/fonts/rubik-latin-700.woff2'));
$input=['web_font'=>$font,'web_font_name'=>'rubik-latin-700.woff2','font_license'=>'1'];
$store->saveBrand($input,1);
$expect($store->settings()['brand']['web_font_name']===$input['web_font_name'],'The uploaded font filename persists with its font.');
$expect($store->settings()['brand']['styles']['webFont']===$font,'The filename does not replace font data.');
$expect($store->settings()['unrelated']==='retained','Other audience settings are preserved.');
$withoutName=$input;unset($withoutName['web_font_name']);$store->saveBrand($withoutName,2);
$expect($store->settings()['brand']['web_font_name']===$input['web_font_name'],'A client omitting metadata keeps the existing name for the same font.');
$store->saveBrand(array_replace($input,['web_font_name'=>'Replacement font.woff2']),3);
$expect($store->settings()['brand']['web_font_name']==='Replacement font.woff2','Replacing a font updates the displayed filename.');
$before=$store->row;
try{$store->saveBrand(array_replace($input,['web_font_name'=>str_repeat('x',256)]),4);throw new RuntimeException('Overlong filename accepted.');}catch(InvalidArgumentException){$checks++;}
$expect($store->row===$before,'Rejected filename metadata leaves saved settings intact.');
$store->saveBrand(['web_font'=>'','web_font_name'=>'Stale font name.woff2'],4);
$expect($store->settings()['brand']['web_font_name']===''&&$store->settings()['brand']['styles']['webFont']==='','Removing a font clears its filename as well.');
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$uploadId='fixture-font';$uploadLabel='Upload a brand font';$uploadKey='web_font';$uploadAccept='.woff2';$uploadTypes='Font';$uploadHelp='Font help';$uploadStatus='Uploaded font: <Example>.woff2';
ob_start();include __DIR__.'/../src/templates/audience/file-drop.php';$html=ob_get_clean();
$expect(str_contains($html,'Uploaded font: &lt;Example&gt;.woff2')&&!str_contains($html,'Uploaded font: <Example>'),'The saved font name is escaped in the drop area.');
$nameTable=static function(string $name,int $nameId=4):string{
    $text=mb_convert_encoding($name,'UTF-16BE','UTF-8');
    return pack('nnn',0,1,18).pack('nnnnnn',3,1,0x409,$nameId,strlen($text),0).$text;
};
$ttf=static fn(string $table)=>"\x00\x01\x00\x00".pack('nnnn',1,0,0,0).'name'.pack('NNN',0,28,strlen($table)).$table;
$data=static fn(string $bytes,string $format='ttf')=>'data:font/'.$format.';base64,'.base64_encode($bytes);
$legacy=$data($ttf($nameTable('Example Light')));
$expect(audienceFontName($legacy)==='Example Light','A legacy TTF upload displays its internal font name.');
$expect(audienceBrandFont(['styles'=>['webFont'=>$legacy]])['name']==='Example Light','Brand Kit and designer share the recovered font label.');
$expect(audienceFontName($data($ttf($nameTable('Example family',16))))==='Example family','A typographic family name is a valid fallback.');
$table=$nameTable(str_repeat('Example ',20));$compressed=gzcompress($table);
$woff='wOFF'."\x00\x01\x00\x00".pack('NnnNnnNNNNN',64+strlen($compressed),1,0,28+strlen($table),1,0,0,0,0,0,0).'name'.pack('NNNN',64,strlen($compressed),strlen($table),0).$compressed;
$expect(audienceFontName($data($woff,'woff'))===trim(str_repeat('Example ',20)),'Compressed WOFF names are recovered.');
foreach([$data(substr($ttf($table),0,-1)),$data(substr_replace($ttf($table),pack('N',0xffffff00),20,4)),$data($ttf(substr_replace($table,pack('n',65535),4,2))),$data($ttf($nameTable("Bad\0name")))]as$malformed)$expect(audienceFontName($malformed)==='','Malformed font names are ignored without breaking the page.');
$expect(audienceBrandFont(['styles'=>['webFont'=>$font],'web_font_name'=>'rubik-latin-700.woff2'])['name']==='rubik-latin-700.woff2','WOFF2 uploads use their persisted filename.');

$design=audienceDesignStarters()['blank']['design'];$design['styles']['webFont']=$font;$design['styles']['webFontName']='Rubik';$design['styles']['webFontDefault']=false;
$design['rows'][0]['columns'][0][0]['html']='<p><font face="MOEDBrandFont,Tahoma">Brand text</font> and system text</p>';
$design['rows'][0]['columns'][0][0]['styles']['font']='MOEDBrandFont,Tahoma';
$design['rows'][0]['columns'][0][0]['mobile']['font']='MOEDBrandFont,Arial';$design['rows'][0]['columns'][0][0]['linkDevices']=false;
$saved=audienceDesign(json_decode(json_encode($design,JSON_THROW_ON_ERROR),true));
$expect($saved['styles']['webFontName']==='Rubik'&&$saved['styles']['webFont']===$font&&!$saved['styles']['webFontDefault'],'A selected font and its name survive a saved design round trip.');
$expect(str_contains($saved['rows'][0]['columns'][0][0]['html'],'font-family:MOEDBrandFont,Tahoma'),'Selected-text font survives the rich-text sanitizer.');
$render=audienceDesignHtml($saved);
$expect(str_contains($render,'@font-face{font-family:MOEDBrandFont;src:url('.$font.')')&&str_contains($render,'font-family:MOEDBrandFont,Tahoma')&&str_contains($render,'font-family:MOEDBrandFont,Arial!important'),'Email rendering includes the font bytes and explicit desktop/mobile selections with fallbacks.');
$design['rows'][0]['columns'][0][0]['styles']=[];$design['rows'][0]['columns'][0][0]['mobile']=[];$design['rows'][0]['columns'][0][0]['html']='';
$render=audienceDesignHtml($design);
$expect(!str_contains($render,'font-family:MOEDBrandFont,Arial'),'Choosing a font for selected text does not change unselected block defaults.');
$design['styles']['webFontDefault']=true;
$expect(str_contains(audienceDesignHtml($design),'font-family:MOEDBrandFont,Arial'),'Existing Brand Kit default-font behavior remains supported.');
echo "Brand font metadata tests passed ($checks checks).\n";
$colors=['styles'=>['link'=>'#3261b5','button'=>'#c10304','buttonText'=>'#fff5e6']];
$plain=audienceDesignStarters()['blank']['design'];
$plain['rows'][0]['columns'][0][]=['id'=>'brand-button','type'=>'button','text'=>'Learn more','url'=>'https://example.test','inherit'=>['background']];
$plain['rows'][0]['columns'][0][0]['html']='<p><a href="https://example.test">Default link</a> <a href="https://example.test" style="color:#993399">Custom link</a></p>';
$branded=audienceDesignBrandDefaults($plain,$colors);
$expect($branded['styles']['link']==='#3261b5'&&$branded['styles']['button']==='#c10304'&&$branded['styles']['buttonText']==='#fff5e6','Editor link and button defaults come from the Brand Kit.');
$render=audienceDesignHtml($branded);
$expect(str_contains($render,'a{color:#3261b5;')&&str_contains($render,'background:#c10304;color:#fff5e6'),'Default link and button colors reach email output.');
$expect(str_contains($render,'color:#993399'),'Explicit rich-text link colors are preserved.');
$saved=audienceDesign(json_decode(json_encode($branded),true));
$expect(audienceDesignBrandDefaults($saved,['styles'=>['link'=>'#224488']])['styles']['link']==='#224488','Inherited defaults track the Brand Kit when reopening a template.');
$saved['styles']['link']='#007c89';$saved['styles']['brandColors']['link']=false;
$expect(audienceDesignBrandDefaults($saved,$colors)['styles']['link']==='#007c89','An explicitly chosen teal remains an override after saving.');
$legacy=$plain;unset($legacy['styles']['brandColors']);$legacy['styles']['link']='#112233';$legacy['styles']['button']='#445566';
$legacy=audienceDesignBrandDefaults($legacy,$colors);
$expect($legacy['styles']['link']==='#112233'&&$legacy['styles']['button']==='#445566','Existing custom template palettes remain overrides.');
$plain['rows'][0]['columns'][0][1]['background']='#228822';$plain['rows'][0]['columns'][0][1]['styles']['color']='#ffffcc';$plain['rows'][0]['columns'][0][1]['inherit']=[];
$render=audienceDesignHtml(audienceDesignBrandDefaults($plain,$colors));
$expect(str_contains($render,'background:#228822;color:#ffffcc'),'Explicit button background and text colors are preserved.');
$withoutKit=audienceDesignBrandDefaults($plain,[]);
$expect($withoutKit['styles']['link']==='#007c89'&&$withoutKit['styles']['button']==='#007c89','Unconfigured Brand Kits retain the standard defaults.');
$expect($plain['styles']['link']==='#007c89','Resolving editor defaults does not mutate the saved source snapshot.');
echo "Brand Kit font and color tests passed ($checks total checks).\n";
