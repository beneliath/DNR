<?php
declare(strict_types=1);
require_once __DIR__.'/audience_design_builder.php';
require_once __DIR__.'/audience_font.php';
require_once __DIR__.'/audience_svg.php';

// Includes embedded image snapshots. Save, preview and reusable sections must
// accept the same bounded payload, including Brand Kit logos up to 2 MB.
const AUDIENCE_DESIGN_MAX_BYTES=10000000;
const AUDIENCE_DESIGN_BRAND_COLORS=['link'=>'#007c89','button'=>'#007c89','buttonText'=>'#ffffff'];
function audienceDesignPayload(mixed $value):string
{
    if(is_string($value)&&strlen($value)>AUDIENCE_DESIGN_MAX_BYTES)throw new InvalidArgumentException('Keep the email design below 10 MB, including images.');
    return audienceDesignString($value,AUDIENCE_DESIGN_MAX_BYTES);
}

function audienceDesignString(mixed $value,int $max=100):string
{
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$max||str_contains($value,"\0"))throw new InvalidArgumentException('Invalid or oversized email content.');
    return $value;
}
function audienceDesignNumber(mixed $value,int $min,int $max):int
{
    if(!is_numeric($value)||(int)$value<$min||(int)$value>$max)throw new InvalidArgumentException('A style value is out of range.');return (int)$value;
}
function audienceDesignColor(mixed $value):string
{
    if(!is_string($value)||!preg_match('/^#[a-f0-9]{6}$/iD',$value))throw new InvalidArgumentException('Choose a valid color.');return $value;
}
function audienceDesignUrl(string $url,bool $image=false):bool
{
    if($url==='')return true;
    if($image)return preg_match('~^https://[^\s<>"\x00-\x1f]+$~iD',$url)===1;
    return preg_match('~^(https?://[^\s<>"\x00-\x1f]+|mailto:[^\s<>"\x00-\x1f]+|tel:[+0-9 ()-]+|#[a-zA-Z][a-zA-Z0-9_-]{0,79}|\*\|(?:UNSUB|UPDATE_PROFILE|ARCHIVE|SURVEY:[a-f0-9]{64}|POLL:[a-f0-9]{64}:[0-9]{1,2})\|\*)$~iD',$url)===1;
}
function audienceDesignNormalizeLink(string $url):string
{
    $url=trim($url);
    if(preg_match('~^[A-Za-z0-9.!#$%&\x27*+/=?^_`{|}\~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$~D',$url))return 'mailto:'.$url;
    if(preg_match('~^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?::[0-9]{1,5})?(?:[/?#][^\s<>"\x00-\x1f]*)?$~D',$url))return 'https://'.$url;
    return $url;
}
function audienceDesignImage(mixed $image,int $maxBytes=2000000,bool $allowSvg=false):string
{
    if($image==='')return '';
    if($allowSvg&&is_string($image)&&str_starts_with($image,'data:image/svg+xml;'))return audienceSvgImage($image,$maxBytes);
    if(!is_string($image)||!preg_match('~^data:image/(png|jpeg|webp|gif);base64,([A-Za-z0-9+/=]+)$~D',$image,$m))throw new InvalidArgumentException('Upload a PNG, JPEG, WebP or GIF image.');
    $bytes=base64_decode($m[2],true);$info=$bytes===false?false:@getimagesizefromstring($bytes);
    if(!$info||strlen($bytes)>$maxBytes||$info[0]*$info[1]>16000000||$info['mime']!=='image/'.$m[1])throw new InvalidArgumentException('Use an image up to '.($maxBytes/1000000).' MB and 16 megapixels.');return $image;
}
function audienceDesignStyle(mixed $input):array
{
    if(!is_array($input))throw new InvalidArgumentException('Invalid email styles.');$out=[];
    foreach($input as$key=>$value){
        if(in_array($key,['background','color','borderColor','borderTopColor','borderBottomColor'],true))$out[$key]=audienceDesignColor($value);
        elseif(in_array($key,['padding','margin','paragraphSpacing'],true))$out[$key]=audienceDesignNumber($value,0,80);
        elseif(in_array($key,['size','heading1','heading2','heading3','heading4','buttonSize'],true))$out[$key]=audienceDesignNumber($value,10,72);
        elseif($key==='dividerWidth')$out[$key]=audienceDesignNumber($value,10,100);
        elseif($key==='dividerHeight')$out[$key]=audienceDesignNumber($value,1,12);
        elseif($key==='lineHeight')$out[$key]=audienceDesignNumber($value,100,240);
        elseif($key==='letterSpacing')$out[$key]=audienceDesignNumber($value,-2,10);
        elseif(in_array($key,['borderWidth','borderTopWidth','borderBottomWidth'],true))$out[$key]=audienceDesignNumber($value,0,12);
        elseif($key==='font'&&is_string($value)&&preg_match('/^(?:MOEDBrandFont,)?(?:Arial|Georgia|Verdana|Tahoma|Times New Roman)$/D',$value))$out[$key]=$value;
        elseif($key==='align'&&in_array($value,['left','center','right','justify'],true))$out[$key]=$value;
        elseif($key==='direction'&&in_array($value,['ltr','rtl'],true))$out[$key]=$value;
        elseif($key==='weight'&&in_array($value,['normal','bold'],true))$out[$key]=$value;
        elseif($key==='fontStyle'&&in_array($value,['normal','italic'],true))$out[$key]=$value;
        elseif(in_array($key,['borderStyle','borderTopStyle','borderBottomStyle'],true)&&in_array($value,['solid','dashed','dotted'],true))$out[$key]=$value;
        else throw new InvalidArgumentException('Unsupported email style.');
    }return $out;
}
function audienceDesignCondition(mixed $input):array
{
    if(!is_array($input)||!is_array($input['rules']??[])||count($input['rules']??[])>10)throw new InvalidArgumentException('Use up to ten dynamic-content rules.');
    $out=['match'=>($input['match']??'all')==='any'?'any':'all','rules'=>[]];
    foreach($input['rules']??[]as$rule){if(!is_array($rule))throw new InvalidArgumentException('Invalid dynamic-content rule.');$field=audienceDesignString($rule['field']??'',80);if(!preg_match('/^(?:[A-Z][A-Z0-9_:]{0,49}|tag:[1-9][0-9]*|group:[1-9][0-9]*)$/D',$field)||!in_array($rule['operator']??'', ['is','is_not','contains','not_contains','empty','not_empty'],true))throw new InvalidArgumentException('Invalid dynamic-content rule.');$out['rules'][]=['field'=>$field,'operator'=>$rule['operator'],'value'=>audienceDesignString($rule['value']??'',300)];}return $out;
}
function audienceDesignMatches(array $condition,array $context):bool
{
    if(!$condition['rules'])return true;$results=[];foreach($condition['rules']as$rule){$v=(string)($context[$rule['field']]??'');$wanted=$rule['value'];$results[]=match($rule['operator']){'is'=>$v===$wanted,'is_not'=>$v!==$wanted,'contains'=>str_contains(mb_strtolower($v),mb_strtolower($wanted)),'not_contains'=>!str_contains(mb_strtolower($v),mb_strtolower($wanted)),'empty'=>$v==='','not_empty'=>$v!=='',default=>false};}return $condition['match']==='any'?in_array(true,$results,true):!in_array(false,$results,true);
}
function audienceDesignWebFont(mixed $value):string
{
    if($value===''||$value===null)return '';
    if(!is_string($value)||strlen($value)>700000||!preg_match('~^data:font/(woff2?|ttf);base64,([A-Za-z0-9+/=]+)$~D',$value,$m))throw new InvalidArgumentException('Upload a WOFF, WOFF2 or TTF font up to 500 KB.');
    $bytes=base64_decode($m[2],true);$signature=match($m[1]){'woff'=>'wOFF','woff2'=>'wOF2',default=>"\x00\x01\x00\x00"};
    if($bytes===false||strlen($bytes)<48||strlen($bytes)>500000||substr($bytes,0,4)!==$signature)throw new InvalidArgumentException('The uploaded font has an invalid format.');
    if($m[1]!=='ttf'&&unpack('N',substr($bytes,8,4))[1]!==strlen($bytes))throw new InvalidArgumentException('The uploaded font is incomplete.');
    return $value;
}
function audienceDesignDocument(array $input):array
{
    if(($input['schema']??null)!==1)throw new InvalidArgumentException('Unsupported email design version.');
    audienceDesignPayload(json_encode($input,JSON_THROW_ON_ERROR));
    $s=$input['styles']??[];if(!is_array($s))throw new InvalidArgumentException('Invalid email styles.');
    $styles=[];foreach(['background'=>'#f4f4f4','body'=>'#ffffff','text'=>'#24292d','link'=>'#007c89','button'=>'#007c89','buttonText'=>'#ffffff']as$key=>$default)$styles[$key]=audienceDesignColor($s[$key]??$default);
    $brandColors=$s['brandColors']??[];if(!is_array($brandColors))throw new InvalidArgumentException('Invalid Brand Kit color defaults.');
    foreach(AUDIENCE_DESIGN_BRAND_COLORS as$key=>$default){
        if(array_key_exists($key,$brandColors)&&!is_bool($brandColors[$key]))throw new InvalidArgumentException('Invalid Brand Kit color default.');
        $styles['brandColors'][$key]=$brandColors[$key]??(strtolower($styles[$key])===$default);
    }
    $styles['font']=in_array($s['font']??'', ['Arial','Georgia','Verdana','Tahoma','Times New Roman'],true)?$s['font']:'Arial';
    $styles['webFont']=audienceDesignWebFont($s['webFont']??'');
    $styles['webFontName']=audienceDesignString($s['webFontName']??'',255);
    $styles['webFontDefault']=($s['webFontDefault']??true)!==false;
    foreach(['width'=>[600,400,800],'fontSize'=>[16,12,30],'lineHeight'=>[160,100,240],'letterSpacing'=>[0,-2,10],'paragraphSpacing'=>[16,0,80],'heading1'=>[32,12,72],'heading2'=>[28,12,72],'heading3'=>[24,12,72],'heading4'=>[20,12,72],'buttonSize'=>[16,10,72],'buttonRadius'=>[4,0,50],'dividerHeight'=>[1,1,12],'dividerWidth'=>[100,10,100]]as$key=>[$default,$min,$max])$styles[$key]=audienceDesignNumber($s[$key]??$default,$min,$max);
    $styles['direction']=($s['direction']??'ltr')==='rtl'?'rtl':'ltr';$styles['linkUnderline']=($s['linkUnderline']??true)!==false;
    $out=['schema'=>1,'mode'=>($input['mode']??'visual')==='code'?'code':'visual','styles'=>$styles,'mobile'=>audienceDesignStyle($input['mobile']??[]),'rows'=>[],'plainText'=>isset($input['plainText'])?audienceDesignString($input['plainText'],100000):null,'code'=>audienceDesignString($input['code']??'',3000000),'areas'=>[]];
    if(!is_array($input['areas']??[]))throw new InvalidArgumentException('Invalid editable regions.');
    foreach($input['areas']??[]as$key=>$value){if(!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',(string)$key))throw new InvalidArgumentException('Invalid editable region name.');$out['areas'][$key]=audienceDesignRichText(audienceDesignString($value,30000));}
    $footer=$input['footer']??[];if(!is_array($footer))throw new InvalidArgumentException('Invalid footer.');
    $out+=audienceDesignBuilderSettings($input);
    $out['footer']=['text'=>audienceDesignString($footer['text']??'',2000),'styles'=>audienceDesignStyle($footer['styles']??[])];
    if(!is_array($input['rows']??null)||count($input['rows'])>50)throw new InvalidArgumentException('Use up to 50 layouts.');$count=0;$ids=[];$anchors=[];
    foreach($input['rows']as$ri=>$row){
        if(!is_array($row)||!is_array($row['columns']??null)||count($row['columns'])<1||count($row['columns'])>4)throw new InvalidArgumentException('Use one to four columns per layout.');
        $rid=audienceDesignKey($row['id']??'row-'.$ri,$ids);$clean=['id'=>$rid,'name'=>audienceDesignString($row['name']??'Section '.($ri+1),100),'region'=>in_array($row['region']??'', ['preheader','header','body','footer'],true)?$row['region']:'body','styles'=>audienceDesignStyle($row['styles']??[]),'mobile'=>audienceDesignStyle($row['mobile']??[]),'condition'=>audienceDesignCondition($row['condition']??[]),'columns'=>[]];
        foreach($row['columns']as$ci=>$column){if(!is_array($column))throw new InvalidArgumentException('Invalid content column.');$blocks=[];
            foreach($column as$bi=>$block){
                if(++$count>150||!is_array($block)||!in_array($block['type']??'', ['heading','text','image','button','divider','spacer','image-card','image-group','video','social','code','toc','boxed-text','image-text','share'],true))throw new InvalidArgumentException('Invalid content block or too many blocks.');
                $type=$block['type'];$b=['type'=>$type,'id'=>audienceDesignKey($block['id']??'block-'.$ri.'-'.$ci.'-'.$bi,$ids)];
                foreach(['text'=>30000,'alt'=>300,'url'=>2048,'anchor'=>80,'caption'=>2000,'code'=>50000]as$key=>$limit)$b[$key]=audienceDesignString($block[$key]??'',$limit);
                $b['url']=audienceDesignNormalizeLink($b['url']);if(!audienceDesignUrl($b['url']))throw new InvalidArgumentException('Use an HTTP, HTTPS, email, telephone, or anchor link.');
                if($b['anchor']!==''){if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D',$b['anchor'])||isset($anchors[$b['anchor']]))throw new InvalidArgumentException('Use a unique anchor name without spaces.');$anchors[$b['anchor']]=true;}
                $b['html']=in_array($type,['heading','text','image-card','boxed-text','image-text'],true)?audienceDesignRichText(audienceDesignPayload($block['html']??'')):'';
                $b['align']=in_array($block['align']??'', ['left','center','right','justify'],true)?$block['align']:'left';
                foreach(['padding'=>[24,0,80],'margin'=>[0,0,80],'size'=>[$type==='heading'?32:16,10,72],'height'=>[24,4,200],'radius'=>[4,0,50],'headingLevel'=>[1,1,4],'dividerWidth'=>[100,10,100],'dividerHeight'=>[1,1,12]]as$key=>[$default,$min,$max])$b[$key]=audienceDesignNumber($block[$key]??$default,$min,$max);
                $b['imagePosition']=($block['imagePosition']??'left')==='right'?'right':'left';$b['imageWidth']=audienceDesignNumber($block['imageWidth']??40,20,80);
                $platforms=$block['sharePlatforms']??['facebook','x','linkedin','email'];if(!is_array($platforms)||count($platforms)>4||array_diff($platforms,['facebook','x','linkedin','email']))throw new InvalidArgumentException('Choose supported sharing services.');$b['sharePlatforms']=array_values(array_unique($platforms));
                $b['bold']=!empty($block['bold']);$b['italic']=!empty($block['italic']);$b['color']=audienceDesignColor($block['color']??$styles['text']);$b['background']=audienceDesignColor($block['background']??$styles['button']);$b['image']=audienceDesignImage($block['image']??'',2000000,true);
                $b['styles']=audienceDesignStyle($block['styles']??[]);$b['mobile']=audienceDesignStyle($block['mobile']??[]);$b['linkDevices']=($block['linkDevices']??true)!==false;$b['includeToc']=!empty($block['includeToc']);
                $b['inherit']=[];if(!is_array($block['inherit']??[]))throw new InvalidArgumentException('Invalid inherited styles.');foreach($block['inherit']??[]as$key){if(!in_array($key,['color','size','padding','align','font','lineHeight','letterSpacing','paragraphSpacing','margin','background','radius','dividerHeight','dividerWidth'],true))throw new InvalidArgumentException('Invalid inherited style.');$b['inherit'][]=$key;}
                $b['condition']=audienceDesignCondition($block['condition']??[]);$b['images']=[];$b['social']=[];
                if(!is_array($block['images']??[])||count($block['images']??[])>4)throw new InvalidArgumentException('Use up to four images in a group.');
                foreach($block['images']??[]as$image){if(!is_array($image))throw new InvalidArgumentException('Invalid image.');$url=audienceDesignString($image['url']??'',2048);if(!audienceDesignUrl($url))throw new InvalidArgumentException('Invalid image link.');$b['images'][]=['image'=>audienceDesignImage($image['image']??''),'alt'=>audienceDesignString($image['alt']??'',300),'url'=>$url];}
                if(!is_array($block['social']??[])||count($block['social']??[])>10)throw new InvalidArgumentException('Use up to ten social links.');
                foreach($block['social']??[]as$link){if(!is_array($link))throw new InvalidArgumentException('Invalid social link.');$url=audienceDesignString($link['url']??'',2048);if(!audienceDesignUrl($url))throw new InvalidArgumentException('Invalid social link.');$b['social'][]=['label'=>audienceDesignString($link['label']??'',60),'url'=>$url];}
                $blocks[]=$b;
            }$clean['columns'][]=$blocks;
        }$out['rows'][]=$clean;
    }return $out;
}
function audienceDesignKey(mixed $value,array &$ids):string
{
    if(!is_string($value)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D',$value)||isset($ids[$value]))throw new InvalidArgumentException('Content identifiers must be unique.');$ids[$value]=true;return $value;
}
function audienceDesignCss(array $style,bool $important=false):string
{
    $properties=['background'=>'background-color','color'=>'color','font'=>'font-family','size'=>'font-size','padding'=>'padding','margin'=>'margin','align'=>'text-align','lineHeight'=>'line-height','letterSpacing'=>'letter-spacing','paragraphSpacing'=>'--paragraph-spacing','direction'=>'direction','borderColor'=>'border-color','borderWidth'=>'border-width','borderStyle'=>'border-style','weight'=>'font-weight','fontStyle'=>'font-style','borderTopColor'=>'border-top-color','borderTopWidth'=>'border-top-width','borderTopStyle'=>'border-top-style','borderBottomColor'=>'border-bottom-color','borderBottomWidth'=>'border-bottom-width','borderBottomStyle'=>'border-bottom-style'];$out='';
    foreach($style as$key=>$value){if(!isset($properties[$key]))continue;$unit=in_array($key,['size','padding','margin','letterSpacing','paragraphSpacing','borderWidth','borderTopWidth','borderBottomWidth'],true)?'px':($key==='lineHeight'?'%':'');$out.=$properties[$key].':'.$value.$unit.($important?'!important':'').';';}return $out;
}
function audienceDesignBlockStyle(array $b,array $row,array $s):array
{
    $defaults=['weight'=>$b['type']==='heading'?'bold':'normal','color'=>$s['text'],'size'=>$b['type']==='heading'?$s['heading'.$b['headingLevel']]:($b['type']==='button'?$s['buttonSize']:$s['fontSize']),'font'=>$s['font'],'lineHeight'=>$s['lineHeight'],'letterSpacing'=>$s['letterSpacing'],'paragraphSpacing'=>$s['paragraphSpacing'],'direction'=>$s['direction'],'padding'=>24,'margin'=>0,'align'=>'left'];
    $defaults=array_replace($defaults,$s['_typography'][$b['type']==='heading'?'heading'.$b['headingLevel']:'paragraph']??[]);$style=array_replace($defaults,$row['styles']);foreach(['color','size','padding','margin','align']as$key)if(!in_array($key,$b['inherit'],true))$style[$key]=$b[$key];return array_replace($style,$b['styles']);
}
/** Interpret a bounded subset of Mailchimp conditional merge syntax without eval. */
function audienceDesignMerge(string $text,array $context,bool $html=true):string
{
    $parts=preg_split('/(\*\|(?:IF:[^|]{1,300}|IFNOT:[^|]{1,300}|ELSEIF:[^|]{1,300}|ELSE:|END:IF)\|\*)/',$text,-1,PREG_SPLIT_DELIM_CAPTURE);$stack=[];$active=true;$out='';
    $test=static function(string $expr)use($context):bool{if(!preg_match('/^([A-Z][A-Z0-9_:]*)(?:\s*(!=|>=|<=|=|>|<)\s*(.*))?$/D',trim(html_entity_decode($expr,ENT_QUOTES,'UTF-8')),$m))return false;$v=(string)($context[$m[1]]??'');$wanted=trim($m[3]??'',"\"' ");return match($m[2]??''){'='=>$v===$wanted,'!='=>$v!==$wanted,'>'=>is_numeric($v)&&is_numeric($wanted)&&(float)$v>(float)$wanted,'<'=>is_numeric($v)&&is_numeric($wanted)&&(float)$v<(float)$wanted,'>='=>is_numeric($v)&&is_numeric($wanted)&&(float)$v>=(float)$wanted,'<='=>is_numeric($v)&&is_numeric($wanted)&&(float)$v<=(float)$wanted,default=>$v!==''};};
    foreach($parts?:[]as$part){if(preg_match('/^\*\|(IF|IFNOT|ELSEIF):(.*?)\|\*$/sD',$part,$m)){
        if($m[1]==='ELSEIF'){$last=array_pop($stack);if(!$last)continue;$match=!$last['matched']&&$test($m[2]);$active=$last['parent']&&$match;$last['matched']=$last['matched']||$match;$stack[]=$last;}
        else{if(count($stack)>=20)throw new InvalidArgumentException('Conditional merge tags are nested too deeply.');$match=$test($m[2]);if($m[1]==='IFNOT')$match=!$match;$stack[]=['parent'=>$active,'matched'=>$match];$active=$active&&$match;}
    }elseif($part==='*|ELSE:|*'){$last=array_pop($stack);if($last){$active=$last['parent']&&!$last['matched'];$last['matched']=true;$stack[]=$last;}}
    elseif($part==='*|END:IF|*'){$last=array_pop($stack);if($last)$active=$last['parent'];}elseif($active)$out.=$part;}
    return preg_replace_callback('/\*\|([A-Z][A-Z0-9_:]{0,60})\|\*/',static function($m)use($context,$html){if(!array_key_exists($m[1],$context))return $m[0];$v=(string)$context[$m[1]];return $html?htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):$v;},$out);
}
function audienceDesignCodeWithAreas(string $code,array $areas):string
{
    if(!$areas)return $code;$doc=new DOMDocument();$old=libxml_use_internal_errors(true);try{$doc->loadHTML('<?xml encoding="UTF-8">'.$code,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    foreach($doc->getElementsByTagName('*')as$node){$name=$node->getAttribute('mc:edit');if(!array_key_exists($name,$areas))continue;$region=new DOMDocument();$old=libxml_use_internal_errors(true);try{$region->loadHTML('<?xml encoding="UTF-8"><body>'.audienceDesignRichText($areas[$name]).'</body>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}while($node->firstChild)$node->removeChild($node->firstChild);$body=$region->getElementsByTagName('body')->item(0);if($body)foreach(iterator_to_array($body->childNodes)as$child)$node->appendChild($doc->importNode($child,true));}
    return preg_replace('/<\?xml[^>]*>\s*/','',$doc->saveHTML()?:'');
}
/** Strip active markup and keep an inert copy of imported email markup; source stays intact. */
function audienceDesignSafeCode(string $code,array $areas=[]):array
{
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);try{$doc->loadHTML('<?xml encoding="UTF-8">'.$code,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $warnings=[];$regionNames=[];
    foreach(iterator_to_array($doc->getElementsByTagName('*'))as$node){
        $name=$node->getAttribute('mc:edit');if($name!==''&&preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$name)){$regionNames[$name]=$node->textContent;if(isset($areas[$name])){while($node->firstChild)$node->removeChild($node->firstChild);$fragment=$doc->createDocumentFragment();$regionDoc=new DOMDocument();$old=libxml_use_internal_errors(true);try{$regionDoc->loadHTML('<?xml encoding="UTF-8"><body>'.audienceDesignRichText($areas[$name]).'</body>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}$body=$regionDoc->getElementsByTagName('body')->item(0);if($body)foreach(iterator_to_array($body->childNodes)as$child)$node->appendChild($doc->importNode($child,true));}}
    }
    $allowed=['html','head','body','title','meta','style','table','thead','tbody','tfoot','tr','td','th','col','colgroup','caption','div','span','p','br','hr','a','img','h1','h2','h3','h4','h5','h6','b','strong','em','i','u','s','ul','ol','li','blockquote','center'];
    foreach(iterator_to_array($doc->getElementsByTagName('*'))as$node){if(!$node->parentNode)continue;$tag=strtolower($node->tagName);
        if(!in_array($tag,$allowed,true)){$warnings['markup']='Active or unsupported markup is omitted from the safe preview. The original source is retained.';$active=in_array($tag,['script','iframe','object','embed','svg','math','form','input','button','video','audio','link','base'],true);if(!$active){foreach(iterator_to_array($node->childNodes)as$child)$node->parentNode->insertBefore($child,$node);}$node->parentNode->removeChild($node);continue;}
        if($tag==='meta'){if(strtolower($node->getAttribute('http-equiv'))!==''){$node->parentNode->removeChild($node);continue;}}
        if($tag==='style'){if(preg_match('/url\s*\(|@import|expression|behavior|binding/i',$node->textContent))$warnings['css']='External or unsupported CSS is omitted from the protected preview; the original source is retained.';$node->textContent=audienceDesignSafeCss($node->textContent,true);}
        foreach(iterator_to_array($node->attributes)as$attribute){$key=strtolower($attribute->name);$value=$attribute->value;$keep=in_array($key,['width','height','align','valign','bgcolor','border','cellpadding','cellspacing','colspan','rowspan','alt','title','class','id','role','charset','name','content'],true);
            if($key==='style'){if(preg_match('/url\s*\(|expression|behavior|binding/i',$value))$warnings['css']='External or unsupported CSS is omitted from the protected preview; the original source is retained.';$node->setAttribute('style',audienceDesignSafeCss($value,false));$keep=true;}
            if(in_array($key,['mc:edit','mc:repeatable','mc:variant','mc:hideable'],true))$keep=preg_match('/^[a-zA-Z0-9 _-]{0,100}$/D',$value)===1;
            if($key==='href'){$value=audienceDesignNormalizeLink($value);$node->setAttribute('href',$value);$keep=$value!==''&&audienceDesignUrl($value);if(!$keep)$warnings['links']='A link is missing a supported destination. Review it in the link checker.';}
            if($key==='src'){$keep=audienceDesignUrl($value,true)||str_starts_with($value,'data:image/');if(str_starts_with($value,'data:')){try{$value=audienceDesignImage($value,2000000,true);$node->setAttribute('src',$value);}catch(InvalidArgumentException){$keep=false;}}if(!$keep)$warnings['images']='Some image references are unsupported or missing. Upload them or include them in the ZIP.';if(str_starts_with($value,'https://'))$warnings['remote']='Remote images remain in the source; the protected preview blocks remote image loading.';}
            if(!$keep){$node->removeAttribute($attribute->name);if(str_starts_with($key,'on'))$warnings['markup']='Active or unsupported markup is omitted from the safe preview. The original source is retained.';}
        }
    }
    $html=$doc->saveHTML()?:'';$html=preg_replace('/<\?xml[^>]*>\s*/','',$html);return ['html'=>$html,'warnings'=>array_values($warnings),'regions'=>$regionNames];
}
function audienceDesignSafeCss(string $css,bool $sheet):string
{
    $css=preg_replace('~/\*.*?\*/~s','',$css)??'';if(!$sheet){$out='';$allowed=['color','background','background-color','font','font-family','font-size','font-weight','font-style','text-decoration','text-align','line-height','letter-spacing','direction','padding','padding-top','padding-bottom','padding-left','padding-right','margin','margin-top','margin-bottom','margin-left','margin-right','width','max-width','min-width','height','max-height','min-height','border','border-top','border-bottom','border-left','border-right','border-color','border-width','border-style','border-radius','display','vertical-align','float','clear','border-collapse','border-spacing','table-layout','word-break','overflow-wrap'];
        foreach(explode(';',$css)as$declaration){$pair=explode(':',$declaration,2);if(count($pair)!==2)continue;[$key,$value]=array_map('trim',$pair);$key=strtolower($key);if(in_array($key,$allowed,true)&&preg_match('/^[#a-zA-Z0-9\s.,%()\/\'"!:+-]+$/D',$value)&&!preg_match('/url|expression|javascript|behavior|binding|var\(/i',$value))$out.=$key.':'.$value.';';}return $out;}
    $css=preg_replace('/@import[^;]*;/i','',$css)??'';$out='';$offset=0;$length=strlen($css);
    while($offset<$length){$open=strpos($css,'{',$offset);if($open===false)break;$selector=trim(substr($css,$offset,$open-$offset));$depth=1;$end=$open+1;for(;$end<$length&&$depth;$end++){if($css[$end]==='{')$depth++;elseif($css[$end]==='}')$depth--;if($depth>8)break;}if($depth!==0)break;$body=substr($css,$open+1,$end-$open-2);$offset=$end;
        if(preg_match('/^@media\s+(?:(?:only\s+)?screen\s+and\s+)?\((?:(?:max|min)-width\s*:\s*[0-9]{1,4}px|prefers-color-scheme\s*:\s*(?:light|dark))\)(?:\s+and\s+\((?:(?:max|min)-width\s*:\s*[0-9]{1,4}px|prefers-color-scheme\s*:\s*(?:light|dark))\))*$/iD',$selector))$out.=$selector.'{'.audienceDesignSafeCss($body,true).'}';
        elseif(preg_match('/^[.#a-zA-Z0-9\s>+~_:\[\]="*,-]+$/D',$selector)&&!str_contains($selector,'@'))$out.=$selector.'{'.audienceDesignSafeCss($body,false).'}';
    }return $out;
}
function audienceDesignRender(array $input,?array $context=null):string
{
    $d=audienceDesign($input);$s=$d['styles'];$s['_typography']=$d['typography'];$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$responsive='';$toc=[];
    foreach($d['rows']as$row)foreach($row['columns']as$column)foreach($column as$b)if($b['includeToc']&&$b['type']==='heading'&&($context===null||(audienceDesignMatches($row['condition'],$context)&&audienceDesignMatches($b['condition'],$context))))$toc[]=['anchor'=>$b['anchor']?:$b['id'],'label'=>$b['text']?:strip_tags($b['html'])];
    $tocHtml='<ul>';foreach($toc as$item)$tocHtml.='<li><a href="#'.$e($item['anchor']).'">'.$e($item['label']).'</a></li>';$tocHtml.='</ul>';
    $footer='<div class="email-footer" style="padding:32px;text-align:center;font-size:12px;color:#60676b;'.audienceDesignCss(array_replace($d['regions']['footer'],$d['footer']['styles'])).'">'.nl2br($e($d['footer']['text'])).($d['footer']['text']!==''?'<br><br>':'').'*|LIST:COMPANY|*<br><span style="white-space:pre-line">*|LIST:ADDRESS|*</span><br><br><a href="*|UPDATE_PROFILE|*">Update your preferences</a> or <a href="*|UNSUB|*">unsubscribe</a>.</div>';
    if($d['badge']['enabled'])$footer.='<div class="email-badge" style="text-align:'.$d['badge']['align'].';padding:12px;font-size:12px">Made with MessageMonkey</div>';
    if($d['mode']==='code'){
        $safe=audienceDesignSafeCode($d['code'],$d['areas']);$html=$safe['html'];$hasFooter=str_contains($html,'*|UNSUB|*')&&str_contains($html,'*|UPDATE_PROFILE|*')&&(str_contains($html,'*|LIST:ADDRESS|*')||str_contains($html,'*|LIST:ADDRESSLINE|*'));if(!$hasFooter)$html=str_replace('</body>',$footer.'</body>',$html);$html=str_replace('*|MC:TOC|*',$tocHtml,$html);
        // Reinsert only the validated native brand font after sanitizing arbitrary source CSS.
        if($s['webFont']!=='')$html=str_replace('</head>','<style>@font-face{font-family:MOEDBrandFont;src:url('.$s['webFont'].');font-display:swap}'.($s['webFontDefault']?'body{font-family:MOEDBrandFont,'.$s['font'].'}':'').'</style></head>',$html);
        return $context===null?$html:audienceDesignMerge($html,$context);
    }
    $body='';
    foreach($d['rows']as$row){$row['styles']=array_replace($d['regions'][$row['region']],$row['styles']);
        if($context!==null&&!audienceDesignMatches($row['condition'],$context))continue;
        $rowStyle=audienceDesignCss($row['styles']);$responsive.='.'.$row['id'].'{'.audienceDesignCss(array_replace($row['styles'],$row['mobile']),true).'}';
        $body.='<tr><td class="'.$row['id'].'" style="'.$rowStyle.'"><table role="presentation" width="100%"><tr>';
        foreach($row['columns']as$column){$body.='<td class="email-column" valign="top" width="'.(100/count($row['columns'])).'%">';
            foreach($column as$b){if($context!==null&&!audienceDesignMatches($b['condition'],$context))continue;
                $style=audienceDesignBlockStyle($b,$row,$s);if($s['webFont']!==''&&$s['webFontDefault']&&$style['font']===$s['font']&&in_array('font',$b['inherit'],true)&&empty($row['styles']['font'])&&empty($b['styles']['font']))$style['font']='MOEDBrandFont,'.$s['font'];$class=$b['id'];$mobileStyle=$b['linkDevices']?[]:$b['mobile'];$mobileDefaults=array_intersect_key($d['mobile'],array_flip($b['inherit']));if(in_array('size',$b['inherit'],true)&&$b['type']==='heading'){$mobileDefaults['size']=$d['mobile']['heading'.$b['headingLevel']]??$style['size'];}elseif(in_array('size',$b['inherit'],true)&&$b['type']==='button')$mobileDefaults['size']=$d['mobile']['buttonSize']??$style['size'];$responsive.='.'.$class.'{'.audienceDesignCss(array_replace($style,$mobileDefaults,$row['mobile'],$mobileStyle),true).'}';
                $mobileEffective=array_replace($style,$mobileDefaults,$row['mobile'],$mobileStyle);$responsive.='.'. $class.' p{margin-bottom:'.$mobileEffective['paragraphSpacing'].'px!important}';
                $text=$b['html']!==''?$b['html']:nl2br($e($b['text']));$text=str_replace('*|MC:TOC|*',$tocHtml,$text);if(str_contains($b['html'],'<img'))$text.='<div style="clear:both"></div>';$content='';$type=$b['type'];
                $image=static function(array $image,int $width)use($e):string{$content=$image['image']!==''?'<img src="'.$e($image['image']).'" alt="'.$e($image['alt']).'" width="'.$width.'" style="max-width:100%;height:auto">':'<div style="padding:40px;background:#eef1f2;color:#66757c">Add an image</div>';return ($image['url']??'')!==''?'<a href="'.$e($image['url']).'">'.$content.'</a>':$content;};
                $width=max(1,(int)($s['width']/count($row['columns']))-$style['padding']*2);
                if($type==='heading')$content='<h'.$b['headingLevel'].' id="'.$e($b['anchor']?:$b['id']).'" style="margin:0;font-size:inherit;font-weight:inherit">'.$text.'</h'.$b['headingLevel'].'>';
                elseif($type==='text'||$type==='boxed-text')$content='<div'.($b['anchor']!==''?' id="'.$e($b['anchor']).'"':'').'>'.$text.'</div>';
                elseif($type==='image')$content=$image($b,$width);
                elseif($type==='image-card')$content=$image($b,$width).'<div style="padding:16px;background:'.$b['background'].'">'.$text.'</div>';
                elseif($type==='image-text'){$iw=$b['imageWidth'];$media='<td class="email-media" width="'.$iw.'%" valign="top">'.$image($b,max(1,(int)($width*$iw/100))).'</td>';$copy='<td class="email-media" width="'.(100-$iw).'%" valign="top" style="padding:12px">'.$text.'</td>';$content='<table role="presentation" width="100%"><tr>'.($b['imagePosition']==='left'?$media.$copy:$copy.$media).'</tr></table>';}
                elseif($type==='share')$content=audienceDesignShare($b['sharePlatforms'],$context);
                elseif($type==='image-group'){$content='<table role="presentation" width="100%"><tr>';foreach($b['images']as$item)$content.='<td width="'.(100/max(1,count($b['images']))).'%" valign="top">'.$image($item,max(1,(int)($width/max(1,count($b['images']))))).'</td>';$content.='</tr></table>';}
                elseif($type==='video')$content='<a href="'.$e($b['url']?:'https://example.com').'">'.$image(array_replace($b,['url'=>'']),$width).'<span style="display:block;text-align:center">▶ '.$e($b['caption']?:'Watch video').'</span></a>';
                elseif($type==='social'){foreach($b['social']as$link)$content.='<a href="'.$e($link['url']).'" style="display:inline-block;margin:8px;color:'.$s['link'].'">'.$e($link['label']).'</a>';}
                elseif($type==='code'){$safe=audienceDesignSafeCode('<html><body>'.$b['code'].'</body></html>');$content=preg_replace('~^.*?<body[^>]*>|</body>.*$~s','',$safe['html']);}
                elseif($type==='toc')$content=$tocHtml;
                elseif($type==='button'){$buttonBackground=in_array('background',$b['inherit'],true)?$s['button']:$b['background'];$radius=in_array('radius',$b['inherit'],true)?$s['buttonRadius']:$b['radius'];$border=array_intersect_key($b['styles'],array_flip(['borderColor','borderWidth','borderStyle']));$content='<a href="'.$e($b['url']?:'https://example.com').'" style="display:inline-block;padding:14px 24px;background:'.$buttonBackground.';color:'.($b['styles']['color']??$s['buttonText']).';border-radius:'.$radius.'px;text-decoration:none;'.audienceDesignCss($border).'">'.$text.'</a>';foreach(['borderColor','borderWidth','borderStyle']as$key)unset($style[$key]);} 
                elseif($type==='divider'){$dividerWidth=in_array('dividerWidth',$b['inherit'],true)?$s['dividerWidth']:$b['dividerWidth'];$dividerHeight=in_array('dividerHeight',$b['inherit'],true)?$s['dividerHeight']:$b['dividerHeight'];$content='<div class="email-divider" style="margin:0 auto;width:'.$dividerWidth.'%;border-top:'.$dividerHeight.'px '.($b['styles']['borderStyle']??'solid').' '.$b['color'].'"></div>';$responsive.='.'. $class.' .email-divider{width:'.($mobileStyle['dividerWidth']??$d['mobile']['dividerWidth']??$dividerWidth).'%!important;border-top-width:'.($mobileStyle['dividerHeight']??$d['mobile']['dividerHeight']??$dividerHeight).'px!important}';} 
                elseif($type==='spacer')$content='<div style="height:'.$b['height'].'px">&nbsp;</div>';
                if(in_array($type,['text','heading'],true)&&$b['url']!=='')$content='<a href="'.$e($b['url']).'" style="color:'.$s['link'].'">'.$content.'</a>';
                $body.='<div class="email-block '.$class.'" style="'.audienceDesignCss($style).'font-weight:'.($b['bold']?'bold':($style['weight']??'normal')).';font-style:'.($b['italic']?'italic':($style['fontStyle']??'normal')).'">'.$content.'</div>';
            }$body.='</td>';
        }$body.='</tr></table></td></tr>';
    }
    $css='*{box-sizing:border-box}body{margin:0}table{border-collapse:collapse;border-spacing:0}td{padding:0}.email-body{table-layout:fixed;max-width:100%}p{margin:0 0 '.$s['paragraphSpacing'].'px}.email-block p{margin-bottom:var(--paragraph-spacing,'.$s['paragraphSpacing'].'px)}a{color:'.$s['link'].';text-decoration:'.($s['linkUnderline']?'underline':'none').'}img{max-width:100%;height:auto}@media(max-width:600px){.email-media,.email-column{display:block!important;width:100%!important}.email-body{width:100%!important}'.$responsive.'}';
    if($s['webFont']!=='')$css='@font-face{font-family:MOEDBrandFont;src:url('.$s['webFont'].');font-display:swap}'.$css;
    $html='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><style>'.$css.'</style></head><body style="background:'.$s['background'].';color:'.$s['text'].';font-family:'.$s['font'].',serif;font-size:'.$s['fontSize'].'px;line-height:'.($s['lineHeight']/100).';direction:'.$s['direction'].';letter-spacing:'.$s['letterSpacing'].'px">'.($d['preheader']['text']!==''?'<div style="display:none;max-height:0;overflow:hidden;font-size:1px">'.$e($d['preheader']['text']).'</div>':'').'<table role="presentation" width="100%"><tr><td align="center" style="padding:24px 0"><table role="presentation" class="email-body" width="'.$s['width'].'" style="max-width:100%;background:'.$s['body'].';'.audienceDesignCss($d['regions']['page']).'">'.($d['preheader']['showArchive']?'<tr><td style="text-align:center;padding:12px;font-size:12px;'.audienceDesignCss($d['regions']['preheader']).'"><a href="*|ARCHIVE|*">View this email in your browser</a></td></tr>':'').$body.'<tr><td>'.$footer.'</td></tr></table></td></tr></table></body></html>';
    return $context===null?$html:audienceDesignMerge($html,$context);
}
function audienceDesignPlainText(array $design,?array $context=null):string
{
    if(isset($design['plainText']))return $context===null?$design['plainText']:audienceDesignMerge($design['plainText'],$context,false);
    $html=audienceDesignRender($design,$context);$html=preg_replace('~<(head|style)[^>]*>.*?</\1>~si','',$html);
    $html=preg_replace_callback('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~si',static fn($m)=>strip_tags($m[2]).' ('.html_entity_decode($m[1],ENT_QUOTES,'UTF-8').')',$html);
    $html=preg_replace('~<(?:br|hr)\b[^>]*>|</(?:p|div|h[1-6]|tr|li)>~i',"\n",$html);$text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');return trim(preg_replace('/[ \t]+\n/',"\n",preg_replace('/\n{3,}/',"\n\n",$text)));
}
function audienceDesignLinkReport(array $design,?array $context=null):array
{
    $html=audienceDesignRender($design,$context);$doc=new DOMDocument();$old=libxml_use_internal_errors(true);try{$doc->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}$anchors=[];foreach($doc->getElementsByTagName('*')as$n)if($n->hasAttribute('id'))$anchors[]=$n->getAttribute('id');$links=[];
    foreach($doc->getElementsByTagName('a')as$a){if(!$a->hasAttribute('href')&&$a->hasAttribute('id'))continue;$url=$a->getAttribute('href');$status='Ready to check';if(str_starts_with($url,'#'))$status=in_array(substr($url,1),$anchors,true)?'Anchor found':'Missing anchor';elseif(str_contains($url,'*|'))$status=preg_match('/^\*\|(UNSUB|UPDATE_PROFILE|ARCHIVE)\|\*$/D',$url)?'Resolved during delivery':'Unresolved merge tag';elseif(str_contains($url,'example.com')||$url==='')$status='Placeholder link';elseif(!audienceDesignUrl($url))$status='Invalid link';elseif(str_starts_with(strtolower($url),'http://'))$status='HTTP link — review destination';elseif(!str_starts_with(strtolower($url),'https://'))$status='Email or phone link';$links[$url!==''?$url:'missing-'.count($links)]=['url'=>$url,'label'=>mb_substr(trim($a->textContent),0,100),'status'=>$status];}
    $warnings=[];if($context!==null&&($context['LIST:ADDRESS']??'')==='')$warnings[]='The audience mailing address is missing.';foreach($doc->getElementsByTagName('img')as$img)if(trim($img->getAttribute('alt'))==='')$warnings[]='An image is missing alt text.';
    preg_match_all('/\*\|([A-Z][A-Z0-9_:]{0,60})\|\*/',$html,$tags);foreach(array_unique($tags[1])as$tag)if(!in_array($tag,['UNSUB','UPDATE_PROFILE','ARCHIVE','LIST:COMPANY','LIST:ADDRESS'],true))$warnings[]='Unresolved merge tag: '.$tag;
    return ['links'=>array_values($links),'warnings'=>array_values(array_unique($warnings))];
}
function audienceDesignPublicAddress(string $address):bool
{
    if(!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    foreach(['100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4']as$cidr){[$network,$bits]=explode('/',$cidr);$mask=(0xffffffff<< (32-(int)$bits))&0xffffffff;if((ip2long($address)&$mask)===(ip2long($network)&$mask))return false;}return true;
}
/** Public-HTTPS-only HEAD check. Pin the vetted address and never follow redirects. */
function audienceDesignProbeLink(string $url):array
{
    if(!audienceDesignUrl($url,true)||strlen($url)>2048)throw new InvalidArgumentException('Choose a public HTTPS link.');$parts=parse_url($url);
    if(!$parts||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])&&$parts['port']!==443)throw new InvalidArgumentException('Choose a public HTTPS link on port 443.');
    $host=$parts['host'];if(filter_var($host,FILTER_VALIDATE_IP)||!str_contains($host,'.')||str_ends_with($host,'.local')||str_ends_with($host,'.localhost'))throw new InvalidArgumentException('Private and local addresses cannot be checked.');
    $addresses=gethostbynamel($host)?:[];if(!$addresses)throw new InvalidArgumentException('The link hostname could not be resolved.');foreach($addresses as$address)if(!audienceDesignPublicAddress($address))throw new InvalidArgumentException('Private and reserved addresses cannot be checked.');
    $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_PROTOCOLS_STR=>'https',CURLOPT_RESOLVE=>[$host.':443:'.$addresses[0]],CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'MOED MessageMonkey Link Checker']);curl_exec($curl);$code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);
    return ['status'=>$error!==''?'Could not verify':($code>=200&&$code<300?'Available':($code>=300&&$code<400?'Redirect — review destination':($code===405?'HEAD not supported':($code>=400?'HTTP '.$code:'Could not verify')))),'http'=>$code];
}
function audienceDesignImportFile(array $upload):array
{
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name']??''))throw new InvalidArgumentException('Choose an HTML or ZIP file.');
    if(($upload['size']??0)>5000000)throw new InvalidArgumentException('Keep the import below 5 MB.');$name=strtolower($upload['name']??'');
    if(str_ends_with($name,'.html')||str_ends_with($name,'.htm'))return ['code'=>audienceDesignString(file_get_contents($upload['tmp_name']),1000000),'warnings'=>[]];
    if(!str_ends_with($name,'.zip')||!class_exists(ZipArchive::class))throw new InvalidArgumentException('Choose an HTML or ZIP file.');
    $zip=new ZipArchive();if($zip->open($upload['tmp_name'])!==true)throw new InvalidArgumentException('Unable to open the ZIP file.');
    try{$files=[];$total=0;if($zip->numFiles>100)throw new InvalidArgumentException('Use up to 100 files in a template ZIP.');for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$path=$stat['name'];$total+=$stat['size'];if($total>5000000||str_contains($path,'..')||str_starts_with($path,'/')||str_contains($path,'\\'))throw new InvalidArgumentException('The ZIP contains unsafe paths or too much data.');if(preg_match('/\.html?$/i',$path))$files[]=$path;}
        if(count($files)!==1)throw new InvalidArgumentException('Include exactly one HTML file in the ZIP.');$code=$zip->getFromName($files[0]);$base=dirname($files[0]);
        $code=preg_replace_callback('/\bsrc=["\']([^"\']+)["\']/i',static function($m)use($zip,$base){$path=($base==='.'?'':$base.'/').$m[1];$segments=[];foreach(explode('/',$path)as$part){if($part==='..'){if(!$segments)throw new InvalidArgumentException('An image path leaves the template ZIP.');array_pop($segments);}elseif($part!==''&&$part!=='.')$segments[]=$part;}$path=implode('/',$segments);$data=$zip->getFromName($path);if($data===false)return $m[0];$info=@getimagesizefromstring($data);if(!$info)return $m[0];$image='data:'.$info['mime'].';base64,'.base64_encode($data);audienceDesignImage($image);return 'src="'.$image.'"';},$code);
        return ['code'=>audienceDesignString($code,3000000),'warnings'=>[]];
    }finally{$zip->close();}
}
