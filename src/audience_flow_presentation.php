<?php
declare(strict_types=1);
require_once __DIR__.'/audience_flows.php';

/** Local vector artwork shared by the catalog, previews and builder. */
function audienceFlowIconPaths(): array
{
    return [
        'trigger'=>'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-2 5 6 4-6 4Z',
        'email'=>'M3 5h18v14H3ZM3 6l9 7 9-7',
        'delay'=>'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 4v6l4 2',
        'wait'=>'M6 3h12M6 21h12M7 3v5l10 8v5M17 3v5L7 16v5',
        'condition'=>'m12 2 10 10-10 10L2 12ZM12 8v5m0 3v.1',
        'tag'=>'M3 3h8l10 10-8 8L3 11ZM7 7h.1',
        'group'=>'M8 3a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm8 2a3 3 0 1 1 0 6M2 21v-4a6 6 0 0 1 12 0v4m3-8a5 5 0 0 1 5 5v3',
        'archive'=>'M3 3h18v5H3Zm2 5v13h14V8m-10 5h6',
        'exit'=>'M10 3H3v18h7m4-14 5 5-5 5m-7-5h14',
        'forms'=>'M7 4H4v18h16V4h-3M8 2h8v5H8Zm-1 9h10M7 15h10M7 19h6',
        'date'=>'M3 5h18v16H3Zm4-3v6m10-6v6M3 10h18m-13 4h2m4 0h2',
        'update'=>'M16 3 21 8 9 20l-6 1 1-6Zm-3 3 5 5',
        'status'=>'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-5 9 3 3 7-7',
    ];
}

function audienceFlowIcon(string $kind): string
{
    $path=audienceFlowIconPaths()[$kind]??audienceFlowIconPaths()['trigger'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" class="flow-symbol"><path d="'.$path.'"/></svg>';
}

function audienceFlowListOptions(array $input): array
{
    $q=audienceText($input,'q',100);$state=audienceText($input,'state',20);$sort=audienceText($input,'sort',20)?:'created';
    if(!in_array($state,['','draft','running','paused'],true))throw new InvalidArgumentException('Choose an available flow status.');
    if(!in_array($sort,['created','updated','oldest'],true))throw new InvalidArgumentException('Choose an available flow sort order.');
    return ['q'=>$q,'state'=>$state,'sort'=>$sort];
}

/** Setup progress reflects native records, not activity in a vendor account. */
function audienceFlowSetupTasks(array $facts,bool $canConfigureDelivery): array
{
    return [
        'welcome'=>['title'=>'Create a welcome flow','kind'=>'Email flow','complete'=>!empty($facts['welcome']),
            'description'=>'Start with the welcome template, choose an email, and tailor the starting point. The flow stays a draft until you review and turn it on.',
            'action'=>'Use template','target'=>['view'=>'flow-templates','preview'=>'welcome'],'icon'=>'email'],
        'delivery'=>['title'=>'Qualify email delivery','kind'=>'Sending readiness','complete'=>!empty($facts['delivery']),
            'description'=>'Have an administrator review the sender, domain, delivery events and sending approval. Creating drafts does not enable live sending.',
            'action'=>$canConfigureDelivery?'Review email delivery':'Read the sending guide','target'=>$canConfigureDelivery?['view'=>'delivery']:null,'icon'=>'status'],
        'form'=>['title'=>'Collect connections with a signup page','kind'=>'Signup forms','complete'=>!empty($facts['form']),
            'description'=>'Publish a standalone signup page hosted by MOED and share its link. Email confirmation keeps its delivery requirements; a pending signup does not start a subscribed welcome flow.',
            'action'=>'Explore signup forms','target'=>['view'=>'forms'],'icon'=>'forms'],
        'organize'=>['title'=>'Organize your audience','kind'=>'Tags and groups','complete'=>!empty($facts['organize']),
            'description'=>'Use audience tags or groups to express interests and choose who enters a flow. Existing tags and groups can be reused.',
            'action'=>'Manage tags','target'=>['view'=>'tags'],'icon'=>'tag'],
        'library'=>['title'=>'Get inspired by the flow template library','kind'=>'Flow templates','complete'=>false,
            'description'=>'Explore native starters and their actual maps. Review requirements, then add or remove steps in your own draft.',
            'action'=>'Go to library','target'=>['view'=>'flow-templates'],'icon'=>'condition'],
    ];
}

/** Original native artwork; the preview link opens a real draft-template map. */
function audienceFlowWelcomeArtwork(): void
{
    ?><svg class="flow-overview-illustration" viewBox="0 0 360 220" role="img" aria-label="A subscribed signup leads to a welcome email, then an exit"><rect class="flow-art-backdrop" width="360" height="220" rx="12"/><path class="flow-art-line" d="M180 57v108m-4-4 4 4 4-4"/><rect class="flow-art-card" x="112" y="24" width="136" height="40" rx="3"/><rect class="flow-art-card" x="67" y="97" width="226" height="44" rx="3"/><circle class="flow-art-card" cx="180" cy="181" r="12"/><svg x="125" y="35" width="18" height="18" viewBox="0 0 24 24" class="flow-symbol"><path d="<?= audienceFlowIconPaths()['trigger'] ?>"/></svg><svg x="81" y="109" width="20" height="20" viewBox="0 0 24 24" class="flow-symbol"><path d="<?= audienceFlowIconPaths()['email'] ?>"/></svg><text x="151" y="49">Signs up</text><text x="111" y="124">Send welcome email</text><path class="flow-art-line" d="m175 181 4 4 7-8"/></svg><?php
}

function audienceFlowTaskArtwork(string $task): void
{
    if($task==='welcome'){audienceFlowWelcomeArtwork();return;}
    ?><svg class="flow-overview-illustration" viewBox="0 0 360 220" aria-hidden="true"><rect class="flow-art-backdrop" width="360" height="220" rx="8"/><?php
    if($task==='form'){
        ?><rect class="flow-art-card" x="75" y="18" width="210" height="184" rx="5"/><text x="104" y="51">Join our community</text><rect class="flow-art-card" x="101" y="69" width="158" height="28" rx="3"/><text x="110" y="88">Email address</text><rect class="flow-art-card" x="101" y="109" width="13" height="13" rx="2"/><path class="flow-art-line" d="m104 115 3 3 5-6"/><text x="121" y="120" class="flow-art-small">Email permission</text><rect class="flow-art-teal" x="101" y="144" width="158" height="30" rx="3"/><text class="flow-art-white" x="151" y="164">Sign up</text><?php
    }elseif($task==='organize'){
        ?><text x="92" y="36">Audience interests</text><?php foreach([['News',65,64],['Events',175,107],['Volunteers',65,152]]as[$label,$x,$y]): ?><g transform="translate(<?= $x ?> <?= $y ?>)"><path class="flow-art-card" d="M0 0h100l18 16-18 16H0Z"/><circle class="flow-art-dot" cx="12" cy="16" r="3"/><text x="24" y="21"><?= $label ?></text></g><?php endforeach;
    }elseif($task==='library'){
        ?><text x="111" y="31">Flow templates</text><?php foreach([[70,50],[190,50],[70,128],[190,128]]as[$x,$y]): ?><g transform="translate(<?= $x ?> <?= $y ?>)"><rect class="flow-art-card" width="100" height="62" rx="4"/><path class="flow-art-line" d="M22 13h56M22 30h56M50 35v13"/><rect class="flow-art-teal" x="36" y="44" width="28" height="7" rx="2"/></g><?php endforeach;
    }else{
        ?><path class="flow-art-line" d="M180 50v120M115 105h130"/><rect class="flow-art-card" x="105" y="54" width="150" height="104" rx="5"/><path class="flow-art-line" d="m105 55 75 58 75-58"/><circle class="flow-art-teal" cx="180" cy="166" r="22"/><path class="flow-art-white-line" d="M170 166h20m-7-7 7 7-7 7"/><text x="126" y="210">Review delivery</text><?php
    }
    ?></svg><?php
}

function audienceFlowTriggerLabel(array $trigger): string
{
    if($trigger['type']==='status'&&($trigger['value']??'')!=='')return 'Changes to '.$trigger['value'];
    return AUDIENCE_FLOW_TRIGGERS[$trigger['type']]??'Starting point';
}

/** Derive the preview from the same graph that Use this flow creates. */
function audienceFlowPreviewGraph(array $document): array
{
    $d=audienceFlowDocument($document);$cards=[];$edges=[];$rank=[];$nodes=array_column($d['nodes'],null,'id');
    $triggers=[$d['trigger'],...$d['additional_triggers']];
    foreach($triggers as$i=>$trigger){$id='trigger-'.$i;$cards[$id]=['id'=>$id,'kind'=>'trigger','label'=>audienceFlowTriggerLabel($trigger),'category'=>'Starting point'];$rank[$id]=0;$edges[]=['from'=>$id,'to'=>$d['start'],'label'=>''];}
    foreach($nodes as$id=>$node){$cards[$id]=['id'=>$id,'kind'=>$node['type'],'label'=>$node['label'],'category'=>AUDIENCE_FLOW_NODES[$node['type']]];$rank[$id]=1;
        if($node['type']==='delay')$cards[$id]['label']=($node['seconds']%86400===0?($node['seconds']/86400).' day'.($node['seconds']===86400?'':'s'):($node['seconds']/60).' minutes').' delay';
        foreach(in_array($node['type'],['condition','split','wait'],true)?['yes','no']:($node['type']==='exit'?[]:['next'])as$edge)$edges[]=['from'=>$id,'to'=>$node[$edge],'label'=>$edge==='next'?'':($node['type']==='wait'?($edge==='yes'?'Trigger matched':'Timed out'):ucfirst($edge))];
    }
    foreach($edges as$i=>&$edge)if($edge['to']===''){$edge['to']='exit-'.$i;$cards[$edge['to']]=['id'=>$edge['to'],'kind'=>'exit','label'=>'Connection exits','category'=>'End of this path'];$rank[$edge['to']]=1;}unset($edge);
    for($pass=0;$pass<=count($cards);$pass++){$changed=false;foreach($edges as$edge){$next=$rank[$edge['from']]+1;if($rank[$edge['to']]<$next){$rank[$edge['to']]=$next;$changed=true;}}if(!$changed)break;}
    $rows=[];foreach($cards as$id=>$card)$rows[$rank[$id]][]=$id;
    $width=max(900,...array_map(static fn($row)=>count($row)*350+60,$rows));$height=(max($rank)+1)*148+60;
    foreach($rows as$row=>$ids)foreach($ids as$i=>$id){$cards[$id]['x']=($width-count($ids)*350)/2+$i*350+25;$cards[$id]['y']=30+$row*148;}
    return ['cards'=>array_values($cards),'edges'=>$edges,'width'=>$width,'height'=>$height];
}

function audienceFlowTemplateCard(string $key,array $template,callable $url,bool $featured=false): void
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$email=in_array('email',$template['steps'],true);
    $integrationNames=implode(' ',array_map(static fn($key)=>$key==='forms'?'MOED forms':'MessageMonkey audience',$template['integrations']));
    ?>
    <article class="flow-template-card<?= $featured?' flow-template-featured':'' ?>" data-template-key="<?= $h($key) ?>" data-flow-template="<?= $h(mb_strtolower($template['name'].' '.$template['description'].' '.$template['topic'].' '.$integrationNames)) ?>" data-template-name="<?= $h($template['name']) ?>" data-recommended="<?= (int)$template['recommended'] ?>" data-topic="<?= $h($template['topic']) ?>" data-channel="<?= $email?'email':'data' ?>" data-integrations="<?= $h(implode(' ',$template['integrations'])) ?>">
        <div class="flow-card-top"><ul class="flow-integration-coins" aria-label="Channels and native integrations">
            <?php if($email): ?><li title="Email"><?= audienceFlowIcon('email') ?><span class="sr-only">Email</span></li><?php endif; ?>
            <li title="MessageMonkey audience"><?= audienceFlowIcon('group') ?><span class="sr-only">MessageMonkey audience</span></li>
            <?php if(in_array('forms',$template['integrations'],true)): ?><li title="MOED forms"><?= audienceFlowIcon('forms') ?><span class="sr-only">MOED forms</span></li><?php endif; ?>
        </ul><?php if($template['recommended']): ?><span class="flow-card-badge">Recommended</span><?php endif; ?></div>
        <h3><a href="<?= $h($url(['view'=>'flow-templates','preview'=>$key])) ?>"><?= $h($template['name']) ?></a></h3>
        <?php if($featured): ?><p><?= $h($template['description']) ?></p><?php endif; ?>
    </article>
    <?php
}
