<?php
declare(strict_types=1);

final class AudienceCollectionOriginException extends InvalidArgumentException {}

/** Public address inputs use the organization's country/region values and existing audience storage. */
function audienceCollectionFieldValue(array $field,mixed $value):string|array
{
    if($field['field_type']==='phone')$value=audienceCollectionPhoneValue($value,$field['label']);
    if($field['field_type']==='address'&&(is_array($value)||$field['required'])){
        if(!is_array($value))$value=audienceAddressParts($value);
        $parts=[];
        foreach(['address1','address2','city','region','postal_code','country']as$key)$parts[$key]=audienceText($value,$key,300);
        if($field['required'])foreach(['address1'=>'Address Line 1','city'=>'City','region'=>'State / Province','postal_code'=>'Postal Code','country'=>'Country']as$key=>$label){
            if($parts[$key]==='')throw new InvalidArgumentException($field['label'].': '.$label.' is required.');
        }
        // The country picker has a default; an otherwise empty optional address stays empty.
        if(implode('',array_diff_key($parts,['country'=>true]))==='')return audienceFieldValue($field,'');
        $country=normalizeAddressCountryCode($parts['country']?:applicationDefaultCountry());
        if($country===null||!isset(addressCountryChoices()[$country]))throw new InvalidArgumentException($field['label'].': choose a valid country.');
        $region=normalizeAddressRegion($country,$parts['region']);
        if($region===null)throw new InvalidArgumentException($field['label'].': choose a valid state or province.');
        $value=array_replace($parts,['country'=>$country,'region'=>$region]);
    }
    return audienceFieldValue($field,$value);
}

/** Structured public controls use MOED normalization; legacy scalar values remain readable. */
function audienceCollectionPhoneValue(mixed $value,string $label='Phone Number'):string
{
    if(!is_array($value))return audienceCollectionText($value,80);
    return normalizePhoneNumber(audienceText($value,'country_code',10),audienceText($value,'number',80),$label);
}

/** Fill missing profile details after ownership proof, preserving information saved in the meantime. */
function audienceCollectionProfileAdditions(array $person,array $submitted,array $fields):array
{
    $result=['first_name'=>$person['first_name'],'last_name'=>$person['last_name'],'custom'=>audienceJson($person['custom_json']),'changed_fields'=>[]];
    $values=is_array($submitted['custom']??null)?$submitted['custom']:[];$types=is_array($submitted['custom_types']??null)?$submitted['custom_types']:[];
    $blank=static fn($value)=>$value===null||$value===[]||(is_string($value)&&trim($value)==='');
    foreach(['first_name','last_name']as$key){
        $value=audienceText($submitted,$key,100);
        if($blank($result[$key])&&$value!==''){$result[$key]=$value;$result['changed_fields'][]=$key;}
    }
    foreach($fields as$field){
        $id=$field['id'];
        if(!$field['is_public']||!array_key_exists($id,$values)||($types[$id]??null)!==$field['field_type'])continue;
        $field['required']=false;
        try{$value=audienceCollectionFieldValue($field,$values[$id]);}catch(InvalidArgumentException){continue;}
        if($blank($value))continue;
        $current=$result['custom'][$id]??'';$merged=$current;
        if($field['field_type']==='address'&&is_string($current)&&substr_count($current,"\n")<=5){
            $existing=audienceAddressParts($current);$incoming=audienceAddressParts($value);$conflict=false;
            $country=normalizeAddressCountryCode($existing['country']?:$incoming['country']);
            foreach($existing as$key=>$part)if(!$blank($part)&&!$blank($incoming[$key])){
                $old=$part;$new=$incoming[$key];
                if($key==='country'){$old=normalizeAddressCountryCode($old)??$old;$new=normalizeAddressCountryCode($new)??$new;}
                if($key==='region'&&$country){$old=normalizeAddressRegion($country,$old)??$old;$new=normalizeAddressRegion($country,$new)??$new;}
                if(mb_strtolower(trim($old))!==mb_strtolower(trim($new))){$conflict=true;break;}
            }
            if($conflict)continue;
            foreach($existing as$key=>$part)if($blank($part)&&!$blank($incoming[$key]))$existing[$key]=$incoming[$key];
            try{$merged=audienceFieldValue($field,$existing);}catch(InvalidArgumentException){continue;}
        }elseif($field['field_type']==='checkboxes'){
            $merged=array_values(array_unique([...(is_array($current)?$current:audienceParseListCell((string)$current)),...$value]));
        }elseif($blank($current))$merged=$value;
        if($merged!==$current){$result['custom'][$id]=$merged;$result['changed_fields'][]='custom:'.$id;}
    }
    return $result;
}
require_once __DIR__.'/audience_collection_customization.php';
require_once __DIR__.'/audience_collection_embed.php';

const AUDIENCE_COLLECTION_KINDS = ['hosted','embedded','popup','landing','survey','preferences'];
const AUDIENCE_COLLECTION_QUESTIONS = ['radio','checkboxes','range','text','dropdown','email','contact','content'];
const AUDIENCE_SURVEY_STARTERS = [
    'event-scale'=>['Satisfaction','Post-event (1-10 scale) survey','Post-event satisfaction survey on a 1–10 scale. Includes an intro and 2 questions.'],
    'growth'=>['Growth strategy','Growth opportunities','A five-question survey to engage your audience and identify opportunities for growth.'],
    'channels'=>['Effectiveness','Where did you hear about us?','A simple one-question survey to learn which channels reach your audience.'],
    'event-emojis'=>['Satisfaction','Post-event emojis survey','Post-event satisfaction using emojis. Includes an intro and 2 questions.'],
];

function audienceCollectionStarter(string $kind,string $starter,string $name): array
{
    $d=audienceCollectionDefaults($kind,$name);
    if($kind==='survey'&&isset(AUDIENCE_SURVEY_STARTERS[$starter])){
        $q=static fn($id,$type,$label,$options=[])=>['id'=>$id,'type'=>$type,'label'=>$label,'required'=>true,'options'=>$options];
        if($starter==='channels')$d['questions']=[$q('q1','radio','Where did you hear about us?',['Our website','Social media','A friend','An event','Other'])];
        elseif($starter==='growth')$d['questions']=[$q('q1','text','What do you value most about our work?'),$q('q2','text','What would you like to see more of?'),$q('q3','text','What could we improve?'),$q('q4','range','How likely are you to recommend us?'),$q('q5','text','Is there anything else you would like to share?')];
        else{$label=str_starts_with($starter,'event')?'How was your experience at our event?':'How was your experience?';$d['questions']=[$q('q1',str_ends_with($starter,'emojis')?'radio':'range',$label,str_ends_with($starter,'emojis')?['😃 Great','🙂 Good','😐 Neutral','🙁 Could be better']:[]),$q('q2','text','What would you like us to know?')];$d['questions'][1]['required']=false;}
    }elseif($kind==='landing'){
        if(!in_array($starter,['blank','grow','announcement'],true))$starter='grow';
        if($starter==='grow'){$d['title']='Join our community';$d['sections']=[['type'=>'heading','text'=>'Be the first to know','url'=>''],['type'=>'text','text'=>'Get our latest stories and updates delivered to your inbox.','url'=>'']];}
        if($starter==='announcement'){$d['title']=$name;$d['sections']=[['type'=>'heading','text'=>'Something new is coming','url'=>''],['type'=>'text','text'=>'Sign up for the latest news and announcements.','url'=>'']];}
    }
    return $d;
}

function audienceCollectionDefaults(string $kind, string $name = ''): array
{
    if (!in_array($kind, AUDIENCE_COLLECTION_KINDS, true)) throw new InvalidArgumentException('Choose a supported form type.');
    return [
        'title'=>$kind==='embedded'?'Subscribe':($name ?: ($kind==='survey'?'We’d love your feedback':'Stay in the know')),
        'intro'=>$kind==='embedded'?'':($kind==='survey'?'Tell us what you think.':'Sign up to receive our latest news and updates.'),
        'button'=>$kind==='survey'?'Submit':'Subscribe',
        'thanks'=>'Thank you! Your response has been received.',
        'confirmation'=>'Check your email and confirm your subscription to finish signing up.',
        'permission'=>'Yes, I would like to receive email newsletters. I can unsubscribe at any time.',
        'language'=>'en', 'close_at'=>'', 'opt_in'=>'double', 'fields'=>$kind==='embedded'?['email']:['email','first_name','last_name'],
        'embedded'=>audienceCollectionEmbedDefaults(), 'help_text'=>[],
        'messages'=>audienceCollectionMessages(), 'show_footer'=>true, 'advanced'=>['enabled'=>false,'pages'=>['signup'=>'','thanks'=>'','confirm'=>'','unsubscribe'=>'']],
        'required'=>['email'], 'group_ids'=>[], 'tag_ids'=>[], 'permissions'=>[],
        'color'=>$kind==='embedded'?'#111111':'#007c89', 'background'=>$kind==='embedded'?'#ffffff':'#f6f6f4', 'text_color'=>'#241c15',
        'font'=>'Arial', 'web_font'=>'', 'web_font_name'=>'', 'width'=>$kind==='embedded'?600:640, 'logo'=>'', 'logo_width'=>240, 'logo_resized'=>false, 'description'=>'', 'favicon'=>'',
        'labels'=>['email'=>'Email address','first_name'=>'First name','last_name'=>'Last name','phone'=>'Phone number'],
        'system'=>['unsubscribe_title'=>'Unsubscribe from emails?','unsubscribe_intro'=>'You can stop emails from this audience at any time.','unsubscribe_button'=>'Unsubscribe','unsubscribe_done'=>'You will no longer receive emails from this audience.','confirm_title'=>'Confirm your subscription','confirm_intro'=>'Confirm that you want to receive emails from *|LIST:COMPANY|*.','confirm_button'=>'Yes, subscribe me','confirm_done'=>'Your email subscription is confirmed.'],
        'sections'=>[], 'questions'=>$kind==='survey'?[['id'=>'q1','type'=>'radio','label'=>'How was your experience?','required'=>true,'options'=>['Great','Good','Could be better']]]:[],
        'origins'=>[], 'popup'=>['delay'=>5,'trigger'=>'delay','frequency'=>7,'device'=>'all','paths'=>[],'exclude_paths'=>[],'countries'=>[]],
        'variants'=>[], 'quiz'=>[],
    ];
}

function audienceCollectionBrandFontDefaults(array $document,array $brand):array
{
    $font=$brand['styles']['font']??'';
    $document['font']=in_array($font,['Arial','Georgia','Verdana','Tahoma','Times New Roman'],true)?$font:'Arial';
    $document['web_font']=audienceDesignWebFont($brand['styles']['webFont']??'');
    $document['web_font_name']=$document['web_font']!==''?audienceBrandFont($brand)['name']:'';
    return $document;
}

function audienceCollectionEditorFontDefaults(array $document,array $brand):array
{
    return $document['font']==='Arial'&&$document['web_font']===''
        ?audienceCollectionBrandFontDefaults($document,$brand):$document;
}

function audienceCollectionText(mixed $value, int $limit): string
{
    if (!is_string($value) || mb_strlen($value)>$limit || str_contains($value,"\0")) throw new InvalidArgumentException('A form value is invalid or too long.');
    return trim($value);
}

/** These audience record fields are not offered as form editor choices. */
function audienceCollectionEditorFields(array $fields): array
{
    return array_values(array_filter($fields,static fn($field)=>!in_array(mb_strtolower(trim($field['label'])),['full name','date of entry','fund'],true)));
}

function audienceCollectionUrl(mixed $value, bool $image = false): string
{
    $url=audienceCollectionText($value, $image?2700000:2000);
    if ($url==='') return '';
    if ($image && str_starts_with($url,'data:')) return audienceDesignImage($url,2000000,true);
    if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null) throw new InvalidArgumentException('Use a full HTTPS address.');
    return $url;
}

/** Only allow declared native fields and typed, escaped content in public pages. */
function audienceCollectionDocument(array $input, string $kind, array $fields, array $groups, array $tags): array
{
    $d=audienceCollectionDefaults($kind);
    foreach (['title'=>200,'intro'=>5000,'button'=>80,'thanks'=>2000,'confirmation'=>2000,'permission'=>1000,'description'=>500,'language'=>12] as $key=>$limit) {
        $d[$key]=audienceCollectionText($input[$key]??$d[$key],$limit);
    }
    if ($d['button']==='' || $d['thanks']==='' || $d['permission']==='') throw new InvalidArgumentException('Add a button label, permission text and thank-you message.');
    if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$d['language'])) throw new InvalidArgumentException('Choose a valid language code.');
    $d['close_at']=audienceCollectionText($input['close_at']??'',16);
    if($d['close_at']!==''){$close=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$d['close_at'],new DateTimeZone('UTC'));if(!$close||$close->format('Y-m-d\TH:i')!==$d['close_at'])throw new InvalidArgumentException('Choose a valid closing date and time.');}
    foreach (['color','background','text_color'] as $key) {
        $d[$key]=audienceCollectionText($input[$key]??$d[$key],7);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/D',$d[$key])) throw new InvalidArgumentException('Choose a six-digit color.');
    }
    $d['font']=in_array($input['font']??'', ['Arial','Georgia','Verdana','Tahoma','Times New Roman'],true)?$input['font']:'Arial';
    $d['web_font']=audienceDesignWebFont($input['web_font']??'');
    $d['web_font_name']=$d['web_font']!==''?audienceCollectionText($input['web_font_name']??'',255):'';
    $width=$input['width']??$d['width'];
    $d['width']=$kind==='embedded'&&($width===''||$width===0)?0:max(320,min(1200,(int)$width));
    $d['embedded']=audienceCollectionEmbedOptions($input['embedded']??[]);
    foreach (['logo','favicon'] as $key) $d[$key]=audienceCollectionUrl($input[$key]??'',true);
    $d['logo_width']=audienceDesignNumber($input['logo_width']??240,40,1200);
    $d['logo_resized']=!empty($input['logo_resized']);
    $d['opt_in']=in_array($input['opt_in']??'', ['single','double'],true)?$input['opt_in']:'double';
    $available=['email','first_name','last_name']; $must=['email'];
    foreach ($fields as $f) if ($f['is_public']) { $key='custom:'.$f['id']; $available[]=$key; if ($f['required']) $must[]=$key; }
    foreach (['fields','required'] as $key) {
        if (!is_array($input[$key]??null)) throw new InvalidArgumentException('Choose form fields.');
        $d[$key]=array_values(array_unique(array_map(static fn($v)=>audienceCollectionText($v,40),$input[$key])));
        if (array_diff($d[$key],$available)) throw new InvalidArgumentException('A selected field is private or unavailable.');
    }
    $d['required']=array_values(array_unique([...$d['required'],...$must]));
    $d['fields']=array_values(array_unique([...$d['fields'],...$d['required']]));
    if (!is_array($input['labels']??[])) throw new InvalidArgumentException('Invalid field labels.');
    foreach ($input['labels']??[] as $key=>$label) {
        if (!in_array($key,[...$available,'phone'],true)) throw new InvalidArgumentException('A field label is unavailable.');
        $d['labels'][$key]=audienceCollectionText($label,200);
        if ($d['labels'][$key]==='') throw new InvalidArgumentException('Field labels cannot be empty.');
    }
    if (!is_array($input['help_text']??[])) throw new InvalidArgumentException('Invalid field help text.');
    foreach ($input['help_text']??[] as $key=>$help) {
        if (!in_array($key,$available,true)) throw new InvalidArgumentException('Help text belongs to an unavailable field.');
        $d['help_text'][$key]=audienceCollectionText($help,1000);
    }
    if (!is_array($input['system']??[])) throw new InvalidArgumentException('Invalid system messages.');
    foreach ($d['system'] as $key=>$value) $d['system'][$key]=audienceCollectionText($input['system'][$key]??$value,1000);
    if(array_key_exists('show_footer',$input)&&!is_bool($input['show_footer']))throw new InvalidArgumentException('Choose whether to show the footer credit.');
    $d['show_footer']=$input['show_footer']??true;
    if(!is_array($input['messages']??[]))throw new InvalidArgumentException('Invalid form translations.');
    foreach($d['messages']as$key=>$value){
        $d['messages'][$key]=audienceCollectionText($input['messages'][$key]??$value,1000);
        if($d['messages'][$key]===''&&($key!=='powered'||$d['show_footer']))throw new InvalidArgumentException($key==='powered'?'Add footer credit text or hide the footer credit.':'Translated messages cannot be empty.');
    }
    $advanced=$input['advanced']??[];if(!is_array($advanced)||!is_array($advanced['pages']??[]))throw new InvalidArgumentException('Invalid custom page source.');
    $d['advanced']['enabled']=!empty($advanced['enabled']);
    foreach($d['advanced']['pages']as$key=>$value)$d['advanced']['pages'][$key]=audienceCollectionAdvancedHtml(audienceCollectionText($advanced['pages'][$key]??'',60000));
    $publicGroups=array_map('intval',array_column(array_filter($groups,static fn($g)=>$g['display_type']!=='hidden'),'id'));
    foreach (['group_ids'=>$publicGroups,'tag_ids'=>array_map('intval',array_column($tags,'id'))] as $key=>$allowed) {
        $d[$key]=audienceIds($input[$key]??[]);
        if (array_diff($d[$key],$allowed)) throw new InvalidArgumentException('A selected tag or interest belongs to another audience or is unavailable.');
    }
    foreach (['permissions','origins'] as $key) {
        if (!is_array($input[$key]??[])) throw new InvalidArgumentException('Invalid form options.');
        foreach (array_slice($input[$key]??[],0,20) as $value) {
            $value=audienceCollectionText($value,$key==='origins'?300:200); if ($value==='') continue;
            if ($key==='origins' && !preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$~D',$value)) throw new AudienceCollectionOriginException('Enter website origins such as https://example.com, without a path.');
            $d[$key][]=$value;
        }
    }
    foreach (array_slice(is_array($input['sections']??null)?$input['sections']:[],0,30) as $s) {
        if (!is_array($s) || !in_array($s['type']??'', ['heading','text','image','button','divider'],true)) throw new InvalidArgumentException('Choose a supported section.');
        $section=['type'=>$s['type'],'text'=>audienceCollectionText($s['text']??'',5000),'url'=>audienceCollectionUrl($s['url']??'',$s['type']==='image')];
        if($s['type']==='image'&&isset($s['width'])&&$s['width']!=='')$section['width']=audienceDesignNumber($s['width'],40,1200);
        $d['sections'][]=$section;
    }
    $d['questions']=[]; $ids=[];
    foreach (array_slice(is_array($input['questions']??null)?$input['questions']:[],0,30) as $q) {
        if (!is_array($q) || !in_array($q['type']??'',AUDIENCE_COLLECTION_QUESTIONS,true)) throw new InvalidArgumentException('Choose a supported question type.');
        $id=audienceCollectionText($q['id']??'',40);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D',$id) || isset($ids[$id])) throw new InvalidArgumentException('Question identifiers must be unique.');
        $ids[$id]=true; $options=[];
        foreach (array_slice(is_array($q['options']??null)?$q['options']:[],0,30) as $o) { $o=audienceCollectionText($o,200); if ($o!=='') $options[]=$o; }
        $options=array_values(array_unique($options));
        if (in_array($q['type'],['radio','checkboxes','dropdown'],true) && count($options)<2) throw new InvalidArgumentException('Add at least two answer choices.');
        $label=audienceCollectionText($q['label']??'',1000); if ($label==='') throw new InvalidArgumentException('Add a label to each question.');
        $answerTags=[];
        foreach(is_array($q['answer_tags']??null)?$q['answer_tags']:[]as$answer=>$tagIds){
            if(!in_array((string)$answer,$options,true))throw new InvalidArgumentException('Choose an available answer for tagging.');
            $answerTags[$answer]=audienceIds($tagIds);
            if(array_diff($answerTags[$answer],array_map('intval',array_column($tags,'id'))))throw new InvalidArgumentException('Choose tags from this audience.');
        }
        if(in_array('__message_monkey_other__',$options,true))throw new InvalidArgumentException('Choose a different answer label.');
        $d['questions'][]=['id'=>$id,'type'=>$q['type'],'label'=>$label,'required'=>!empty($q['required']),'options'=>$options,'other'=>!empty($q['other'])&&in_array($q['type'],['radio','checkboxes','dropdown'],true),'answer_tags'=>$answerTags];
    }
    if ($kind==='survey' && !array_filter($d['questions'],static fn($q)=>$q['type']!=='content')) throw new InvalidArgumentException('Add at least one survey question.');
    $p=is_array($input['popup']??null)?$input['popup']:[];
    $d['popup']=['delay'=>max(0,min(120,(int)($p['delay']??5))),'trigger'=>in_array($p['trigger']??'', ['delay','exit','scroll'],true)?$p['trigger']:'delay','frequency'=>max(1,min(365,(int)($p['frequency']??7))),'device'=>in_array($p['device']??'', ['all','desktop','mobile'],true)?$p['device']:'all','paths'=>[],'exclude_paths'=>[],'countries'=>[]];
    foreach(array_slice(is_array($p['countries']??null)?$p['countries']:[],0,250)as$country){if(!is_string($country)||!preg_match('/^[A-Z]{2}$/D',$country)||in_array($country,['XX','ZZ'],true))throw new InvalidArgumentException('Choose valid country codes.');$d['popup']['countries'][]=$country;}
    foreach (['paths','exclude_paths'] as $key) foreach (array_slice(is_array($p[$key]??null)?$p[$key]:[],0,20) as $path) {
        $path=audienceCollectionText($path,300); if ($path!=='' && $path[0]!=='/') throw new InvalidArgumentException('Page paths must begin with /.'); if ($path!=='') $d['popup'][$key][]=$path;
    }
    if(!empty($input['variants']))throw new InvalidArgumentException('A/B testing is not available in MessageMonkey.');
    foreach (array_slice(is_array($input['quiz']??null)?$input['quiz']:[],0,10) as $q) {
        if (!is_array($q)) throw new InvalidArgumentException('Invalid quiz choice.');
        $d['quiz'][]=['label'=>audienceCollectionText($q['label']??'',200),'outcome'=>audienceCollectionText($q['outcome']??'',1000)];
    }
    if (strlen(json_encode($d,JSON_THROW_ON_ERROR))>3000000) throw new InvalidArgumentException('This form is too large. Use smaller images.');
    return $d;
}

trait AudienceCollection
{
    public function collectionAvailable(): bool
    {
        return (int)$this->one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_collection_resources'")['n']===1;
    }

    public function collectionResource(int $id, bool $lock=false, bool $includeRemoved=false): array
    {
        $r=$this->one('SELECT * FROM marketing_collection_resources WHERE audience_id=? AND id=?'.($includeRemoved?'':' AND deleted_at IS NULL').($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
        if (!$r) throw new InvalidArgumentException('This form or page is unavailable.');
        $r['draft']=audienceJson($r['draft_json']);
        return $r;
    }

    public function saveCollection(array $input, int $id=0, int $version=0): int
    {
        return $this->transaction(function() use($input,$id,$version) {
            $old=$id?$this->collectionResource($id,true):null;
            if ($old && (int)$old['version']!==$version) throw new InvalidArgumentException('This form changed. Reload before saving.');
            $kind=$old['kind']??audienceText($input,'kind',20); $name=audienceText($input,'name',100);
            if ($name==='') throw new InvalidArgumentException('Name this form or page.');
            $doc=is_array($input['document']??null)?$input['document']:audienceCollectionStarter($kind,audienceText($input,'starter',40),$name);
            if (!isset($input['document'])) {
                $settings=audienceJson($this->one('SELECT settings_json FROM marketing_audiences WHERE id=?',[$this->audienceId])['settings_json']);
                $doc=audienceCollectionBrandFontDefaults($doc,$settings['brand']??[]);
                foreach (['opt_in','permission','language'] as $key) if (isset($settings['collection'][$key])) $doc[$key]=$settings['collection'][$key];
                foreach ($this->groups() as $group) if ($group['display_type']!=='hidden') $doc['group_ids'][]=(int)$group['id'];
                if ($kind==='preferences') { $doc['title']='Update your preferences';$doc['intro']='Choose the information and interests you share with us.';$doc['button']='Save preferences';$doc['thanks']='Your preferences have been saved.'; }
            }
            $doc=audienceCollectionDocument($doc,$kind,$this->fields(),$this->groups(),$this->tags());
            if ($old) $this->write('UPDATE marketing_collection_resources SET name=?,draft_json=?,version=version+1 WHERE audience_id=? AND id=?',[$name,json_encode($doc,JSON_THROW_ON_ERROR),$this->audienceId,$id]);
            else {
                $this->write('INSERT INTO marketing_collection_resources(audience_id,kind,name,public_key,draft_json) VALUES (?,?,?,?,?)',[$this->audienceId,$kind,$name,bin2hex(random_bytes(32)),json_encode($doc,JSON_THROW_ON_ERROR)]);
                $id=(int)$this->db->insert_id;
            }
            return $id;
        });
    }

    public function collectionOperation(int $id, int $version, string $operation): int
    {
        return $this->transaction(function() use($id,$version,$operation) {
            $r=$this->collectionResource($id,true,in_array($operation,['restore','delete'],true));
            if ((int)$r['version']!==$version) throw new InvalidArgumentException('This form changed. Reload before continuing.');
            if(in_array($operation,['restore','delete'],true)&&!$r['deleted_at'])throw new InvalidArgumentException('Only archived forms can be restored or permanently deleted.');
            if ($operation==='replicate') return $this->saveCollection(['kind'=>$r['kind'],'name'=>mb_substr($r['name'],0,90).' (copy)','document'=>$r['draft']]);
            if($operation==='delete'){
                $this->write('DELETE FROM marketing_collection_responses WHERE audience_id=? AND resource_id=?',[$this->audienceId,$id]);
                $this->write('DELETE FROM marketing_collection_visits WHERE resource_id=?',[$id]);
                $this->write('DELETE FROM marketing_collection_revisions WHERE audience_id=? AND resource_id=?',[$this->audienceId,$id]);
                $this->write('DELETE FROM marketing_collection_resources WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
            } elseif ($operation==='restore') {
                $this->write("UPDATE marketing_collection_resources SET deleted_at=NULL,state='draft',published_revision=NULL,version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$id]);
            } elseif ($operation==='publish') {
                $doc=audienceCollectionDocument($r['draft'],$r['kind'],$this->fields(),$this->groups(),$this->tags());
                if (in_array($r['kind'],['embedded','popup'],true) && !$doc['origins']) throw new AudienceCollectionOriginException('Add the HTTPS website origin under Setup → Website origins before publishing this form.');
                $this->write('INSERT INTO marketing_collection_revisions(resource_id,audience_id,document_json) VALUES (?,?,?)',[$id,$this->audienceId,json_encode($doc,JSON_THROW_ON_ERROR)]);
                $revision=(int)$this->db->insert_id;
                $this->write("UPDATE marketing_collection_resources SET state='published',published_revision=?,version=version+1 WHERE audience_id=? AND id=?",[$revision,$this->audienceId,$id]);
            } elseif ($operation==='pause') $this->write("UPDATE marketing_collection_resources SET state='paused',version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$id]);
            elseif (in_array($operation,['archive','remove'],true)) $this->write("UPDATE marketing_collection_resources SET deleted_at=UTC_TIMESTAMP(),state='paused',version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$id]);
            else throw new InvalidArgumentException('Choose a supported form action.');
            return $id;
        });
    }

    public function collectionPublished(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) return null;
        $r=$this->one("SELECT r.*,v.document_json FROM marketing_collection_resources r JOIN marketing_collection_revisions v ON v.id=r.published_revision AND v.resource_id=r.id AND v.audience_id=r.audience_id WHERE r.audience_id=? AND r.public_key=? AND r.state='published' AND r.deleted_at IS NULL",[$this->audienceId,$key]);
        if ($r){$r['document']=audienceJson($r['document_json']);if(!empty($r['document']['close_at'])&&$r['document']['close_at']<=gmdate('Y-m-d\TH:i'))return null;}
        return $r;
    }

    public function collectionDefaults(int $version,array $input): void
    {
        $this->transaction(function() use($version,$input) {
            $a=$this->one('SELECT * FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);
            if ((int)$a['version']!==$version) throw new InvalidArgumentException('Audience settings changed. Reload before saving.');
            $settings=audienceJson($a['settings_json']);
            $opt=audienceText($input,'opt_in',10); if (!in_array($opt,['single','double'],true)) throw new InvalidArgumentException('Choose single or double opt-in.');
            $language=audienceText($input,'language',12); if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$language)) throw new InvalidArgumentException('Choose a valid language code.');
            $permission=audienceText($input,'permission',1000); if ($permission==='') throw new InvalidArgumentException('Add a permission statement.');
            $settings['collection']=['opt_in'=>$opt,'language'=>$language,'permission'=>$permission];
            $this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);
        });
    }

    public function tagCollectionResponse(int $resource,int $response,int $tag): void
    {
        $this->transaction(function() use($resource,$response,$tag) {
            $this->collectionResource($resource);
            $r=$this->one('SELECT contact_id FROM marketing_collection_responses WHERE audience_id=? AND resource_id=? AND id=?',[$this->audienceId,$resource,$response]);
            if (!$r || !$r['contact_id']) throw new InvalidArgumentException('Only responses from a private email link can be tagged to a connection.');
            $person=$this->person((int)$r['contact_id'],true);
            $this->mutatePerson((int)$person['id'],(int)$person['version'],'tag',['tag_id'=>$tag]);
            $this->event((int)$person['id'],'survey-response-tag',['resource_id'=>$resource,'response_id'=>$response,'tag_id'=>$tag]);
        });
    }

    public function collectionChallenge(array $r, string $visitor, string $variant='A', string $recipient=''): string
    {
        return \Dnr\Security\ApplicationKey::seal(json_encode(['account'=>currentAccountKey(),'audience'=>$this->audienceId,'resource'=>(int)$r['id'],'revision'=>(int)$r['published_revision'],'visitor'=>$visitor,'variant'=>$variant,'recipient'=>$recipient,'issued'=>time(),'nonce'=>bin2hex(random_bytes(24))],JSON_THROW_ON_ERROR));
    }

    /** The public response is deliberately identical for unknown and existing addresses. */
    public function submitCollection(array $r, array $input, string $ip, string $base, array $interactionLocation = []): array
    {
        $proof=audienceCollectionText($input['proof']??'',12000);
        try { $p=json_decode(\Dnr\Security\ApplicationKey::open($proof),true,12,JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new InvalidArgumentException('This form expired. Reload the page and try again.'); }
        if (($p['account']??'')!==currentAccountKey() || ($p['audience']??0)!==$this->audienceId || ($p['resource']??0)!==(int)$r['id'] || ($p['revision']??0)!==(int)$r['published_revision'] || ($p['issued']??0)>time()-2 || ($p['issued']??0)<time()-7200 || !preg_match('/^[a-f0-9]{48}$/D',$p['nonce']??'') || !preg_match('/^[a-f0-9]{64}$/D',$p['visitor']??'')) throw new InvalidArgumentException('This form expired. Reload the page and try again.');
        if (audienceText($input,'website',200)!=='') throw new InvalidArgumentException('The response could not be accepted.');
        $this->collectionRateLimit($ip,60);
        return $this->transaction(function() use($r,$input,$p,$base,$interactionLocation) {
            $fresh=$this->collectionResource((int)$r['id'],true);
            if ($fresh['state']!=='published' || (int)$fresh['published_revision']!==(int)$p['revision']) throw new InvalidArgumentException('This form has changed. Reload and try again.');
            if(!empty($r['document']['close_at'])&&$r['document']['close_at']<=gmdate('Y-m-d\TH:i'))throw new InvalidArgumentException('This survey is closed.');
            $hash=hash('sha256',$p['nonce']);
            if ($prior=$this->one('SELECT payload_ciphertext FROM marketing_collection_responses WHERE audience_id=? AND resource_id=? AND receipt_hash=?',[$this->audienceId,$r['id'],$hash])) {
                $answers=json_decode(\Dnr\Security\ApplicationKey::open($prior['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR);
                return ['confirmed'=>$answers['confirmation_required']??($r['document']['opt_in']==='double'),'duplicate'=>true];
            }
            $d=$r['document']; $answers=[]; $contact=null; $confirmation=false;
            if ($r['kind']==='survey') {
                $answers=audienceCollectionAnswers($d,$input);
                $contact=$this->collectionRecipient($p['recipient']??'');
                if ($contact && $this->one('SELECT id FROM marketing_collection_responses WHERE audience_id=? AND resource_id=? AND contact_id=?',[$this->audienceId,$r['id'],$contact['id']])) return ['confirmed'=>false,'duplicate'=>true];
                if (!$contact && $this->one('SELECT id FROM marketing_collection_responses WHERE audience_id=? AND resource_id=? AND visitor_hash=?',[$this->audienceId,$r['id'],$p['visitor']])) return ['confirmed'=>false,'duplicate'=>true];
                if($contact)foreach($d['questions']as$question)foreach($question['answer_tags']??[]as$answer=>$tagIds)if(in_array((string)$answer,array_map('strval',(array)($answers[$question['id']]??[])),true))foreach($tagIds as$tag){
                    $this->write('INSERT IGNORE INTO marketing_contact_tags(contact_id,tag_id) SELECT ?,id FROM marketing_tags WHERE audience_id=? AND id=?',[$contact['id'],$this->audienceId,$tag]);
                    if($this->db->affected_rows>0)$this->event((int)$contact['id'],'tags_changed',['added'=>[(int)$tag],'removed'=>[],'source'=>'Survey answer']);
                    $this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Survey recipient','recipient','survey-answer-tag',?)",[$contact['id'],json_encode(['resource_id'=>(int)$r['id'],'question_id'=>$question['id'],'tag_id'=>(int)$tag],JSON_THROW_ON_ERROR)]);
                }
            } elseif ($r['kind']==='preferences') {
                $contact=$this->collectionRecipient($p['recipient']??'');
                if (!$contact) throw new InvalidArgumentException('Use the private preferences link in your email.');
                $this->savePublicPreferences((int)$contact['id'],(int)audienceText($input,'version',12),$input,$d,$interactionLocation);
                $answers=['preferences'=>true];
            } else {
                [$answers,$confirmation]=$this->collectionSignup($d,$input,array_replace($r,['variant'=>$p['variant']]),$base,$interactionLocation);
            }
            $sealed=\Dnr\Security\ApplicationKey::seal(json_encode($answers,JSON_THROW_ON_ERROR));
            $this->write('INSERT INTO marketing_collection_responses(audience_id,resource_id,revision_id,receipt_hash,visitor_hash,contact_id,payload_ciphertext,variant_key) VALUES (?,?,?,?,?,?,?,?)',[$this->audienceId,$r['id'],$r['published_revision'],$hash,$p['visitor'],$contact['id']??null,$sealed,$p['variant']]);
            $response=(int)$this->db->insert_id;
            if($r['kind']==='survey'){
                $this->inboxSurveyResponse($r,$answers,$contact,$response);
                if($contact)$this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Survey recipient','recipient','survey-response',?)",[$contact['id'],json_encode(['resource_id'=>(int)$r['id'],'response_id'=>$response]+($interactionLocation?['interaction_location'=>$interactionLocation]:[]),JSON_THROW_ON_ERROR)]);
            }
            return ['confirmed'=>$confirmation,'duplicate'=>false];
        });
    }

    public function collectionRateLimit(string $identifier, int $limit): void
    {
        $this->write('DELETE FROM marketing_collection_limits WHERE audience_id=? AND hour_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 48 HOUR) LIMIT 100',[$this->audienceId]);
        $bucket=hash('sha256',currentAccountKey().'|collection|'.$identifier); $hour=gmdate('Y-m-d H:00:00');
        $this->write('INSERT INTO marketing_collection_limits(audience_id,bucket_hash,hour_start) VALUES (?,?,?) ON DUPLICATE KEY UPDATE attempts=attempts+1',[$this->audienceId,$bucket,$hour]);
        $attempts=(int)$this->one('SELECT attempts FROM marketing_collection_limits WHERE audience_id=? AND bucket_hash=? AND hour_start=?',[$this->audienceId,$bucket,$hour])['attempts'];
        if ($attempts>$limit) throw new AudienceRateLimitException('rate_limit','Too many attempts. Please try again later.');
    }

    /** Bound anonymous analytics writes without blocking form access or consent. */
    public function recordCollectionVisit(int $resource,string $key,string $visitor,string $ip,string $variant='A'):bool
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$visitor)||!in_array($variant,['A','B'],true))return false;
        // Share the budget across this account's forms. A caller controls the
        // visitor ID and public form key, but cannot select the trusted client IP.
        try{$this->collectionRateLimit('visit:'.$ip,300);}catch(InvalidArgumentException){return false;}
        $this->write("INSERT IGNORE INTO marketing_collection_visits(resource_id,day,visitor_hash,variant_key) SELECT id,UTC_DATE(),?,? FROM marketing_collection_resources WHERE audience_id=? AND id=? AND public_key=? AND state='published' AND deleted_at IS NULL",[hash('sha256',$key.$visitor),$variant,$this->audienceId,$resource,$key]);
        return $this->db->affected_rows===1;
    }

    public function collectionRecipient(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $r=$this->one('SELECT c.* FROM marketing_campaign_recipients r JOIN marketing_contacts c ON c.id=r.contact_id AND c.audience_id=r.audience_id WHERE r.audience_id=? AND r.token_hash=? AND r.email_hash=SHA2(LOWER(c.email),256) AND c.deleted_at IS NULL AND c.is_archived=0',[$this->audienceId,hash('sha256',$token)]);
        return $r;
    }

    /** Bounded streaming keeps plaintext response filtering and exports in memory. */
    public function collectionResponses(int $resource,array $filters=[]): Generator
    {
        $where='audience_id=? AND resource_id=?';$params=[$this->audienceId,$resource];
        if(($filters['start']??'')!==''){$where.=' AND created_at>=?';$params[]=$filters['start'].' 00:00:00';}
        if(($filters['end']??'')!==''){$where.=' AND created_at<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$filters['end'].' 00:00:00';}
        if(!empty($filters['revision'])){$where.=' AND revision_id=?';$params[]=$filters['revision'];}
        $last=0;
        do{
            $batch=$this->all("SELECT * FROM marketing_collection_responses WHERE $where AND id>? ORDER BY id LIMIT 200",[...$params,$last]);
            foreach($batch as$row){
                $last=(int)$row['id'];$row['answers']=audienceJson(\Dnr\Security\ApplicationKey::open($row['payload_ciphertext']));
                if(($filters['question']??'')!==''){
                    $value=$row['answers'][$filters['question']]??null;if($value===null||$value===''||$value===[])continue;
                    if(($filters['answer']??'')!==''&&!in_array($filters['answer'],is_array($value)?$value:[(string)$value],true))continue;
                }
                yield $row;
            }
        }while(count($batch)===200);
    }

    private function collectionSignup(array $d, array $input, array $r, string $base, array $interactionLocation = []): array
    {
        $format=($d['embedded']['show_format']??false)?audienceText($input,'email_format',4):'html';
        if($format==='')$format='html';
        if(!in_array($format,['html','text'],true))throw new InvalidArgumentException('Choose HTML or plain text email.');
        if (($input['permission']??'')!=='1') throw new InvalidArgumentException('Confirm that you want to receive email newsletters.');
        $email=strtolower(audienceText($input,'email',254)); if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        $this->collectionRateLimit('email:'.$email,5);
        $values=['email'=>$email,'first_name'=>'','last_name'=>'']; $custom=[];$customTypes=[];
        foreach (['first_name','last_name'] as $key) if (in_array($key,$d['fields'],true)) $values[$key]=audienceText($input,$key,100);
        foreach ($this->fields() as $f) if ($f['is_public'] && in_array('custom:'.$f['id'],$d['fields'],true)) {
            $f['required']=in_array('custom:'.$f['id'],$d['required'],true); $custom[$f['id']]=audienceCollectionFieldValue($f,is_array($input['custom']??null)?($input['custom'][$f['id']]??''):'');$customTypes[$f['id']]=$f['field_type'];
        }
        foreach ($d['required'] as $key) if (isset($values[$key]) && $values[$key]==='') throw new InvalidArgumentException('Complete all required fields.');
        $chosen=audienceIds([...(array)($input['group_ids']??[]),...array_filter((array)($input['group_pick']??[]))]); $allowed=[];
        foreach ($this->groups() as $g) if ($g['display_type']!=='hidden' && in_array((int)$g['id'],$d['group_ids'],true)) {
            $ids=array_map('intval',array_column($g['options'],'id')); $allowed=[...$allowed,...$ids];
            if (in_array($g['display_type'],['radio','dropdown'],true) && count(array_intersect($ids,$chosen))>1) throw new InvalidArgumentException('Choose one interest for '.$g['name'].'.');
        }
        if (array_diff($chosen,$allowed)) throw new InvalidArgumentException('Choose available interests.');
        $permissions=is_array($input['permissions']??null)?array_values($input['permissions']):[];
        foreach ($permissions as $permission) if (!is_string($permission)) throw new InvalidArgumentException('Choose available permission options.');
        if (array_diff($permissions,$d['permissions'])) throw new InvalidArgumentException('Choose available permission options.');
        if ($d['quiz'] && !in_array(audienceText($input,'quiz',200),array_column($d['quiz'],'label'),true)) throw new InvalidArgumentException('Choose one quiz answer.');
        $hash=audienceDispatchEmailHash($email); $confirmation=$d['opt_in']==='double';
        // A double opt-in form is uniformly unavailable until its mail lane is
        // ready, regardless of whether this address is already in the audience.
        if ($confirmation) { $ses=$this->sesSettings(); if (audienceSesReasons($ses,$ses['from_email']??'')) throw new InvalidArgumentException('This signup form is temporarily unavailable. Please try again later.'); }
        $person=$this->one('SELECT * FROM marketing_contacts WHERE audience_id=? AND email=? FOR UPDATE',[$this->audienceId,$email]);
        $newPerson=!$person;
        $deleted=$this->one('SELECT deleted_at FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=? FOR SHARE',[$this->audienceId,$hash]);
        $resubscribe=$deleted&&(!$person||($person['email_status']==='pending'&&$person['created_at']>=$deleted['deleted_at']));
        $blocked=($deleted&&!$resubscribe&&audienceDeletedEmailSuppressed($this->db,$this->audienceId,(int)$person['id'],$hash)) || ($person && ($person['deleted_at'] || $person['email_status']==='cleaned'));
        $confirmation=$confirmation||$resubscribe;
        // A deleted address must verify ownership again even on a single opt-in form.
        if($resubscribe){$ses=$this->sesSettings();if(audienceSesReasons($ses,$ses['from_email']??''))throw new InvalidArgumentException('This signup form is temporarily unavailable. Please try again later.');}
        if (!$blocked) {
            $wasSubscribed=$person&&$person['email_status']==='subscribed';
            $optout=(bool)$this->one('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=? AND email_hash=?',[$this->audienceId,$hash]);
            $confirmation=$confirmation || $optout || (bool)$person;
            if($confirmation){$ses=$this->sesSettings();if(audienceSesReasons($ses,$ses['from_email']??''))throw new InvalidArgumentException('This signup form is temporarily unavailable. Please try again later.');}
            if (!$person) {
                $this->write("INSERT INTO marketing_contacts(audience_id,email,first_name,last_name,email_status,custom_json,source,notes) VALUES (?,?,?,?,?,?,?, '')",[$this->audienceId,$email,$values['first_name'],$values['last_name'],$confirmation?'pending':'subscribed',json_encode((object)$custom,JSON_THROW_ON_ERROR),'Signup form']);
                $person=$this->person((int)$this->db->insert_id);
                foreach ($d['tag_ids'] as $tag) if ($this->one('SELECT id FROM marketing_tags WHERE audience_id=? AND id=?',[$this->audienceId,$tag])) $this->write('INSERT IGNORE INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$person['id'],$tag]);
                foreach ($chosen as $option) $this->write('INSERT IGNORE INTO marketing_contact_groups(contact_id,option_id) VALUES (?,?)',[$person['id'],$option]);
            }
            // Existing profiles remain unchanged until the owner confirms this submission.
            $this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Signup form','recipient','signup-permission',?)",[$person['id'],json_encode(['resource_id'=>(int)$r['id'],'revision_id'=>(int)$r['published_revision'],'source'=>$r['kind'],'permission'=>$d['permission'],'choices'=>$permissions,'opt_in'=>$confirmation?'double':'single','email_format'=>$newPerson?$format:null,'at'=>gmdate(DATE_ATOM)]+($interactionLocation?['interaction_location'=>$interactionLocation]:[]),JSON_THROW_ON_ERROR)]);
            if ($confirmation) {
                $context=['resource_id'=>(int)$r['id'],'revision_id'=>(int)$r['published_revision'],'variant_key'=>$r['variant']??'A'];
                if(!$newPerson)$context['profile']=['first_name'=>$values['first_name'],'last_name'=>$values['last_name'],'custom'=>$custom,'custom_types'=>$customTypes];
                $this->requestSubscriptionConfirmation((int)$person['id'],$base,$context);
            } elseif($person['email_status']!=='subscribed') {
                $this->write("UPDATE marketing_contacts SET email_status='subscribed',version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$person['id']]);
            }
            if(!$wasSubscribed&&!$confirmation)$this->recordCollectionConversion((int)$person['id'],['resource_id'=>(int)$r['id'],'revision_id'=>(int)$r['published_revision'],'variant_key'=>$r['variant']??'A'],'single');
        }
        return [['signup'=>true,'permission'=>$d['permission'],'choices'=>$permissions,'email_format'=>$format,'quiz'=>audienceText($input,'quiz',200),'confirmation_required'=>$confirmation],$confirmation];
    }

    /** Public repeat signups cannot overwrite an existing contact's preference. */
    public function collectionEmailFormat(int $contact): string
    {
        $row=$this->one("SELECT JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.email_format')) format FROM marketing_contact_events WHERE contact_id=? AND actor_type='recipient' AND event_type='signup-permission' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.email_format')) IN ('html','text') ORDER BY id DESC LIMIT 1",[$contact]);
        return ($row['format']??'')==='text'?'text':'html';
    }

    public function collectionConversionContext(array $context):array
    {
        $resource=(int)($context['resource_id']??0);$revision=(int)($context['revision_id']??0);$variant=$context['variant_key']??'A';
        if(!is_string($variant)||!preg_match('/^[A-D]$/D',$variant)||!$this->one('SELECT v.id FROM marketing_collection_revisions v JOIN marketing_collection_resources r ON r.id=v.resource_id AND r.audience_id=v.audience_id WHERE v.audience_id=? AND v.resource_id=? AND v.id=? AND r.kind IN (\'hosted\',\'embedded\',\'popup\',\'landing\')',[$this->audienceId,$resource,$revision]))throw new InvalidArgumentException('This signup attribution is unavailable.');
        return ['resource_id'=>$resource,'revision_id'=>$revision,'variant_key'=>$variant];
    }

    public function recordCollectionConversion(int $contact,array $context,string $method):void
    {
        if(!in_array($method,['single','double'],true))throw new InvalidArgumentException('Invalid subscription method.');
        $context=$this->collectionConversionContext($context);$this->person($contact);
        $this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Signup form','recipient','collection-converted',?)",[$contact,json_encode($context+['method'=>$method],JSON_THROW_ON_ERROR)]);
    }

    public function collectionConversions(int $resource,string $start='',string $end='',int $revision=0):array
    {
        $this->collectionResource($resource,false,true);
        $where="p.audience_id=? AND p.deleted_at IS NULL AND e.event_type='collection-converted' AND JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.resource_id'))=?";$params=[$this->audienceId,(string)$resource];
        if($start!==''){$where.=' AND e.created_at>=?';$params[]=$start.' 00:00:00';}
        if($end!==''){$where.=' AND e.created_at<DATE_ADD(?,INTERVAL 1 DAY)';$params[]=$end;}
        if($revision){$where.=" AND JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.revision_id'))=?";$params[]=(string)$revision;}
        return $this->one("SELECT COUNT(DISTINCT p.id) subscribers,COUNT(DISTINCT CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.method'))='double' THEN p.id END) confirmed FROM marketing_contact_events e JOIN marketing_contacts p ON p.id=e.contact_id WHERE $where",$params);
    }
}

function audienceCollectionAnswers(array $d, array $input): array
{
    $answers=[]; $raw=is_array($input['answers']??null)?$input['answers']:[];
    foreach ($d['questions'] as $q) {
        if ($q['type']==='content') continue;
        $v=$raw[$q['id']]??'';
        $otherValue='__message_monkey_other__';$hasOther=!empty($q['other'])&&in_array($otherValue,(array)$v,true);$other='';
        if($hasOther){$other=audienceCollectionText($input['answers_other'][$q['id']]??'',500);if($other==='')throw new InvalidArgumentException('Complete the other answer.');}
        $choices=$q['options'];if(!empty($q['other']))$choices[]=$otherValue;
        if ($q['type']==='contact') {
            if(!is_array($v))$v=[];$contact=[];
            foreach(['first_name'=>100,'last_name'=>100,'email'=>254]as$key=>$limit)$contact[$key]=audienceCollectionText($v[$key]??'',$limit);
            $contact['phone']=audienceCollectionPhoneValue($v['phone']??'');
            if($contact['email']!==''&&!filter_var($contact['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid contact email address.');
            if($q['required']&&!array_filter($contact))throw new InvalidArgumentException('Complete the required contact information.');
            $v=$contact;
        } elseif ($q['type']==='checkboxes') {
            if (!is_array($v)) $v=$v===''?[]:[$v];
            foreach ($v as $choice) if (!is_string($choice)) throw new InvalidArgumentException('Choose valid answers.');
            if (array_diff($v,$choices)) throw new InvalidArgumentException('Choose valid answers for '.$q['label'].'.');
            $v=array_values(array_unique($v));
        } else {
            $v=audienceCollectionText($v,$q['type']==='text'?5000:500);
            if ($v!=='' && in_array($q['type'],['radio','dropdown'],true) && !in_array($v,$choices,true)) throw new InvalidArgumentException('Choose a valid answer.');
            if ($v!=='' && $q['type']==='range' && !in_array($v,array_map('strval',range(1,10)),true)) throw new InvalidArgumentException('Choose a rating from 1 to 10.');
            if ($v!=='' && $q['type']==='email' && !filter_var($v,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
        }
        if ($q['required'] && ($v==='' || $v===[])) throw new InvalidArgumentException('Answer the required question: '.$q['label']);
        if($hasOther){if(is_array($v))$v=array_map(static fn($answer)=>$answer===$otherValue?'Other: '.$other:$answer,$v);else$v='Other: '.$other;}
        $answers[$q['id']]=$v;
    }
    return $answers;
}

/** Collection links are bound into the immutable campaign snapshot and recipient. */
function audienceCollectionMerge(string $text,array $snapshot,string $token,bool $html): string
{
    return preg_replace_callback('/\*\|(SURVEY|POLL):([a-f0-9]{64})(?::([0-9]{1,2}))?\|\*/',static function($m)use($snapshot,$token,$html) {
        if (($snapshot['collection_links'][$m[2]]??'')!=='survey') throw new InvalidArgumentException('Publish this survey in the current audience before using its email link.');
        $url=audienceDispatchBase($snapshot['base']).'/audience_collect_public.php?key='.$m[2];
        if (!empty($snapshot['test'])) $url.='&preview_poll=1';
        else $url.='&recipient='.$token;
        if ($m[1]==='POLL' && isset($m[3])) $url.='&choice='.(int)$m[3];
        return $html?htmlspecialchars($url,ENT_QUOTES,'UTF-8'):$url;
    },$text);
}

/** Published system-form appearance, with immutable consent actions kept native. */
function audienceCollectionConfirmationIntro(string $intro,array $audience):string
{
    if($intro==='Confirm that you want to receive emails from this audience.')$intro=audienceCollectionDefaults('hosted')['system']['confirm_intro'];
    $organization=audienceBrandIdentity(audienceJson($audience['settings_json']??''),(string)($audience['name']??''))['LIST:COMPANY'];
    return str_replace('*|LIST:COMPANY|*',$organization!==''?$organization:'our organization',$intro);
}

function audienceCollectionSystem(AudienceStore $store,string $screen): ?array
{
    if (!in_array($screen,['unsubscribe','confirm'],true) || !$store->collectionAvailable()) return null;
    $row=$store->one("SELECT r.kind,v.document_json FROM marketing_collection_resources r JOIN marketing_collection_revisions v ON v.id=r.published_revision AND v.resource_id=r.id AND v.audience_id=r.audience_id WHERE r.audience_id=? AND r.kind='hosted' AND r.state='published' AND r.deleted_at IS NULL ORDER BY r.id DESC LIMIT 1",[$store->audienceId]);
    if (!$row) return null;
    $d=audienceJson($row['document_json']); $defaults=audienceCollectionDefaults('hosted')['system']; $system=array_replace($defaults,$d['system']??[]);
    $d['title']=$system[$screen.'_title']; $d['intro']=$system[$screen.'_intro']; $d['button']=$system[$screen.'_button']; $d['fields']=[]; $d['group_ids']=[]; $d['sections']=[]; $d['quiz']=[];
    if($screen==='confirm')$d['intro']=audienceCollectionConfirmationIntro($d['intro'],$store->one('SELECT name,settings_json FROM marketing_audiences WHERE id=?',[$store->audienceId])??[]);
    return ['kind'=>$screen,'document'=>$d,'done'=>$system[$screen.'_done']];
}

/** Location is informational targeting, accepted only through the existing trusted edge chain. */
function audienceCollectionCountry(array $server,?string $trusted=null,?string $cloudflare=null): ?string
{
    $trusted??=trustedProxyNetworks();$cloudflare??=trustedCloudflareProxyNetworks();
    $remote=is_string($server['REMOTE_ADDR']??null)?$server['REMOTE_ADDR']:'';
    if(!\Dnr\Http\ClientAddress::isAddressInTrustedNetworks($remote,$trusted))return null;
    $country=$server['HTTP_CF_IPCOUNTRY']??'';
    if(!is_string($country)||!preg_match('/^[A-Z]{2}$/D',$country)||in_array($country,['XX','ZZ'],true))return null;
    foreach(array_reverse(explode(',',(string)($server['HTTP_X_FORWARDED_FOR']??'')))as$address){
        $address=trim($address);if(!filter_var($address,FILTER_VALIDATE_IP))continue;
        if(\Dnr\Http\ClientAddress::isAddressInTrustedNetworks($address,$cloudflare)){
            return filter_var($server['HTTP_CF_CONNECTING_IP']??'',FILTER_VALIDATE_IP)&&preg_match('/^[0-9a-f]{16,32}(?:-[a-z]{3})?$/iD',(string)($server['HTTP_CF_RAY']??''))?$country:null;
        }
        if(!\Dnr\Http\ClientAddress::isAddressInTrustedNetworks($address,$trusted))return null;
    }
    return null;
}
