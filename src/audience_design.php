<?php
declare(strict_types=1);
require_once __DIR__.'/audience_design_advanced.php';

function audienceDesignRichImage(DOMElement $node):string
{
    try{$source=audienceDesignImage($node->getAttribute('src'),2000000,true);}catch(InvalidArgumentException){return '';}
    if($source==='')return '';
    $css=[];foreach(explode(';',$node->getAttribute('style'))as$declaration){$pair=explode(':',$declaration,2);if(count($pair)===2)$css[strtolower(trim($pair[0]))]=trim($pair[1]);}
    $width=$node->getAttribute('width')?:preg_replace('/px$/','',$css['width']??'');
    $side=in_array($node->getAttribute('align'),['left','right'],true)?$node->getAttribute('align'):($css['float']??'');
    $attrs='';$style='max-width:100%;height:auto';
    if(preg_match('/^[0-9]{1,4}$/D',$width)&&(int)$width>0&&(int)$width<=2400){$attrs.=' width="'.(int)$width.'"';$style.=';width:'.(int)$width.'px';}
    if(in_array($side,['left','right'],true)){$attrs.=' align="'.$side.'"';$style.=';float:'.$side.';margin:'.($side==='left'?'0 12px 12px 0':'0 0 12px 12px');}
    return '<img src="'.htmlspecialchars($source,ENT_QUOTES,'UTF-8').'" alt="'.htmlspecialchars(mb_substr($node->getAttribute('alt'),0,300),ENT_QUOTES,'UTF-8').'"'.$attrs.' style="'.$style.'">';
}

/** Keep authored rich text inert and portable across preview and email rendering. */
function audienceDesignRichText(string $html):string
{
    // Embedded image bytes share the document budget; they are not text.
    audienceDesignPayload($html);
    if(mb_strlen(strip_tags($html))>30000)throw new InvalidArgumentException('Keep each text block below 30,000 characters.');
    if($html==='')return '';
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $walk=static function(DOMNode $node)use(&$walk):string{
        if($node instanceof DOMText)return htmlspecialchars($node->textContent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if(!($node instanceof DOMElement))return '';
        $tag=strtolower($node->tagName);if(in_array($tag,['script','style','iframe','object','svg','math','form','input'],true))return '';
        if($tag==='img')return audienceDesignRichImage($node);
        $children='';foreach($node->childNodes as$child)$children.=$walk($child);
        if(!in_array($tag,['p','div','span','font','br','hr','h1','h2','h3','h4','blockquote','strong','b','em','i','u','s','ul','ol','li','a'],true))return $children;
        if($tag==='br'||$tag==='hr')return '<'.$tag.'>';
        $attrs='';$id=$node->getAttribute('id');if(preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D',$id))$attrs=' id="'.$id.'"';if($tag==='a'){$href=audienceDesignNormalizeLink($node->getAttribute('href'));if($href!==''&&audienceDesignUrl($href))$attrs.=' href="'.htmlspecialchars($href,ENT_QUOTES,'UTF-8').'"';}
        $style=$node->getAttribute('style');$declarations=[];foreach(explode(';',audienceDesignSafeCss($style,false))as$declaration){if(preg_match('/^(?:color|background-color|font-family|font-size|font-weight|font-style|text-decoration|text-align|direction|line-height|letter-spacing):/',$declaration))$declarations[]=$declaration;}
        if($tag==='font'){$tag='span';$color=$node->getAttribute('color');if(preg_match('/^#[a-f0-9]{6}$/iD',$color))$declarations[]='color:'.$color;$face=$node->getAttribute('face');if(preg_match('/^(?:MOEDBrandFont,\s*)?(?:Arial|Georgia|Verdana|Tahoma|Times New Roman)$/D',$face))$declarations[]='font-family:'.$face;$sizes=['1'=>10,'2'=>13,'3'=>16,'4'=>18,'5'=>24,'6'=>32,'7'=>48];if(isset($sizes[$node->getAttribute('size')]))$declarations[]='font-size:'.$sizes[$node->getAttribute('size')].'px';}
        if($declarations)$attrs.=' style="'.htmlspecialchars(implode(';',$declarations),ENT_QUOTES,'UTF-8').'"';
        return '<'.$tag.$attrs.'>'.$children.'</'.$tag.'>';
    };
    $out='';$body=$doc->getElementsByTagName('body')->item(0);if($body)foreach($body->childNodes as$child)$out.=$walk($child);return $out;
}
/** Keep wrapping on the media container when a mail client rewrites the image itself. */
function audienceDesignEmailRichText(string $html,int $paragraphSpacing=16):string
{
    if(!str_contains($html,'<img')||!preg_match('/\balign="(?:left|right)"/',$html))return $html;
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="email-rich-content">'.$html.'</div></body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    foreach(iterator_to_array($doc->getElementsByTagName('img'))as$image){
        $side=$image->getAttribute('align');$width=$image->getAttribute('width');
        if(!in_array($side,['left','right'],true)||!preg_match('/^[0-9]{1,4}$/D',$width)||(int)$width<1||(int)$width>2400)continue;
        $media=$image;$parent=$image->parentNode;
        if($parent instanceof DOMElement&&strtolower($parent->tagName)==='a'){
            $children=array_filter(iterator_to_array($parent->childNodes),static fn($node)=>!($node instanceof DOMText&&trim($node->textContent)===''));
            if(count($children)!==1)continue;
            $media=$parent;
        }
        $table=$doc->createElement('table');$table->setAttribute('role','presentation');$table->setAttribute('class','email-rich-image');$table->setAttribute('align',$side);
        $table->setAttribute('width',(string)((int)$width+12));$table->setAttribute('border','0');$table->setAttribute('cellpadding','0');$table->setAttribute('cellspacing','0');
        // Proton iOS injects * { float: none !important }. Inline priority preserves authored wrapping.
        $table->setAttribute('style','float:'.$side.'!important;width:'.((int)$width+12).'px;width:min(100%,'.((int)$width+12).'px);max-width:100%;table-layout:fixed;border-collapse:collapse');
        $cell=$doc->createElement('td');$cell->setAttribute('style','padding:'.($side==='left'?'0 12px 12px 0':'0 0 12px 12px'));
        $row=$doc->createElement('tr');$row->appendChild($cell);$table->appendChild($row);
        $media->parentNode->insertBefore($table,$media);$cell->appendChild($media);
        $image->removeAttribute('align');$image->setAttribute('style','display:block;max-width:100%;height:auto;width:'.$width.'px');
    }
    // Tables cannot live inside paragraphs. Preserve their formatting and paragraph spacing.
    foreach(iterator_to_array($doc->getElementsByTagName('p'))as$paragraph){
        if(!$paragraph->getElementsByTagName('table')->length)continue;
        $replacement=$doc->createElement('div');foreach($paragraph->attributes as$attribute)$replacement->setAttribute($attribute->name,$attribute->value);
        $replacement->setAttribute('class','email-rich-paragraph');$replacement->setAttribute('style',rtrim($paragraph->getAttribute('style'),';').';margin:0 0 '.$paragraphSpacing.'px');
        while($paragraph->firstChild)$replacement->appendChild($paragraph->firstChild);
        $paragraph->parentNode->replaceChild($replacement,$paragraph);
    }
    $out='';$root=$doc->getElementById('email-rich-content');if($root)foreach($root->childNodes as$child)$out.=$doc->saveHTML($child);return $out;
}
/** Versioned, allowlisted email document. No executable HTML or external resource fetching. */
function audienceDesign(array $input): array { return audienceDesignDocument($input); }
/** Resolve editor defaults without rewriting authored colors or sent snapshots. */
function audienceDesignBrandDefaults(array $input,array $brand,bool $newDesign=false):array
{
    $design=audienceDesign($input);
    foreach(AUDIENCE_DESIGN_BRAND_COLORS as$key=>$default){
        if($design['styles']['brandColors'][$key]&&isset($brand['styles'][$key]))$design['styles'][$key]=audienceDesignColor($brand['styles'][$key]);
    }
    $brandWebFont=audienceDesignWebFont($brand['styles']['webFont']??'');
    $usesCurrentFont=$design['styles']['webFont']===''||($brandWebFont!==''&&$design['styles']['webFont']===$brandWebFont);
    if($newDesign||$usesCurrentFont){
        $font=$brand['styles']['font']??'';
        if($newDesign||$design['styles']['font']==='Arial')$design['styles']['font']=in_array($font,['Arial','Georgia','Verdana','Tahoma','Times New Roman'],true)?$font:'Arial';
        if($newDesign||$brandWebFont!==''){
            $design['styles']['webFont']=$brandWebFont;
            $design['styles']['webFontName']=$brandWebFont!==''?audienceBrandFont($brand)['name']:'';
            $design['styles']['webFontDefault']=true;
        }
    }
    return $design;
}
function audienceDesignStarters(): array
{
    $block=static fn($type,$text,$extra=[])=>['type'=>$type,'text'=>$text,'inherit'=>array_values(array_diff(['color','size','font','lineHeight','letterSpacing','paragraphSpacing','background','radius','dividerWidth','dividerHeight'],array_keys($extra)))]+$extra;
    $intro=[$block('heading','Share something great.',['align'=>'center']),$block('text',"Hello *|FNAME|*,\n\nUse this space to share your news, tell your story, and connect with your connections."),$block('button','Learn more',['align'=>'center','url'=>'https://example.com'])];
    $starters=[
        'blank'=>['name'=>'Start from scratch','category'=>'All','design'=>audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[$block('text','Start writing your email here.')]]]]])],
        'newsletter'=>['name'=>'Simple newsletter','category'=>'Newsletter','design'=>audienceDesign(['schema'=>1,'rows'=>[['columns'=>[$intro]],['columns'=>[[$block('divider',''),$block('heading','In this edition'),$block('text','Add your latest updates and a clear next step for your readers.')]]]]])],
        'welcome'=>['name'=>'A warm welcome','category'=>'Welcome','design'=>audienceDesign(['schema'=>1,'styles'=>['background'=>'#e9eee8','button'=>'#315b48'],'rows'=>[['columns'=>[[$block('heading','Welcome to our community.',['align'=>'center']),$block('text','We are glad you are here. Let us introduce ourselves and help you get started.'),$block('button','Explore more',['align'=>'center','url'=>'https://example.com'])]]]]])],
        'event'=>['name'=>'You are invited','category'=>'Invite to an event','design'=>audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[$block('heading','Let’s get together.',['align'=>'center']),$block('text','Join us for an upcoming event. Share the date, time, location, and what to expect.')]]],['columns'=>[[$block('heading','When',['size'=>24]),$block('text','Add date and time')],[$block('heading','Where',['size'=>24]),$block('text','Add event location')]]],['columns'=>[[$block('button','Reserve your place',['align'=>'center','url'=>'https://example.com'])]]]]])],
        'announcement'=>['name'=>'Make an announcement','category'=>'Announce','design'=>audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[$block('heading','Something new is here.'),$block('text','Introduce your big news. Keep it clear, personal, and useful.'),$block('button','Find out more',['url'=>'https://example.com'])]]]]])],
        'thanks'=>['name'=>'A heartfelt thank you','category'=>'Thank you','design'=>audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[$block('heading','Thank you.',['align'=>'center','size'=>48]),$block('text','Your support makes a difference. Let your connections know what they helped make possible.')]]]]])],
    ];
    $layouts=[
        'news-roundup'=>['News roundup','Newsletter',2,'The latest from our community.'],
        'monthly-digest'=>['Monthly digest','Newsletter',3,'A month of ideas and updates.'],
        'editorial'=>['Editorial letter','Newsletter',1,'A note from our team.'],
        'save-the-date'=>['Save the date','Invite to an event',1,'Mark your calendar.'],
        'event-agenda'=>['Event agenda','Invite to an event',2,'A day worth sharing.'],
        'new-chapter'=>['A new chapter','Announce',2,'See what is coming next.'],
        'important-update'=>['Important update','Announce',1,'An update for our community.'],
        'gratitude'=>['You made a difference','Thank you',2,'Look what we accomplished together.'],
        'getting-started'=>['Getting started','Welcome',3,'Your first steps start here.'],
    ];
    foreach($layouts as$key=>[$name,$category,$columns,$headline]){
        $features=[];for($i=1;$i<=$columns;$i++)$features[]=[ $block('heading','Highlight '.$i,['headingLevel'=>2]),$block('text','Share a useful detail, a new idea, or a story that matters to your readers.'),$block('button','Explore more',['url'=>'https://example.com']) ];
        $starters[$key]=['name'=>$name,'category'=>$category,'design'=>audienceDesign(['schema'=>1,'rows'=>[['name'=>'Header','columns'=>[[$block('heading',$headline,['align'=>'center']),$block('text','Hello *|FNAME|*, make this message your own.')]]],['name'=>'Featured content','columns'=>$features]]])];
    }
    $banners=[
        'newsletter'=>['neve-tzedek-market','People browsing an outdoor market in Neve Tzedek'],
        'welcome'=>['jaffa-steps','A visitor on the stone steps of Old Jaffa'],
        'event'=>['tel-aviv-market','People browsing the colorful Nachalat Binyamin market'],
        'announcement'=>['haifa-harbor','A panoramic view of Haifa and its harbor'],
        'thanks'=>['negev-sunset','Sunset over Ein Yorkeam in the Negev Desert'],
        'news-roundup'=>['jerusalem-street','People walking through the Old City of Jerusalem'],
        'monthly-digest'=>['negev-landscape','Layered desert mountains in the Negev'],
        'editorial'=>['safed-gate','A green decorative gate in Safed'],
        'save-the-date'=>['tel-aviv-beach','People enjoying the beach in Tel Aviv'],
        'event-agenda'=>['ein-gedi-hikers','Hikers exploring the cliffs and trails of Ein Gedi'],
        'new-chapter'=>['eilat-coast','The blue shoreline and coastal buildings of Eilat'],
        'important-update'=>['galilee-boat','The Sea of Galilee and distant hills viewed from a boat'],
        'gratitude'=>['ein-gedi-waterfall','A waterfall flowing through the rocky oasis of Ein Gedi'],
        'getting-started'=>['galilee-palm','Palm fronds framing fields and the Sea of Galilee'],
    ];
    foreach($starters as$key=>&$starter){
        if($key==='blank')continue;
        [$banner,$alt]=$banners[$key];
        $bytes=file_get_contents(__DIR__.'/assets/template-art/'.$banner.'.jpg');
        if($bytes===false)throw new RuntimeException('A bundled template banner is unavailable.');
        $design=$starter['design'];
        array_unshift($design['rows'],['id'=>'starter-banner-'.$key,'name'=>'Banner','columns'=>[[$block('image','',['id'=>'starter-banner-image-'.$key,'image'=>'data:image/jpeg;base64,'.base64_encode($bytes),'alt'=>$alt,'padding'=>0])]]]);
        $starter['design']=audienceDesign($design);
    }unset($starter);
    return $starters;
}
/** Render table-based email HTML, escaping every authored value. Footer is always retained. */
function audienceDesignHtml(array $design,?array $context=null): string { return audienceDesignRender($design,$context); }
/** Preview CSS is nonce-authorized; the app keeps style-src-attr 'none'. */
function audienceDesignPreviewHtml(array $design,string $nonce,?array $context=null,bool $dark=false,array $identity=[]):string
{
    $document=audienceDesign($design);$html=audiencePermissionReminderHtml(audienceDesignHtml($document,$context),$context??$identity);$preview=audienceDesignPreviewSource($html,$nonce,$dark);
    // Account identity can resolve without treating an unselected recipient as
    // an empty recipient and hiding their conditional content.
    if($context===null&&$identity){$tags=[];foreach(['LIST:COMPANY','LIST:ADDRESS','LIST:ADDRESSLINE','LIST:NAME','CURRENT_YEAR','MC:SUBJECT','MC_PREVIEW_TEXT']as$key)if(array_key_exists($key,$identity))$tags['*|'.$key.'|*']=htmlspecialchars((string)$identity[$key],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$preview=strtr($preview,$tags);}
    if($dark){$logoRules='';foreach($document['rows']as$row)if($row['name']==='Brand logo'||str_starts_with($row['id'],'brand-row-')){$background=$row['styles']['background']??$document['regions'][$row['region']]['background']??$document['styles']['body'];$logoRules.='.'. $row['id'].',.'. $row['id'].' .email-block{background-color:'.$background.'!important}';}if($logoRules!=='')$preview=str_replace('</head>','<style nonce="'.$nonce.'">'.$logoRules.'</style></head>',$preview);}
    return $preview;
}
function audienceDesignPreviewSource(string $html,string $nonce,bool $dark=false):string
{
    if(!preg_match('~^[a-zA-Z0-9+/=]{8,100}$~D',$nonce))throw new InvalidArgumentException('Reload the designer preview.');
    $rules=[];if($dark)$html=preg_replace_callback('~<style>(.*?)</style>~s',static fn($m)=>'<style>'.audienceDesignDarkCss($m[1]).'</style>',$html);
    $html=preg_replace_callback('/ style="([^"]*)"/',static function($m)use(&$rules,$dark){if($dark)$m[1]=audienceDesignDarkCss($m[1]);$class='email-style-'.count($rules);$rules[]='[data-email-style="'.$class.'"]{'.$m[1].'}';return ' data-email-style="'.$class.'"';},$html);
    $html=str_replace('<style>','<style nonce="'.$nonce.'">',$html);
    return str_replace('</head>','<style nonce="'.$nonce.'">'.implode('',$rules).'</style></head>',$html);
}

/** Inert card preview for a sent campaign; never modify its saved content. */
function audienceCampaignRecentPreviewHtml(array $campaign,string $nonce,array $identity=[]):string
{
    if($campaign['status']!=='sent'||!empty($campaign['deleted_at']))throw new InvalidArgumentException('Only sent emails appear in recent emails.');
    $draft=$campaign['draft'];
    $design=$draft['design']??audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[['type'=>'text','html'=>'<p>'.nl2br(htmlspecialchars(mb_substr((string)($draft['text']??'No content available.'),0,5000),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>']]]]]]);
    $html=audienceDesignPreviewHtml($design,$nonce,null,false,$identity);
    // White card canvas; authored content colors stay intact.
    return str_replace('</head>','<style nonce="'.$nonce.'">html,body{background:#fff!important}</style></head>',$html);
}
trait AudienceDesigns
{
    public function emailTemplate(int $id,bool $lock=false,bool $includeArchived=false):array
    {
        $row=$this->one('SELECT * FROM marketing_email_templates WHERE audience_id=? AND id=?'.($includeArchived?'':' AND deleted_at IS NULL').($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
        if(!$row)throw new InvalidArgumentException('This template is unavailable.');$row['design']=audienceJson($row['design_json']);return $row;
    }
    public function saveEmailDesign(string $kind,int $id,int $version,array $design,int $variant=0):int
    {
        $design=audienceDesign($design);$this->validateDesignConditions($design);
        return $this->transaction(function()use($kind,$id,$version,$design,$variant){
            $row=$kind==='template'?$this->emailTemplate($id,true):$this->campaign($id,true);
            if((int)$row['version']!==$version)throw new InvalidArgumentException('This design changed in another tab. Your edits remain here; reload before saving.');
            $before=$kind==='template'?$row['design']:$this->campaignDesign($row,$variant);if($kind==='template'&&$variant!==0)throw new InvalidArgumentException('Templates do not have campaign variations.');if($before)$this->recordDesignRevision($kind,$id,$version,$before,$variant);
            if($kind==='template')$this->write('UPDATE marketing_email_templates SET design_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[json_encode($design,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$id]);
            else{
                if($row['status']!=='draft'||$row['campaign_type']!=='regular')throw new InvalidArgumentException('Only regular email drafts can use the visual designer.');
                $draft=$row['draft'];if($variant>0)$draft['design_variants'][$variant]=$design;else $draft['design']=$design;
                $this->write('UPDATE marketing_campaigns SET draft_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[json_encode($draft,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$id]);
            }$this->recordDesignRevision($kind,$id,$version+1,$design,$variant);return $version+1;
        });
    }
    public function createEmailTemplate(string $name,array $design,string $folder=''):int
    {
        $name=trim($name);$folder=trim($folder);if($name===''||mb_strlen($name)>100||mb_strlen($folder)>100)throw new InvalidArgumentException('Enter a template name and folder of up to 100 characters.');
        $design=audienceDesign($design);$this->validateDesignConditions($design);
        return $this->transaction(function()use($name,$folder,$design){
            // Folder membership and folder removal share the audience lock.
            $context=$this->contentSettings(true);
            if($folder!==''){
                $folders=$this->emailTemplateFolders($context['settings'],true);
                if(!in_array($folder,$folders,true)){
                    if(strtolower($folder)==='unfiled'||count($folders)>=100)throw new InvalidArgumentException('Choose a folder name other than Unfiled; up to 100 folders are supported.');
                    $folders[]=$folder;sort($folders);
                }
                if(($context['settings']['template_folders']??[])!==$folders){
                    $settings=$context['settings'];$settings['template_folders']=$folders;
                    $this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);
                }
            }
            $this->write('INSERT INTO marketing_email_templates(audience_id,name,folder,design_json,actor_label) VALUES (?,?,?,?,?)',[$this->audienceId,$name,$folder,json_encode($design,JSON_THROW_ON_ERROR),$this->actorLabel]);return (int)$this->db->insert_id;
        });
    }
    public function emailTemplateFolders(array $settings,bool $lock=false):array
    {
        // Include folders assigned before explicit folder management existed,
        // including folders whose only remaining templates are archived.
        $assigned=$this->all("SELECT folder FROM marketing_email_templates WHERE audience_id=? AND folder<>''".($lock?' FOR UPDATE':''),[$this->audienceId]);
        $folders=array_values(array_unique([...($settings['template_folders']??[]),...array_column($assigned,'folder')]));sort($folders);return $folders;
    }
    public function emailTemplateFolder(string $operation,string $name,string $newName,int $version):void
    {
        $name=audienceText(['name'=>$name],'name',100);$newName=audienceText(['name'=>$newName],'name',100);
        if($name===''||!in_array($operation,['create','rename','delete'],true)||($operation==='rename'&&$newName===''))throw new InvalidArgumentException('Choose a folder and valid name.');
        $this->transaction(function()use($operation,$name,$newName,$version){
            $context=$this->contentSettings(true);
            if($context['version']!==$version)throw new InvalidArgumentException('Template folders changed. Reload before continuing.');
            $settings=$context['settings'];$folders=$this->emailTemplateFolders($settings,true);
            if(strtolower($operation==='rename'?$newName:$name)==='unfiled')throw new InvalidArgumentException('Unfiled is reserved for templates without a folder.');
            if($operation==='create'){
                if(in_array($name,$folders,true)||count($folders)>=100)throw new InvalidArgumentException('Choose a unique folder name; up to 100 folders are supported.');$folders[]=$name;
            }else{
                if(!in_array($name,$folders,true))throw new InvalidArgumentException('This folder is unavailable.');
                if($operation==='rename'&&in_array($newName,$folders,true))throw new InvalidArgumentException('That folder already exists.');
                if($operation==='delete'&&$this->one('SELECT id FROM marketing_email_templates WHERE audience_id=? AND BINARY folder=? LIMIT 1 FOR UPDATE',[$this->audienceId,$name]))throw new InvalidArgumentException('This folder contains templates, including any archived templates. Move its templates out before deleting the folder.');
                $folders=array_values(array_filter($folders,static fn($folder)=>$folder!==$name));
                if($operation==='rename'){
                    $folders[]=$newName;
                    $this->write('UPDATE marketing_email_templates SET folder=?,actor_label=?,version=version+1 WHERE audience_id=? AND BINARY folder=?',[$newName,$this->actorLabel,$this->audienceId,$name]);
                }
            }
            sort($folders);$settings['template_folders']=$folders;
            $this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);
        });
    }
    public function moveEmailTemplates(array $ids,array $versions,string $folder):void
    {
        $ids=audienceIds($ids);sort($ids);$folder=audienceText(['folder'=>$folder],'folder',100);
        if(!$ids||count($ids)>5000)throw new InvalidArgumentException('Choose up to 5,000 saved templates to move.');
        $this->transaction(function()use($ids,$versions,$folder){
            $context=$this->contentSettings(true);$folders=$this->emailTemplateFolders($context['settings'],true);
            if($folder!==''&&!in_array($folder,$folders,true))throw new InvalidArgumentException('Choose an existing folder or Unfiled.');
            foreach($ids as$id){$row=$this->emailTemplate($id,true,true);
                if((int)$row['version']!==(\Dnr\Http\RequestInput::positiveInt($versions,(string)$id)??0))throw new InvalidArgumentException('This template changed. Reload before continuing.');
                if($row['deleted_at']!==null)throw new InvalidArgumentException('Restore this archived template before using or changing it.');
            }
            // Keep legacy folders after their last member is moved out.
            if(($context['settings']['template_folders']??[])!==$folders){
                $context['settings']['template_folders']=$folders;
                $this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($context['settings'],JSON_THROW_ON_ERROR),$this->audienceId]);
            }
            foreach($ids as$id)$this->write('UPDATE marketing_email_templates SET folder=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$folder,$this->actorLabel,$this->audienceId,$id]);
        });
    }
    public function emailTemplateOperation(string $operation,int $id,int $version,array $input):int
    {
        if($operation==='folder'){$this->moveEmailTemplates([$id],[$id=>$version],audienceText($input,'folder',100));return $id;}
        return $this->transaction(function()use($operation,$id,$version,$input){
            if(in_array($operation,['replicate','delete'],true))$context=$this->contentSettings(true);
            if($operation==='delete'){
                $folders=$this->emailTemplateFolders($context['settings'],true);
                if(($context['settings']['template_folders']??[])!==$folders){
                    $context['settings']['template_folders']=$folders;
                    $this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($context['settings'],JSON_THROW_ON_ERROR),$this->audienceId]);
                }
            }
            $row=$this->emailTemplate($id,true,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This template changed. Reload before continuing.');
            $archived=$row['deleted_at']!==null;
            if($archived&&!in_array($operation,['restore','delete'],true))throw new InvalidArgumentException('Restore this archived template before using or changing it.');
            if(!$archived&&in_array($operation,['restore','delete'],true))throw new InvalidArgumentException('Only archived templates can be restored or permanently deleted.');
            if($operation==='replicate')return $this->createEmailTemplate(mb_substr($row['name'],0,90).' (copy)',$row['design'],$row['folder']);
            if($operation==='campaign'){$cid=$this->createCampaign(['name'=>audienceText($input,'name',100)?:$row['name'],'campaign_type'=>'regular']);$this->saveEmailDesign('campaign',$cid,1,$row['design']);return $cid;}
            if($operation==='delete'){
                $this->write("DELETE FROM marketing_design_comments WHERE audience_id=? AND owner_type='template' AND owner_id=?",[$this->audienceId,$id]);
                $this->write("DELETE FROM marketing_design_revisions WHERE audience_id=? AND owner_type='template' AND owner_id=?",[$this->audienceId,$id]);
                $this->write('DELETE FROM marketing_email_templates WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
            }elseif($operation==='archive'||$operation==='restore'){
                $this->write('UPDATE marketing_email_templates SET deleted_at='.($operation==='archive'?'UTC_TIMESTAMP(6)':'NULL').',actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$this->actorLabel,$this->audienceId,$id]);
            }
            elseif($operation==='rename'){$value=audienceText($input,'name',100);if($value==='')throw new InvalidArgumentException('Enter a template name.');$this->write('UPDATE marketing_email_templates SET name=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$value,$this->actorLabel,$this->audienceId,$id]);}
            else throw new InvalidArgumentException('Unknown template action.');return $id;
        });
    }
}
