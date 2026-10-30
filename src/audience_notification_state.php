<?php
declare(strict_types=1);

function audienceBellSyncAvailable(AudienceStore $store): bool
{
    static $available=[];$id=spl_object_id($store->db);
    return $available[$id]??=$store->one("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_notification_dismissals'")!==null;
}

/** Stable keys are shared by the bell, polling, and legacy browser migration. */
function audienceBellItems(AudienceStore $store): array
{
    $items=[];
    foreach($store->inboxAvailable()?$store->inboxNotifications():[] as $row)
        $items[]=['key'=>hash('sha256','inbox:'.$row['id']),'type'=>'inbox','record'=>$row];
    foreach($store->subscriberNotifications() as $row)
        $items[]=['key'=>hash('sha256','subscriber:'.$row['kind'].':'.($row['id']??$row['created_at'])),'type'=>'subscriber','record'=>$row];
    foreach($store->userNotifications() as $row)
        $items[]=['key'=>hash('sha256','job:'.$row['id'].':'.$row['state'].':'.$row['updated_at']),'type'=>'job','record'=>$row];
    foreach($items as &$item){
        $row=$item['record'];
        if($item['type']==='inbox'){
            $item['label']=$row['kind']==='comment'?'New internal Inbox comment':'New Inbox message';
            $item['description']='Open conversation';$item['href']='audience.php?view=inbox&thread='.(int)$row['thread_id'];
            $item['time']=formatUserTimestamp($row['created_at'],'Y-m-d H:i:s');
        }elseif($item['type']==='subscriber'){
            $item['label']=$row['kind']==='digest'?'Daily audience summary':($row['kind']==='signup'?'New audience subscription':'Audience unsubscribe');
            $item['description']=$row['kind']==='digest'?(int)$row['signup'].' subscriptions · '.(int)$row['unsubscribe'].' unsubscribes':'Review connection activity';
            $item['href']=$row['kind']==='digest'?'audience.php?view=analytics&section=audience':'audience.php?view=contact&id='.(int)$row['contact_id'];
            $item['time']=formatUserTimestamp($row['created_at'],'Y-m-d H:i:s');
        }else{
            $item['label']=ucfirst($row['job_type']).' '.match($row['state']){'complete'=>'complete','failed'=>'needs attention','processing'=>'in progress','queued'=>'queued',default=>'updated'};
            $item['description']=$row['name'];$item['href']='audience.php?view=job&id='.(int)$row['id'];
            $item['time']=formatUserTimestamp($row['updated_at'],'M j, Y, g:i A').((int)$row['errors']>0?' · '.(int)$row['errors'].' row errors':'');
        }
        unset($item['record'],$item['type']);
    }
    unset($item);
    return $items;
}

/** Additive writes cannot undo a dismissal made concurrently on another device. */
function audienceDismissBellItems(AudienceStore $store,array $keys): void
{
    if(count($keys)>500)throw new InvalidArgumentException('Too many notification keys.');
    foreach($keys as $key)if(!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new InvalidArgumentException('Invalid notification key.');
    // Only notifications visible to this authenticated user can be dismissed.
    $allowed=array_column(audienceBellItems($store),'key');
    $keys=array_values(array_intersect(array_unique($keys),$allowed));
    $store->transaction(function()use($store,$keys):void{
        foreach($keys as $key)$store->write('INSERT IGNORE INTO marketing_notification_dismissals(audience_id,user_id,notification_key) VALUES (?,?,?)',[$store->audienceId,$store->actorId,$key]);
    });
}

function audienceBellDismissals(AudienceStore $store): array
{
    if(!audienceBellSyncAvailable($store))return [];
    return array_column($store->all('SELECT notification_key FROM marketing_notification_dismissals WHERE audience_id=? AND user_id=?',[$store->audienceId,$store->actorId]),'notification_key');
}

function audienceBellState(AudienceStore $store): array
{
    $items=audienceBellItems($store);$keys=array_column($items,'key');
    return ['items'=>$items,'cleared'=>array_values(array_intersect($keys,audienceBellDismissals($store)))];
}
