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
