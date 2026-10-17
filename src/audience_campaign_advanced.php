<?php
declare(strict_types=1);

function audienceCampaignDatePresets(): array
{
    return [''=>'All dates','last7'=>'Last 7 days','last30'=>'Last 30 days','last90'=>'Last 90 days','last365'=>'Last 365 days','week'=>'Last week','month'=>'Last month','quarter'=>'Last quarter','qtd'=>'Quarter-to-date','ytd'=>'Year-to-date','custom'=>'Custom dates'];
}
function audienceCampaignDateRange(string $preset, ?DateTimeImmutable $today=null): array
{
    $today=($today??new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->setTime(0,0);
    $end=$today;$start=$today;
    if(preg_match('/^last(7|30|90|365)$/D',$preset,$m))$start=$today->modify('-'.((int)$m[1]-1).' days');
    elseif($preset==='week'){$start=$today->modify('monday last week');$end=$start->modify('+6 days');}
    elseif($preset==='month'){$start=$today->modify('first day of last month');$end=$start->modify('last day of this month');}
    elseif(in_array($preset,['quarter','qtd'],true)){$start=$today->setDate((int)$today->format('Y'),(int)(floor(((int)$today->format('n')-1)/3)*3+1),1);if($preset==='quarter'){$end=$start->modify('-1 day');$start=$start->modify('-3 months');}else $end=$today->modify('-1 day');}
    elseif($preset==='ytd'){$start=$today->setDate((int)$today->format('Y'),1,1);$end=$today->modify('-1 day');}
    else return ['',''];
    return [$start->format('Y-m-d'),$end->format('Y-m-d')];
}
function audienceCampaignType(array $row):string
{
    $draft=$row['draft']??audienceJson($row['draft_json']??'{}');
    return $draft['experiment']['kind']??$row['campaign_type'];
}
function audienceCampaignGoals():array
{
    return ['Advertise a sale or promotion','Educate or inform','Sell products','Gather sign-ups','Make an announcement','Promote an event','Sell a service/get bookings','Other'];
}
function audienceCampaignTracking(array $input):array
{
    $out=[];foreach(['opens','clicks','plain_clicks','inbox','video']as$key)$out[$key]=($input[$key]??'0')==='1'||($input[$key]??false)===true;return $out;
}
function audienceCampaignDate(string $value,bool $time=false):string
{
    $format=$time?'Y-m-d\TH:i':'Y-m-d';$date=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));
    if(!$date||$date->format($format)!==$value)throw new InvalidArgumentException('Enter a valid '.($time?'date and time.':'date.'));
    return $time?$date->format('Y-m-d H:i:s'):$value;
}
function audienceCampaignPlannerRange(string $date,string $scale):array
{
    $focus=new DateTimeImmutable(audienceCampaignDate($date),new DateTimeZone('UTC'));
    $start=match($scale){'month'=>$focus->modify('first day of this month')->modify('monday this week'),'week'=>$focus->modify('monday this week'),default=>$focus};
    $end=match($scale){'month'=>$focus->modify('last day of this month')->modify('sunday this week'),'week'=>$start->modify('+6 days'),default=>$start};
    return [$start,$end];
}
function audienceCampaignExperiment(array $input,string $kind='multivariate'):array
{
    if(!in_array($kind,['ab','multivariate'],true))throw new InvalidArgumentException('Choose an A/B or multivariate test.');
    $raw=$input['variables']??[];if(!is_array($raw))throw new InvalidArgumentException('Choose test variables.');
    $variables=[];$combinations=1;
    foreach(['subject','from','content','time']as$key){
        if(isset($input['enabled_variables'])&&(!is_array($input['enabled_variables'])||!in_array($key,$input['enabled_variables'],true)))continue;
        if(!isset($raw[$key])||!is_array($raw[$key])||!$raw[$key])continue;
        if(count($raw[$key])<2||count($raw[$key])>8)throw new InvalidArgumentException('Each test variable needs two to eight variations.');
        $values=[];foreach($raw[$key]as$value){$value=audienceText(['value'=>$value],'value',$key==='subject'?200:100);if($key==='time')audienceCampaignDate($value,true);if($key!=='content'&&$value==='')throw new InvalidArgumentException('Complete each test variation.');$values[]=$key==='content'?'Variation '.(count($values)+1):$value;}
        if($key!=='content'&&count(array_unique($values))!==count($values))throw new InvalidArgumentException('Test variations must be different.');$variables[$key]=$values;$combinations*=count($values);
    }
    if(!$variables||count($variables)>($kind==='ab'?1:3)||$combinations>($kind==='ab'?3:8))throw new InvalidArgumentException($kind==='ab'?'A/B tests use one variable and up to three variations.':'Test up to three variables and eight combinations.');
    $percent=filter_var($input['test_percent']??100,FILTER_VALIDATE_INT);$hours=filter_var($input['test_hours']??4,FILTER_VALIDATE_INT);$winner=audienceText($input,'winner')?:'clicks';
    if($percent===false||$percent<10||$percent>100||$hours===false||$hours<1||$hours>168||!in_array($winner,['opens','clicks','manual'],true))throw new InvalidArgumentException('Choose a valid test size, duration and winner method.');
    if(isset($variables['time'])&&$percent!==100)throw new InvalidArgumentException('Send-time tests must include 100% of recipients.');
    return ['kind'=>$kind,'variables'=>$variables,'combinations'=>$combinations,'percent'=>$percent,'hours'=>$hours,'winner'=>$winner];
}
function audienceCampaignHolidays(int $year):array
{
    // Native US holiday overlay. No external calendar account or API is used.
    $dates=[$year.'-01-01'=>"New Year’s Day",$year.'-06-19'=>'Juneteenth',$year.'-07-04'=>'Independence Day',$year.'-11-11'=>'Veterans Day',$year.'-12-25'=>'Christmas Day'];
    foreach(['third monday of january'=>'Martin Luther King Jr. Day','third monday of february'=>'Presidents’ Day','last monday of may'=>'Memorial Day','first monday of september'=>'Labor Day','second monday of october'=>'Indigenous Peoples’ Day','fourth thursday of november'=>'Thanksgiving']as$when=>$label)$dates[(new DateTimeImmutable($when.' '.$year))->format('Y-m-d')]=$label;
    return $dates;
}

trait AudienceCampaignAdvanced
{
    private function campaignTargetRule(string $target):array
    {
        if(preg_match('/^tag:([1-9][0-9]*)$/D',$target,$m)){
            if(!$this->one('SELECT id FROM marketing_tags WHERE audience_id=? AND id=?',[$this->audienceId,(int)$m[1]]))throw new InvalidArgumentException('A selected tag is unavailable.');
            return ['field'=>'tags','operator'=>'any','value'=>[(int)$m[1]]];
        }
        if(preg_match('/^segment:([1-9][0-9]*)$/D',$target,$m)){
            $segment=$this->one('SELECT filters_json FROM marketing_segments WHERE audience_id=? AND id=?',[$this->audienceId,(int)$m[1]]);if(!$segment)throw new InvalidArgumentException('A selected segment is unavailable.');
            return audienceLegacyRules(audienceJson($segment['filters_json']),$this->tags());
        }
        throw new InvalidArgumentException('Choose an available tag or segment.');
    }
    public function campaignRecipients(array $draft):array
    {
        $include=$draft['include']??(($draft['target']??'all')==='all'?[]:[$draft['target']]);
        $exclude=$draft['exclude']??(!empty($draft['exclude_tag'])?['tag:'.$draft['exclude_tag']]:[]);
        if(!is_array($include)||!is_array($exclude)||count($include)>10||count($exclude)>10)throw new InvalidArgumentException('Select up to 10 tags or segments in each field.');
        foreach([...$include,...$exclude]as$value)if(!is_string($value))throw new InvalidArgumentException('Choose valid recipient tags or segments.');
        $include=array_values(array_unique($include));$exclude=array_values(array_unique($exclude));
        $root=['match'=>'all','rules'=>[]];$includeRules=[];
        foreach($include as$value){if(!is_string($value))throw new InvalidArgumentException('Choose valid recipients.');$includeRules[]=$this->campaignTargetRule($value);}
        if($includeRules)$root['rules'][]=['match'=>'any','rules'=>$includeRules];
        [$where,$params]=$this->where(['status'=>['subscribed']],$root);
        foreach($exclude as$value){if(!is_string($value))throw new InvalidArgumentException('Choose valid exclusions.');$rule=$this->campaignTargetRule($value);[$excluded,$values]=$this->where(['status'=>['subscribed']],isset($rule['field'])?['match'=>'all','rules'=>[$rule]]:$rule);$where.=' AND NOT ('.$excluded.')';array_push($params,...$values);}
        return [$where,$params];
    }
    public function campaignDesign(array $row,int $variant=0):?array
    {
        if($variant<0||$variant>7||($variant>0&&$variant>=count($row['draft']['experiment']['variables']['content']??[])))throw new InvalidArgumentException('This content variation is unavailable.');
        return $variant>0?($row['draft']['design_variants'][$variant]??$row['draft']['design']??null):($row['draft']['design']??null);
    }
    public function campaignMergeTags():array
    {
        $tags=['FNAME'=>'First Name','LNAME'=>'Last Name','EMAIL'=>'Email Address'];foreach($this->fields()as$field)$tags[$field['merge_tag']]=$field['label'];return $tags;
    }
    public function campaignAdvancedSection(array $row,string $section,array $input):array
    {
        $draft=$row['draft'];
        switch($section){
            case 'to':
                if(isset($input['include'])||isset($input['exclude'])||isset($input['advanced_recipients'])){$draft['include']=is_array($input['include']??null)?$input['include']:[];$draft['exclude']=is_array($input['exclude']??null)?$input['exclude']:[];foreach(['include','exclude']as$key)if(isset($input[$key])&&!is_array($input[$key]))throw new InvalidArgumentException('Choose valid recipient tags or segments.');$draft['target']='all';$draft['exclude_tag']=0;}
                else{$draft['target']=audienceText($input,'target',40);$draft['exclude_tag']=(int)audienceText($input,'exclude_tag',12);unset($draft['include'],$draft['exclude']);}
                $this->campaignRecipients($draft);$draft['personalize_to']=($input['personalize_to']??'')==='1';$draft['to_merge']=audienceText($input,'to_merge',50)?:'FNAME';if(!isset($this->campaignMergeTags()[$draft['to_merge']]))throw new InvalidArgumentException('Choose an available To merge tag.');$draft['recipients_saved']=true;break;
            case 'tracking':$draft['tracking']=audienceCampaignTracking($input);break;
            case 'send_time':
                $mode=audienceText($input,'send_mode');if(!in_array($mode,['now','schedule','triggered'],true))throw new InvalidArgumentException('Choose when to send.');
                $zone=audienceText($input,'timezone',64)?:'America/Chicago';if(!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid time zone.');
                $schedule=['mode'=>$mode,'timezone'=>$zone,'delivery'=>audienceText($input,'delivery')?:'specific'];if(!in_array($schedule['delivery'],['specific','optimized','timewarp'],true))throw new InvalidArgumentException('Choose a valid delivery time option.');
                if($mode==='schedule'){$date=audienceCampaignDate(audienceText($input,'delivery_date'));$time=audienceText($input,'delivery_time');if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Choose a valid delivery time.');$local=new DateTimeImmutable($date.' '.$time,new DateTimeZone($zone));if($local->format('Y-m-d H:i')!==$date.' '.$time)throw new InvalidArgumentException('That local time does not exist because of daylight saving time.');$schedule['local']=$local->format('Y-m-d H:i');$schedule['utc']=$local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
                if($mode==='triggered'){$tag=(int)audienceText($input,'trigger_tag',12);$this->campaignTargetRule('tag:'.$tag);$schedule['trigger_tag']=$tag;}
                $draft['send_time']=$schedule;break;
            case 'resend':
                $enabled=($input['enabled']??'')==='1';$resend=['enabled'=>$enabled];
                if($enabled){$days=filter_var($input['days']??3,FILTER_VALIDATE_INT);$time=audienceText($input,'time');$segment=audienceText($input,'segment',40)?:'non-openers';if(!in_array($segment,['non-openers','non-clickers'],true))$this->campaignTargetRule($segment);if($days===false||$days<1||$days>30||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Choose 1–30 days and a valid resend time.');$resend+=['days'=>$days,'time'=>$time,'segment'=>$segment,'ignore_bots'=>($input['ignore_bots']??'')==='1','subject'=>audienceText($input,'resend_subject',200)];}
                $draft['resend']=$resend;break;
            case 'social':
                $title=audienceText($input,'card_title',200);if(str_contains($title,'*|'))throw new InvalidArgumentException('Social card titles cannot contain merge tags.');
                $draft['social']=['enabled'=>($input['enabled']??'')==='1','title'=>$title,'description'=>audienceText($input,'card_description',500),'image'=>audienceDesignImage($input['card_image']??'')];break;
            case 'experiment':
                if($row['campaign_type']!=='regular')throw new InvalidArgumentException('Testing requires a regular email draft.');
                $kind=audienceText($input,'experiment_kind')?:($draft['experiment']['kind']??'ab');$draft['experiment']=audienceCampaignExperiment($input,$kind);
                $count=count($draft['experiment']['variables']['content']??[]);if(isset($draft['design_variants']))$draft['design_variants']=array_filter($draft['design_variants'],static fn($key)=>is_numeric($key)&&(int)$key>0&&(int)$key<$count,ARRAY_FILTER_USE_KEY);break;
            default:throw new InvalidArgumentException('Unknown campaign section.');
        }
        return $draft;
    }
    public function campaignPlan(int $id,bool $lock=false):array
    {
        $row=$this->one('SELECT * FROM marketing_campaign_plans WHERE audience_id=? AND id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$this->audienceId,$id]);if(!$row)throw new InvalidArgumentException('This campaign plan is unavailable.');$row['data']=audienceJson($row['data_json']);return $row;
    }
    public function saveCampaignPlan(array $input,int $id,int $version):int
    {
        $name=audienceText($input,'name',100);$goal=audienceText($input,'goal',100);if($name===''||!in_array($goal,audienceCampaignGoals(),true))throw new InvalidArgumentException('Enter a name and choose a marketing goal.');
        $data=['goal'=>$goal,'start'=>audienceCampaignDate(audienceText($input,'start')),'end'=>audienceCampaignDate(audienceText($input,'end')),'notes'=>audienceText($input,'notes',4000),'tracking'=>audienceCampaignTracking($input)];if($data['end']<$data['start'])throw new InvalidArgumentException('End date must follow start date.');
        return $this->transaction(function()use($id,$version,$name,$data){if($id){$old=$this->campaignPlan($id,true);if((int)$old['version']!==$version)throw new InvalidArgumentException('This plan changed. Reload before saving.');$this->write('UPDATE marketing_campaign_plans SET name=?,data_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$id]);}else{$this->write('INSERT INTO marketing_campaign_plans(audience_id,name,data_json,actor_label) VALUES (?,?,?,?)',[$this->audienceId,$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel]);$id=(int)$this->db->insert_id;}return $id;});
    }
    public function campaignPlanOperation(int $id,int $version,string $operation):?int
    {
        return $this->transaction(function()use($id,$version,$operation){$row=$this->campaignPlan($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This plan changed. Reload before continuing.');if($operation==='replicate'){$this->write('INSERT INTO marketing_campaign_plans(audience_id,name,data_json,actor_label) VALUES (?,?,?,?)',[$this->audienceId,mb_substr($row['name'],0,90).' (copy)',$row['data_json'],$this->actorLabel]);return (int)$this->db->insert_id;}if($operation!=='delete')throw new InvalidArgumentException('Unknown plan action.');$this->write('UPDATE marketing_campaign_touchpoints SET plan_id=NULL,version=version+1 WHERE audience_id=? AND plan_id=?',[$this->audienceId,$id]);$this->write('UPDATE marketing_campaign_plans SET deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);return null;});
    }
    public function saveCampaignTouchpoint(array $input,int $id,int $version):int
    {
        $type=audienceText($input,'touchpoint_type');$name=audienceText($input,'name',100);$state=audienceText($input,'state')?:'not-started';if(!in_array($type,['task','event','email'],true)||!in_array($state,['not-started','in-progress','complete'],true)||$name==='')throw new InvalidArgumentException('Enter a touchpoint name, type and status.');
        $start=audienceCampaignDate(audienceText($input,'start_at'),true);$end=audienceCampaignDate(audienceText($input,'end_at'),true);if($start>$end)throw new InvalidArgumentException('End date must follow start date.');
        $plan=(int)audienceText($input,'plan_id',12);$campaign=(int)audienceText($input,'campaign_id',12);$data=['notes'=>audienceText($input,'notes',4000),'event_type'=>audienceText($input,'event_type',80),'use_plan_tracking'=>($input['use_plan_tracking']??'')==='1'];
        return $this->transaction(function()use($id,$version,$type,$name,$state,$start,$end,$plan,$campaign,$data){if($plan)$this->campaignPlan($plan,true);if($type==='email'){if(!$campaign)throw new InvalidArgumentException('Choose an email draft.');$email=$this->campaign($campaign,true);if($plan&&$data['use_plan_tracking']){if($email['status']!=='draft')throw new InvalidArgumentException('Only draft emails can inherit tracking preferences.');$emailDraft=$email['draft'];$emailDraft['tracking']=$this->campaignPlan($plan)['data']['tracking'];$this->write('UPDATE marketing_campaigns SET draft_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[json_encode($emailDraft,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$campaign]);}}elseif($campaign)throw new InvalidArgumentException('Only email touchpoints can reference a campaign.');
            if($id){$old=$this->one('SELECT * FROM marketing_campaign_touchpoints WHERE audience_id=? AND id=? AND deleted_at IS NULL FOR UPDATE',[$this->audienceId,$id]);if(!$old||(int)$old['version']!==$version)throw new InvalidArgumentException('This touchpoint changed. Reload before saving.');$this->write('UPDATE marketing_campaign_touchpoints SET plan_id=?,campaign_id=?,touchpoint_type=?,name=?,start_at=?,end_at=?,state=?,data_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$plan?:null,$type==='email'?$campaign:null,$type,$name,$start,$end,$state,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$id]);}
            else{$this->write('INSERT INTO marketing_campaign_touchpoints(audience_id,plan_id,campaign_id,touchpoint_type,name,start_at,end_at,state,data_json,actor_label) VALUES (?,?,?,?,?,?,?,?,?,?)',[$this->audienceId,$plan?:null,$type==='email'?$campaign:null,$type,$name,$start,$end,$state,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel]);$id=(int)$this->db->insert_id;}return $id;});
    }
    public function campaignTouchpointOperation(int $id,int $version,string $operation):?int
    {
        return $this->transaction(function()use($id,$version,$operation){$row=$this->one('SELECT * FROM marketing_campaign_touchpoints WHERE audience_id=? AND id=? AND deleted_at IS NULL FOR UPDATE',[$this->audienceId,$id]);if(!$row||(int)$row['version']!==$version)throw new InvalidArgumentException('This touchpoint changed. Reload before continuing.');if($operation==='replicate'){$this->write('INSERT INTO marketing_campaign_touchpoints(audience_id,plan_id,campaign_id,touchpoint_type,name,start_at,end_at,state,data_json,actor_label) VALUES (?,?,?,?,?,?,?,\'not-started\',?,?)',[$this->audienceId,$row['plan_id'],$row['campaign_id'],$row['touchpoint_type'],mb_substr($row['name'],0,90).' (copy)',$row['start_at'],$row['end_at'],$row['data_json'],$this->actorLabel]);return (int)$this->db->insert_id;}if($operation!=='delete')throw new InvalidArgumentException('Unknown touchpoint action.');$this->write('UPDATE marketing_campaign_touchpoints SET deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);return null;});
    }
    public function campaignPublication(int $id):?array
    {
        $this->campaign($id);return $this->one('SELECT * FROM marketing_campaign_publications WHERE audience_id=? AND campaign_id=?',[$this->audienceId,$id]);
    }
    public function saveCampaignPublication(int $id,int $version,array $input,array $audience):void
    {
        $this->transaction(function()use($id,$version,$input,$audience){$row=$this->campaign($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This campaign changed. Reload before saving.');$enabled=($input['enabled']??'')==='1';if($enabled&&($row['status']!=='sent'||($input['publication_confirmed']??'')!=='1'))throw new InvalidArgumentException('Only a sent email can be published after confirming its content is public.');$old=$this->campaignPublication($id);$draft=$row['draft'];$social=$draft['social']??[];$context=$this->designRecipientContext(0,$audience);$context['UNSUB']='';$context['UPDATE_PROFILE']='';$context['ARCHIVE']='';
            $html=!empty($draft['design'])?audienceDesignHtml($draft['design'],$context):'<pre>'.htmlspecialchars($draft['text']??'',ENT_QUOTES,'UTF-8').'</pre>';
            // Public versions never expose personalized subscription URLs.
            $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET);foreach(iterator_to_array($dom->getElementsByTagName('a'))as$link)if(trim($link->getAttribute('href'))===''||str_contains($link->getAttribute('href'),'*|')){$span=$dom->createElement('span');$span->appendChild($dom->createTextNode($link->textContent));$link->parentNode?->replaceChild($span,$link);} $html=$dom->saveHTML()?:'';
            $title=($social['enabled']??false)?($social['title']?:($draft['subject']??$row['name'])):($draft['subject']??$row['name']);$description=($social['enabled']??false)?($social['description']??''):($draft['preview_text']??'');$image=($social['enabled']??false)?($social['image']??''):'';$key=$old['public_key']??bin2hex(random_bytes(32));
            if($old)$this->write('UPDATE marketing_campaign_publications SET enabled=?,archive_listed=?,title=?,description=?,image_data=?,html=?,version=version+1 WHERE audience_id=? AND campaign_id=?',[(int)$enabled,(int)(($input['archive_listed']??'')==='1'),$title,$description,$image,$html,$this->audienceId,$id]);
            else $this->write('INSERT INTO marketing_campaign_publications(audience_id,campaign_id,public_key,enabled,archive_listed,title,description,image_data,html) VALUES (?,?,?,?,?,?,?,?,?)',[$this->audienceId,$id,$key,(int)$enabled,(int)(($input['archive_listed']??'')==='1'),$title,$description,$image,$html]);
            $this->write('UPDATE marketing_campaigns SET version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
        });
    }
}
