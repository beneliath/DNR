<?php
declare(strict_types=1);

const AUDIENCE_FLOW_TRIGGERS = [
    'signup'=>'Signs up','profile'=>'Audience field changes','tag'=>'Tag added','untag'=>'Tag removed',
    'group'=>'Joins group','ungroup'=>'Leaves group','date'=>'Date','birthday'=>'Birthday',
    'anniversary'=>'Signup anniversary','open'=>'Opens email','click'=>'Clicks email',
    'survey'=>'Responds to survey','flow'=>'Completes flow','manual'=>'Added manually',
];
const AUDIENCE_FLOW_NODES = [
    'email'=>'Send email','delay'=>'Time delay','wait'=>'Wait for trigger','until'=>'Wait until',
    'condition'=>'Conditional split','split'=>'Percentage split','tag'=>'Tag/Untag',
    'group'=>'Group/Ungroup','update'=>'Update connection','unsubscribe'=>'Unsubscribe',
    'archive'=>'Archive connection','exit'=>'Exit flow',
];

function audienceFlowsInstalled(mysqli $db): bool
{
    return (int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_flows'")->fetch_assoc()['n']===1;
}

function audienceFlowTemplates(): array
{
    return [
        'welcome'=>['name'=>'Welcome new connections','topic'=>'Welcome','description'=>'Greet new subscribers with a personal hello.','trigger'=>'signup','steps'=>['email']],
        'series'=>['name'=>'Introduce your community','topic'=>'Welcome','description'=>'Share a welcome email, wait, then tell your story.','trigger'=>'signup','steps'=>['email','delay','email']],
        'tagged'=>['name'=>'Follow up with tagged connections','topic'=>'Engagement','description'=>'Start a conversation when a tag is added.','trigger'=>'tag','steps'=>['email']],
        'birthday'=>['name'=>'Celebrate a birthday','topic'=>'Milestones','description'=>'Send a thoughtful message on each connection’s birthday.','trigger'=>'birthday','steps'=>['email']],
        'anniversary'=>['name'=>'Celebrate a subscriber milestone','topic'=>'Milestones','description'=>'Recognize another year in your community.','trigger'=>'anniversary','steps'=>['email']],
        'survey'=>['name'=>'Follow up after a survey','topic'=>'Engagement','description'=>'Thank a verified connection for sharing feedback.','trigger'=>'survey','steps'=>['delay','email']],
        'engaged'=>['name'=>'Organize engaged connections','topic'=>'Organization','description'=>'Tag connections after a tracked email click.','trigger'=>'click','steps'=>['tag']],
        'split'=>['name'=>'Choose a different path','topic'=>'Engagement','description'=>'Use audience conditions to guide each connection.','trigger'=>'tag','steps'=>['condition','email']],
    ];
}

function audienceFlowDefault(string $template='blank'): array
{
    $starter=audienceFlowTemplates()[$template]??null;
    $nodes=[];$types=$starter['steps']??[];
    foreach($types as $i=>$type)$nodes[]=['id'=>'step'.($i+1),'type'=>$type,'label'=>AUDIENCE_FLOW_NODES[$type],'next'=>isset($types[$i+1])?'step'.($i+2):'','yes'=>isset($types[$i+1])?'step'.($i+2):'','no'=>'','seconds'=>86400,'percent'=>50];
    return ['trigger'=>['type'=>$starter['trigger']??'manual','id'=>0,'field'=>'','value'=>'','offset'=>0,'time'=>'09:00'],
        'settings'=>['repeat'=>false,'cooldown'=>1,'timezone'=>'America/Chicago','recipient_timezone'=>true,'weekdays'=>[1,2,3,4,5,6,7],'after'=>'00:00','before'=>'23:59','max_days'=>365],
        'entry'=>['match'=>'all','rules'=>[]],'exit'=>['match'=>'all','rules'=>[]],
        'start'=>$nodes[0]['id']??'','nodes'=>$nodes,'classic'=>false];
}

/** An acyclic map gives each effect a stable (run,node) identity. Incomplete drafts remain editable. */
function audienceFlowDocument(array $raw): array
{
    $d=audienceFlowDefault();$t=$raw['trigger']??[];$s=$raw['settings']??[];
    if(!is_array($t)||!is_array($s)||!is_array($raw['nodes']??[]))throw new InvalidArgumentException('Invalid flow document.');
    if(!isset(AUDIENCE_FLOW_TRIGGERS[$t['type']??'manual']))throw new InvalidArgumentException('Choose a native flow trigger.');
    $d['trigger']=['type'=>$t['type']??'manual','id'=>max(0,(int)($t['id']??0)),'field'=>audienceText($t,'field',50),'value'=>audienceText($t,'value',300),'offset'=>max(-365,min(365,(int)($t['offset']??0))),'time'=>audienceText($t,'time',5)?:'09:00'];
    $zone=$s['timezone']??'America/Chicago';if(!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid time zone.');
    $days=array_values(array_unique(array_map('intval',is_array($s['weekdays']??null)?$s['weekdays']:[1,2,3,4,5,6,7])));if(!$days||array_diff($days,range(1,7)))throw new InvalidArgumentException('Choose at least one delivery day.');
    $d['settings']=['repeat'=>!empty($s['repeat']),'cooldown'=>max(0,min(365,(int)($s['cooldown']??1))),'timezone'=>$zone,'recipient_timezone'=>!empty($s['recipient_timezone']),'weekdays'=>$days,'after'=>$s['after']??'00:00','before'=>$s['before']??'23:59','max_days'=>max(1,min(365,(int)($s['max_days']??365)))];
    foreach([$d['trigger']['time'],$d['settings']['after'],$d['settings']['before']]as$time)if(!is_string($time)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new InvalidArgumentException('Choose a valid time.');
    $extra=$raw['additional_triggers']??[];
    if(!is_array($extra)||!array_is_list($extra)||count($extra)>2)throw new InvalidArgumentException('Use up to three starting triggers.');
    $d['additional_triggers']=[];$seen=[json_encode($d['trigger'])];
    foreach($extra as$trigger){
        if(!is_array($trigger))throw new InvalidArgumentException('Choose a valid starting trigger.');
        $normalized=audienceFlowDocument(['trigger'=>$trigger,'nodes'=>[]])['trigger'];
        if(in_array(json_encode($normalized),$seen,true))throw new InvalidArgumentException('Choose different starting triggers.');
        $seen[]=json_encode($normalized);$d['additional_triggers'][]=$normalized;
    }
    if($d['settings']['after']>=$d['settings']['before'])throw new InvalidArgumentException('The delivery window must end after it starts.');
    foreach(['entry','exit']as$key){$rules=$raw[$key]??$d[$key];if(!is_array($rules))throw new InvalidArgumentException('Invalid flow filters.');$d[$key]=$rules;}
    if(count($raw['nodes']??[])>200)throw new InvalidArgumentException('Use at most 200 steps in a flow.');
    foreach($raw['nodes']??[]as$n){
        if(!is_array($n)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D',(string)($n['id']??''))||!isset(AUDIENCE_FLOW_NODES[$n['type']??'']))throw new InvalidArgumentException('Choose a valid flow step.');
        $node=['id'=>$n['id'],'type'=>$n['type'],'label'=>audienceText($n,'label',100)?:AUDIENCE_FLOW_NODES[$n['type']]];
        foreach(['next','yes','no','field','value','event','time']as$key)$node[$key]=audienceText($n,$key,$key==='value'?1000:50);
        foreach(['tag_id','option_id','campaign_id','seconds','timeout','percent']as$key)$node[$key]=max(0,min($key==='percent'?100:31622400,(int)($n[$key]??0)));
        $node['remove']=!empty($n['remove']);$node['rules']=is_array($n['rules']??null)?$n['rules']:['match'=>'all','rules'=>[]];
        if(isset($n['trigger'])){if(!is_array($n['trigger'])||!in_array($n['trigger']['type']??'',['signup','profile','tag','untag','group','ungroup','open','click','survey','flow'],true))throw new InvalidArgumentException('Choose a wait trigger.');$node['trigger']=array_intersect_key($n['trigger'],array_flip(['type','id','field','value']));}
        $d['nodes'][]=$node;
    }
    $d['start']=audienceText($raw,'start',40);$d['classic']=!empty($raw['classic']);
    $map=[];foreach($d['nodes']as$n){if(isset($map[$n['id']]))throw new InvalidArgumentException('Each step needs a unique identity.');$map[$n['id']]=$n;}
    if($d['start']!==''&&!isset($map[$d['start']]))throw new InvalidArgumentException('Choose an available first step.');
    $done=[];$active=[];
    $visit=function(string $id)use(&$visit,&$done,&$active,$map):void{
        if($id==='')return;if(!isset($map[$id]))throw new InvalidArgumentException('A connected step is missing.');
        if(isset($active[$id]))throw new InvalidArgumentException('Flow steps cannot loop. Use repeat settings for reentry.');if(isset($done[$id]))return;
        $active[$id]=true;$n=$map[$id];foreach(in_array($n['type'],['condition','split','wait'],true)?['yes','no']:['next']as$edge)$visit($n[$edge]);unset($active[$id]);$done[$id]=true;
    };
    foreach(array_keys($map)as$id)$visit($id);
    return $d;
}

function audienceFlowWindow(array $settings,string $recipientZone,int $at): int
{
    $zone=new DateTimeZone(!empty($settings['recipient_timezone'])&&in_array($recipientZone,DateTimeZone::listIdentifiers(),true)?$recipientZone:$settings['timezone']);
    $local=(new DateTimeImmutable('@'.$at))->setTimezone($zone);
    for($i=0;$i<15;$i++){
        $date=$local->format('Y-m-d');$start=new DateTimeImmutable($date.' '.$settings['after'],$zone);$end=new DateTimeImmutable($date.' '.$settings['before'],$zone);
        if(in_array((int)$local->format('N'),$settings['weekdays'],true)&&$at<=$end->getTimestamp())return max($at,$start->getTimestamp());
        $local=$local->modify('+1 day')->setTime(0,0);$at=$local->getTimestamp();
    }
    throw new InvalidArgumentException('No available delivery window.');
}

/** Normalize existing native events without accepting anonymous survey addresses as identity. */
function audienceFlowEventMatches(array $trigger,array $event): bool
{
    $type=$trigger['type'];$e=$event['type'];$d=$event['details']??[];$id=(int)($trigger['id']??0);
    return match($type){
        'signup'=>($e==='contact_added'&&($d['status']??'')==='subscribed')||(in_array($e,['subscription_changed','subscription-confirmed','status'],true)&&($d['to']??'')==='subscribed'),
        'profile'=>in_array($e,['contact_updated','preferences','profile_details','profile_settings'],true)&&!empty($d['changed_fields'])&&(($trigger['field']??'')===''||in_array($trigger['field'],$d['changed_fields'],true)),
        'tag','untag'=>$e==='tags_changed'&&in_array($id,$d[$type==='tag'?'added':'removed']??[],true),
        'group','ungroup'=>$e==='groups_changed'&&in_array($id,$d[$type==='group'?'added':'removed']??[],true),
        'open','click'=>$e===$type&&empty($d['bot'])&&(!$id||(int)($d['campaign_id']??0)===$id),
        'survey'=>$e==='survey'&&(!$id||(int)($d['resource_id']??0)===$id),
        'flow'=>$e==='flow_completed'&&(int)($d['flow_id']??0)===$id,
        'manual'=>$e==='manual',default=>false,
    };
}

function audienceFlowClassicDocument(array $input): array
{
    if(!is_array($input['trigger']??null)||!in_array($input['trigger']['type']??'',['signup','tag','birthday','anniversary','date'],true)||!is_array($input['emails']??null)||!$input['emails']||count($input['emails'])>30)throw new InvalidArgumentException('Use a supported classic workflow with a signup, tag or date trigger and up to 30 native emails.');
    $d=audienceFlowDefault();$d['trigger']=$input['trigger'];$d['classic']=true;
    foreach($input['emails']as$i=>$email){if(!is_array($email)||!empty($email['external'])||!empty($email['unsupported']))throw new InvalidArgumentException('Connected or unsupported classic actions cannot be converted.');$n=count($d['nodes']);if((int)($email['delay_seconds']??0)>0)$d['nodes'][]=['id'=>'step'.($n+1),'type'=>'delay','seconds'=>(int)$email['delay_seconds'],'next'=>'step'.($n+2)];$n=count($d['nodes']);$d['nodes'][]=['id'=>'step'.($n+1),'type'=>'email','campaign_id'=>(int)($email['campaign_id']??0),'next'=>$i<count($input['emails'])-1?'step'.($n+2):''];}
    $d['start']=$d['nodes'][0]['id'];return audienceFlowDocument($d);
}
