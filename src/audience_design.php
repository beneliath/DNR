<?php
declare(strict_types=1);
require_once __DIR__.'/audience_design_advanced.php';

/** Keep authored rich text inert and portable across preview and email rendering. */
function audienceDesignRichText(string $html):string
{
    if(mb_strlen($html)>30000)throw new InvalidArgumentException('Keep each text block below 30,000 characters.');
    if($html==='')return '';
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $walk=static function(DOMNode $node)use(&$walk):string{
        if($node instanceof DOMText)return htmlspecialchars($node->textContent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if(!($node instanceof DOMElement))return '';
        $tag=strtolower($node->tagName);if(in_array($tag,['script','style','iframe','object','svg','math','form','input','img'],true))return '';
        $children='';foreach($node->childNodes as$child)$children.=$walk($child);
        if(!in_array($tag,['p','div','span','font','br','strong','b','em','i','u','s','ul','ol','li','a'],true))return $children;
        if($tag==='br')return '<br>';
        $attrs='';$id=$node->getAttribute('id');if(preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D',$id))$attrs=' id="'.$id.'"';if($tag==='a'){$href=$node->getAttribute('href');if(!audienceDesignUrl($href))return $children;$attrs.=' href="'.htmlspecialchars($href,ENT_QUOTES,'UTF-8').'"';}
        $style=$node->getAttribute('style');$declarations=[];foreach(explode(';',audienceDesignSafeCss($style,false))as$declaration){if(preg_match('/^(?:color|font-family|font-size|font-weight|font-style|text-decoration|letter-spacing):/',$declaration))$declarations[]=$declaration;}
        if($tag==='font'){$tag='span';$color=$node->getAttribute('color');if(preg_match('/^#[a-f0-9]{6}$/iD',$color))$declarations[]='color:'.$color;$face=$node->getAttribute('face');if(in_array($face,['Arial','Georgia','Verdana','Tahoma','Times New Roman'],true))$declarations[]='font-family:'.$face;$sizes=['1'=>10,'2'=>13,'3'=>16,'4'=>18,'5'=>24,'6'=>32,'7'=>48];if(isset($sizes[$node->getAttribute('size')]))$declarations[]='font-size:'.$sizes[$node->getAttribute('size')].'px';}
        if($declarations)$attrs.=' style="'.htmlspecialchars(implode(';',$declarations),ENT_QUOTES,'UTF-8').'"';
        return '<'.$tag.$attrs.'>'.$children.'</'.$tag.'>';
    };
    $out='';$body=$doc->getElementsByTagName('body')->item(0);if($body)foreach($body->childNodes as$child)$out.=$walk($child);return $out;
}
/** Versioned, allowlisted email document. No executable HTML or external resource fetching. */
function audienceDesign(array $input): array { return audienceDesignDocument($input); }
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
        'service-introduction'=>['Meet our services','Sell services',2,'Find the right support for you.'],
        'consultation'=>['Let’s talk','Sell services',1,'Start a conversation with our team.'],
        'product-spotlight'=>['Product spotlight','Sell products',1,'Discover something new.'],
        'collection'=>['Featured collection','Sell products',3,'A few things we think you will love.'],
        'account-notice'=>['Account notice','Transactional',1,'Your account update.'],
    ];
    foreach($layouts as$key=>[$name,$category,$columns,$headline]){
        $features=[];for($i=1;$i<=$columns;$i++)$features[]=[ $block('image',''),$block('heading','Highlight '.$i,['headingLevel'=>2]),$block('text','Share a useful detail, a new idea, or a story that matters to your readers.'),$block('button','Explore more',['url'=>'https://example.com']) ];
        $starters[$key]=['name'=>$name,'category'=>$category,'design'=>audienceDesign(['schema'=>1,'rows'=>[['name'=>'Header','columns'=>[[$block('heading',$headline,['align'=>'center']),$block('text','Hello *|FNAME|*, make this message your own.')]]],['name'=>'Featured content','columns'=>$features]]])];
    }
    return $starters;
}
/** Render table-based email HTML, escaping every authored value. Footer is always retained. */
function audienceDesignHtml(array $design,?array $context=null): string { return audienceDesignRender($design,$context); }
/** Preview CSS is nonce-authorized; the app keeps style-src-attr 'none'. */
function audienceDesignPreviewHtml(array $design,string $nonce,?array $context=null):string
{
    return audienceDesignPreviewSource(audienceDesignHtml($design,$context),$nonce);
}
function audienceDesignPreviewSource(string $html,string $nonce):string
{
    if(!preg_match('~^[a-zA-Z0-9+/=]{8,100}$~D',$nonce))throw new InvalidArgumentException('Reload the designer preview.');
    $rules=[];
    $html=preg_replace_callback('/ style="([^"]*)"/',static function($m)use(&$rules){$class='email-style-'.count($rules);$rules[]='[data-email-style="'.$class.'"]{'.$m[1].'}';return ' data-email-style="'.$class.'"';},$html);
    $html=str_replace('<style>','<style nonce="'.$nonce.'">',$html);
    return str_replace('</head>','<style nonce="'.$nonce.'">'.implode('',$rules).'</style></head>',$html);
}
trait AudienceDesigns
{
    public function emailTemplate(int $id,bool $lock=false):array
    {
        $row=$this->one('SELECT * FROM marketing_email_templates WHERE audience_id=? AND id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
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
        $design=audienceDesign($design);$this->validateDesignConditions($design);$this->write('INSERT INTO marketing_email_templates(audience_id,name,folder,design_json,actor_label) VALUES (?,?,?,?,?)',[$this->audienceId,$name,$folder,json_encode($design,JSON_THROW_ON_ERROR),$this->actorLabel]);return (int)$this->db->insert_id;
    }
    public function emailTemplateOperation(string $operation,int $id,int $version,array $input):int
    {
        return $this->transaction(function()use($operation,$id,$version,$input){
            $row=$this->emailTemplate($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This template changed. Reload before continuing.');
            if($operation==='replicate')return $this->createEmailTemplate(mb_substr($row['name'],0,90).' (copy)',$row['design'],$row['folder']);
            if($operation==='campaign'){$cid=$this->createCampaign(['name'=>audienceText($input,'name',100)?:$row['name'],'campaign_type'=>'regular']);$this->saveEmailDesign('campaign',$cid,1,$row['design']);return $cid;}
            if($operation==='delete')$this->write('UPDATE marketing_email_templates SET deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
            elseif($operation==='rename'||$operation==='folder'){$key=$operation==='rename'?'name':'folder';$value=audienceText($input,$key,100);if($key==='name'&&$value==='')throw new InvalidArgumentException('Enter a template name.');$this->write("UPDATE marketing_email_templates SET $key=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?",[$value,$this->actorLabel,$this->audienceId,$id]);}
            else throw new InvalidArgumentException('Unknown template action.');return $id;
        });
    }
}
