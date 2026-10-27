<?php
declare(strict_types=1);

/** Suggestions come only from authorized MessageMonkey rows already loaded for this view. */
function audienceSearchOptions(array $values): string
{
    $options=[];
    foreach($values as $value) {
        if(!is_string($value))continue;
        $value=trim($value);
        if($value===''||mb_strlen($value)>320)continue;
        $key=mb_strtolower($value);
        if(isset($options[$key]))continue;
        $options[$key]='<option value="'.htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"></option>';
        if(count($options)===100)break;
    }
    return implode('',$options);
}

function audienceSearchCategories(): array
{
    return ['connections'=>'Connections','campaigns'=>'Campaigns','flows'=>'Flows','pages'=>'Pages','actions'=>'Quick actions','help'=>'Help articles'];
}

/** Only existing destinations are searchable; editor actions remain permission-scoped. */
function audienceSearchCatalog(bool $canManage,bool $canAccessDelivery=false): array
{
    $pages=[];
    foreach(['home'=>'Home','analytics'=>'Analytics','contacts'=>'Connections','campaigns'=>'Campaigns','campaign-manager'=>'Campaign Manager','templates'=>'Email templates','tags'=>'Tags','segments'=>'Segments','content'=>'Content Library','brand'=>'Brand Kit','groups'=>'Groups','fields'=>'Fields and merge tags','settings'=>'Audience settings','manual'=>'User Manual'] as $view=>$title) {
        $pages[]=['title'=>$title,'description'=>'MessageMonkey workspace','href'=>'audience.php?view='.$view,'icon'=>'page'];
    }
    foreach(['automations'=>'Automations','flow-templates'=>'Flow templates','forms'=>'Forms','surveys'=>'Surveys','landing-pages'=>'Landing pages','preferences'=>'Subscriber preferences'] as $view=>$title)$pages[]=['title'=>$title,'description'=>'MessageMonkey workspace','href'=>'audience.php?view='.$view,'icon'=>'page'];
    if($canManage) {
        foreach(['inbox'=>'Inbox','import'=>'Import connections','jobs'=>'Import history'] as $view=>$title) {
            $pages[]=['title'=>$title,'description'=>'MessageMonkey workspace','href'=>'audience.php?view='.$view,'icon'=>'page'];
        }
    }
    if($canAccessDelivery)$pages[]=['title'=>'Email delivery','description'=>'MessageMonkey workspace','href'=>'audience.php?view=delivery','icon'=>'page'];
    $actions=$canManage?[
        ['title'=>'Add a connection','description'=>'Add one person to your audience.','href'=>'audience.php?view=contact','icon'=>'action'],
        ['title'=>'Import connections','description'=>'Upload a file or paste connections.','href'=>'audience.php?view=import','icon'=>'action'],
        ['title'=>'Create an email','description'=>'Start a regular or plain-text email.','href'=>'audience.php?view=campaigns&create=1','icon'=>'action'],
        ['title'=>'Build a flow','description'=>'Create a native automation flow.','href'=>'audience.php?view=automations&create=1','icon'=>'action'],
        ['title'=>'Manage tags','description'=>'Organize your audience with tags.','href'=>'audience.php?view=tags','icon'=>'action'],
        ['title'=>'Manage segments','description'=>'Find connections that meet your criteria.','href'=>'audience.php?view=segments','icon'=>'action'],
    ]:[];
    return ['pages'=>$pages,'actions'=>$actions,'help'=>[
        ['title'=>'MessageMonkey user manual','description'=>'Campaigns, connections, forms, automations, analytics, AI, and troubleshooting.','href'=>'audience.php?view=manual','icon'=>'help'],
        ['title'=>'MOED user manual','description'=>'Getting oriented and understanding roles and access.','href'=>'help.php#roles','icon'=>'help'],
        ['title'=>'Verify an Amazon SES sending domain','description'=>'AWS guide to sending identities and domain authentication.','href'=>'https://docs.aws.amazon.com/ses/latest/dg/creating-identities.html','icon'=>'help','external'=>true],
        ['title'=>'Set up SES and SNS delivery events','description'=>'AWS guide to delivery, bounce and complaint notifications.','href'=>'https://docs.aws.amazon.com/ses/latest/dg/monitor-sending-activity-using-notifications-sns.html','icon'=>'help','external'=>true],
    ]];
}

/** Bounded, read-only search of the current account's canonical audience. */
function audienceWorkspaceSearch(AudienceStore $store,array $input,bool $canManage,bool $canAccessDelivery=false): array
{
    $query=audienceText($input,'q',200);$category=audienceText($input,'category',30);
    $labels=audienceSearchCategories();
    if($category!==''&&!isset($labels[$category]))throw new InvalidArgumentException('Choose an available search filter.');
    $groups=[];$wanted=static fn(string $key):bool=>$category===''||$category===$key;
    if($query!==''&&$wanted('connections')) {
        $people=$store->all("SELECT id,email,first_name,last_name,email_status FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL AND is_archived=0 AND (LOCATE(?,email)>0 OR LOCATE(?,CONCAT(first_name,' ',last_name))>0) ORDER BY email,id LIMIT 10",[$store->audienceId,$query,$query]);
        $items=[];
        foreach($people as $person) {
            $name=trim($person['first_name'].' '.$person['last_name']);
            $items[]=['title'=>$person['email'],'description'=>($name!==''?$name.' · ':'').ucfirst($person['email_status']),'href'=>'audience.php?view=contact&id='.(int)$person['id'],'icon'=>'person'];
        }
        $groups[]=['key'=>'connections','label'=>$labels['connections'],'items'=>$items,'more'=>'audience.php?q='.rawurlencode($query)];
    }
    if($wanted('flows')&&($query!==''||$category==='flows')&&$store->flowAvailable()){
        $rows=$store->all('SELECT id,name,state,updated_at FROM marketing_flows WHERE audience_id=? AND deleted_at IS NULL'.($query!==''?' AND LOCATE(?,name)>0':'').' ORDER BY updated_at DESC,id DESC LIMIT 10',$query!==''?[$store->audienceId,$query]:[$store->audienceId]);
        $groups[]=['key'=>'flows','label'=>'Flows','items'=>array_map(static fn($row)=>['title'=>$row['name'],'description'=>'Automation flow','status'=>$row['state'],'updated_at'=>$row['updated_at'],'href'=>'audience.php?view=flow&id='.(int)$row['id'],'icon'=>'action'],$rows)];
    }
    if($wanted('campaigns')) {
        $rows=$store->all("SELECT id,name,campaign_type,status,updated_at,JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.experiment.kind')) experiment_kind FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL".($query!==''?' AND LOCATE(?,name)>0':'').' ORDER BY updated_at DESC,id DESC LIMIT '.($query===''?'5':'10'),$query!==''?[$store->audienceId,$query]:[$store->audienceId]);
        $items=[];
        foreach($rows as $row) {
            $type=$row['experiment_kind']==='ab'?'Historical email':($row['campaign_type']==='plain-text'?'Plain-text email':'Regular email');
            $items[]=['title'=>$row['name'],'description'=>$type,'updated_at'=>$row['updated_at'],'status'=>$row['status'],'href'=>'audience.php?view=campaign&id='.(int)$row['id'],'icon'=>'campaign'];
        }
        $groups[]=['key'=>'campaigns','label'=>$query===''?'Recent campaigns':$labels['campaigns'],'items'=>$items,'more'=>mb_strlen($query)<=100?'audience.php?view=campaigns&search='.rawurlencode($query):null];
    }
    if($query!==''||$category!=='') {
        foreach(audienceSearchCatalog($canManage,$canAccessDelivery) as $key=>$items) {
            if(!$wanted($key))continue;
            $items=array_values(array_filter($items,static fn(array $item):bool=>$query===''||mb_stripos($item['title'].' '.$item['description'],$query)!==false));
            $groups[]=['key'=>$key,'label'=>$labels[$key],'items'=>$items];
        }
    }
    return ['query'=>$query,'category'=>$category,'groups'=>$groups];
}
