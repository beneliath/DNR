<?php
declare(strict_types=1);

const AUDIENCE_FLOW_TRIGGERS = [
    'signup'=>'Signs up','profile'=>'Audience field changes','tag'=>'Tag added','untag'=>'Tag removed',
    'group'=>'Joins group','ungroup'=>'Leaves group','date'=>'Date','birthday'=>'Birthday',
    'anniversary'=>'Signup anniversary','open'=>'Opens email','click'=>'Clicks email',
    'survey'=>'Responds to survey','flow'=>'Completes flow','status'=>'Email subscription status changes','manual'=>'Added manually',
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
    $make=static function(string $name,string $description,string $trigger,array $steps,string $family,string $topic,array $extra=[]):array{
        return array_replace(['name'=>$name,'description'=>$description,'trigger'=>$trigger,'steps'=>$steps,'family'=>$family,'topic'=>$topic,
            'objective'=>match($family){'welcome'=>'Welcome new connections','nurture'=>'Nurture relationships','reengage'=>'Re-engage connections',default=>'Manage connections'},
            'integrations'=>in_array($trigger,['signup','survey'],true)?['audience','forms']:['audience'],
            'recommended'=>false,'requirements'=>array_merge(match($trigger){
                'tag','untag'=>['Choose the audience tag that starts this flow.'],
                'group','ungroup'=>['Choose the audience group that starts this flow.'],
                'birthday','date'=>['Choose a birthday or date field and check the local delivery time.'],
                'anniversary'=>['Uses the connection’s creation date in this MessageMonkey audience.'],
                'flow'=>['Choose an existing source flow.'],
                'open','click'=>['Choose a source email, or explicitly watch any email.'],
                'survey'=>['Choose a survey, or explicitly watch any verified survey response.'],
                'profile'=>['Choose the audience field to watch, or explicitly watch all profile changes.'],
                default=>[]},
                in_array('condition',$steps,true)?['Set the audience conditions for the Yes and No paths.']:[],
                in_array('tag',$steps,true)?['Choose the tag to apply.']:[],
                in_array('email',$steps,true)?['Choose the email designs in the builder.','Email requires a subscribed connection and qualified delivery.']:[]),
            'summary'=>array_map(static fn($step)=>AUDIENCE_FLOW_NODES[$step],$steps)],$extra);
    };
    return [
        'welcome'=>$make('Welcome new connections','Increase engagement from new subscribers with a personal hello.','signup',['email'],'welcome','General',[
            'recommended'=>true,'summary'=>['Start only after a connection becomes subscribed.','Send an introductory welcome email.'],'node_settings'=>[['label'=>'Welcome email']]]),
        'series'=>$make('Welcome series','Introduce your community with a sequence of useful, well-spaced emails.','signup',['email','delay','email','delay','email'],'welcome','General',[
            'summary'=>['Welcome a new subscriber.','Wait one day before sharing your story.','Wait three more days before inviting the next step.'],
            'node_settings'=>[['label'=>'Welcome to our community'],['seconds'=>86400],['label'=>'Our story'],['seconds'=>259200],['label'=>'Your next step']]]),
        'tagged'=>$make('Email tagged connections','Send a relevant follow-up when a selected tag is added.','tag',['email'],'nurture','Contact management',[
            'recommended'=>true,'summary'=>['Listen for a newly added tag.','Send the email associated with that interest.']]),
        'birthday'=>$make('Celebrate a birthday','Send a thoughtful message on each connection’s birthday.','birthday',['email'],'nurture','Special dates',[
            'settings'=>['repeat'=>true],'summary'=>['Use a birthday field and the connection’s time zone.','Allow another birthday greeting next year.']]),
        'anniversary'=>$make('Celebrate signup anniversaries','Recognize another year in your community.','anniversary',['email'],'nurture','Special dates',[
            'settings'=>['repeat'=>true],'summary'=>['Use the connection’s creation date in this audience.','Send an annual anniversary email.']]),
        'survey'=>$make('Thank connections for answering a survey','Follow up after feedback from a verified connection.','survey',['delay','email'],'manage','Feedback',[
            'summary'=>['Use a response tied to a verified recipient.','Wait one day, then send a personal thank-you.']]),
        'engaged'=>$make('Identify connections who click','Organize your audience using observed email engagement.','click',['tag'],'manage','Contact management',[
            'summary'=>['Listen for a tracked click, excluding recognized bots.','Add the engagement tag you choose.']]),
        'split'=>$make('Guide connections with If/Else conditions','Send different follow-ups according to audience information.','tag',['condition','email','email'],'nurture','Contact management',[
            'summary'=>['Start when a selected tag is added.','Check your audience conditions.','Send the matching Yes or No email, then exit.'],
            'node_settings'=>[['label'=>'Meets your audience conditions','yes'=>'step2','no'=>'step3'],['label'=>'Yes-path email','next'=>''],['label'=>'No-path email']]]),
        'group-welcome'=>$make('Welcome new group members','Acknowledge a connection joining an audience group.','group',['email'],'welcome','General',[
            'summary'=>['Listen for a connection joining the selected group.','Send a welcome relevant to that group.']]),
        'welcome-tag'=>$make('Organize new subscribers','Apply a welcome tag before introducing your community.','signup',['tag','email'],'welcome','Contact management',[
            'summary'=>['Wait for confirmed email permission.','Add your onboarding tag and send a welcome.']]),
        'profile-followup'=>$make('Follow up when an audience field changes','Respond to a real profile update with a relevant email.','profile',['email'],'nurture','Contact management',[
            'summary'=>['Choose the audience field to watch.','Send an email when that field actually changes.']]),
        'date-reminder'=>$make('Remind connections about a date','Send a reminder before an appointment or event date stored in the audience.','date',['email'],'nurture','Events',[
            'trigger_settings'=>['offset'=>-1],'summary'=>['Read a date field on the connection’s profile.','Send one day before, at the chosen local time.'],
            'requirements'=>['Choose an audience date field; this does not infer MOED event attendance.','Choose an email and qualify delivery.']]),
        'followup'=>$make('Follow up when a welcome email gets no click','Give new subscribers a second chance to engage without emailing those who already responded.','signup',['email','wait','email'],'reengage','General',[
            'summary'=>['Send the welcome email.','Wait up to three days for a tracked click.','Exit on a click; otherwise send one reminder.'],
            'requirements'=>['Choose the welcome email and the same email in the click-wait step.','Choose a reminder email and qualify delivery.'],
            'node_settings'=>[['label'=>'Welcome email'],['label'=>'Wait for a welcome-email click','trigger'=>['type'=>'click','id'=>0],'timeout'=>259200,'yes'=>'','no'=>'step3'],['label'=>'Welcome reminder']]]),
        'group-feedback'=>$make('Ask why a connection left a group','Invite feedback from connections who leave an interest group while retaining email permission.','ungroup',['email'],'reengage','Feedback',[
            'summary'=>['Listen for removal from the selected group.','Ask for feedback only if email permission is still present.']]),
        'flow-followup'=>$make('Continue after another flow','Connect your onboarding or follow-up journeys using native flow completion.','flow',['delay','email'],'reengage','General',[
            'summary'=>['Choose the source flow.','After it finishes, wait one day and send the next message.']]),
        'opened'=>$make('Identify connections who open','Apply an audience tag when a selected email is observed as opened.','open',['tag'],'manage','Contact management',[
            'summary'=>['Listen for an observed open with recognized bots excluded.','Add your selected tag; privacy proxy opens may still be counted.']]),
        'cleanup'=>$make('Archive unsubscribed or cleaned connections','Keep the audience organized when email permission is removed or an address is cleaned.','status',['archive'],'manage','Contact management',[
            'recommended'=>true,'trigger_settings'=>['value'=>'unsubscribed'],'additional_triggers'=>[['type'=>'status','value'=>'cleaned']],
            'entry'=>['match'=>'any','rules'=>[['field'=>'email_status','operator'=>'is','value'=>'unsubscribed'],['field'=>'email_status','operator'=>'is','value'=>'cleaned']]],
            'exit'=>['match'=>'all','rules'=>[['field'=>'email_status','operator'=>'is_not','value'=>'unsubscribed'],['field'=>'email_status','operator'=>'is_not','value'=>'cleaned']]],
            'summary'=>['Listen for a change to unsubscribed or cleaned.','Archive that connection without changing consent or deleting their history.'],
            'requirements'=>['Review the two status triggers and current-status entry/exit filters.','Only archives connections who are still unsubscribed or cleaned; this does not send email.']]),
    ];
}

function audienceFlowDefault(string $template='blank'): array
{
    $starter=audienceFlowTemplates()[$template]??null;
    $nodes=[];$types=$starter['steps']??[];
    foreach($types as $i=>$type)$nodes[]=array_replace(['id'=>'step'.($i+1),'type'=>$type,'label'=>AUDIENCE_FLOW_NODES[$type],'next'=>isset($types[$i+1])?'step'.($i+2):'','yes'=>isset($types[$i+1])?'step'.($i+2):'','no'=>'','seconds'=>86400,'percent'=>50],$starter['node_settings'][$i]??[]);
    return ['trigger'=>array_replace(['type'=>$starter['trigger']??'manual','id'=>0,'field'=>'','value'=>'','offset'=>0,'time'=>'09:00'],$starter['trigger_settings']??[]),
        'additional_triggers'=>$starter['additional_triggers']??[],
        'settings'=>array_replace(['repeat'=>false,'cooldown'=>1,'timezone'=>'America/Chicago','recipient_timezone'=>true,'weekdays'=>[1,2,3,4,5,6,7],'after'=>'00:00','before'=>'23:59','max_days'=>365],$starter['settings']??[]),
        'entry'=>$starter['entry']??['match'=>'all','rules'=>[]],'exit'=>$starter['exit']??['match'=>'all','rules'=>[]],
        'start'=>$nodes[0]['id']??'','nodes'=>$nodes,'classic'=>false];
}

/** An acyclic map gives each effect a stable (run,node) identity. Incomplete drafts remain editable. */
function audienceFlowDocument(array $raw): array
{
    $d=audienceFlowDefault();$t=$raw['trigger']??[];$s=$raw['settings']??[];
    if(!is_array($t)||!is_array($s)||!is_array($raw['nodes']??[]))throw new InvalidArgumentException('Invalid flow document.');
    if(!isset(AUDIENCE_FLOW_TRIGGERS[$t['type']??'manual']))throw new InvalidArgumentException('Choose a native flow trigger.');
    $d['trigger']=['type'=>$t['type']??'manual','id'=>max(0,(int)($t['id']??0)),'field'=>audienceText($t,'field',50),'value'=>audienceText($t,'value',300),'offset'=>max(-365,min(365,(int)($t['offset']??0))),'time'=>audienceText($t,'time',5)?:'09:00'];
    if($d['trigger']['type']==='status'&&!in_array($d['trigger']['value'],['',...AUDIENCE_STATUSES],true))throw new InvalidArgumentException('Choose an email subscription status.');
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
        'status'=>in_array($e,['subscription_changed','subscription-confirmed','status'],true)&&isset($d['to'])&&($d['from']??null)!==$d['to']&&in_array($d['to'],AUDIENCE_STATUSES,true)&&(($trigger['value']??'')===''||$trigger['value']===$d['to']),
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
