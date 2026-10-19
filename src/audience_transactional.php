<?php
declare(strict_types=1);

/** Account-isolated, metadata-only views of existing MOED mail queues. */
function audienceTransactionalSources(): array
{
    return [
        'confirmation'=>['label'=>'Signup confirmations','table'=>'marketing_service_mail','state'=>'state','purpose'=>"'Email permission confirmation'",'where'=>"audience_id=? AND kind='confirmation'",'transport'=>'SES'],
        'inbox'=>['label'=>'Inbox replies and alerts','table'=>'marketing_service_mail','state'=>'state','purpose'=>"CASE kind WHEN 'inbox' THEN 'One-to-one Inbox reply' ELSE 'Inbox notification' END",'where'=>"audience_id=? AND kind IN ('inbox','inbox-alert')",'transport'=>'SES'],
        'audience-alert'=>['label'=>'Audience notifications','table'=>'marketing_service_mail','state'=>'state','purpose'=>"'Audience activity notification'",'where'=>"audience_id=? AND kind='audience-alert'",'transport'=>'SES'],
        'account'=>['label'=>'Account access emails','table'=>'email_outbox','state'=>'status','purpose'=>"'Invitation, verification or recovery'",'where'=>'1=1','transport'=>'SMTP'],
        'digest'=>['label'=>'Task digests','table'=>'notification_outbox','state'=>'status','purpose'=>"'Daily task digest'",'where'=>'1=1','transport'=>'SMTP'],
        'engagement'=>['label'=>'Engagement correspondence','table'=>'engagement_email_deliveries','state'=>'status','purpose'=>"'Engagement correspondence'",'where'=>'1=1','transport'=>'SMTP'],
        'reimbursement'=>['label'=>'Reimbursement submissions','table'=>'reimbursement_email_deliveries','state'=>'status','purpose'=>"'Reimbursement submission'",'where'=>'1=1','transport'=>'SMTP'],
    ];
}

function audienceTransactionalFilters(array $input): array
{
    $source=audienceText($input,'source',30);$state=audienceText($input,'state',20);$days=audienceText($input,'days',3)?:'30';
    if($source!==''&&!isset(audienceTransactionalSources()[$source]))throw new InvalidArgumentException('Choose a supported email source.');
    if(!in_array($state,['','queued','processing','accepted','failed','uncertain','cancelled','development'],true))throw new InvalidArgumentException('Choose a supported email status.');
    if(!in_array($days,['7','30','90'],true))throw new InvalidArgumentException('Choose a supported activity window.');
    return ['source'=>$source,'state'=>$state,'days'=>(int)$days,'page'=>max(1,min(10000,(int)($input['page']??1)))];
}

function audienceTransactionalActivity(AudienceStore $store,array $filters): array
{
    $sources=audienceTransactionalSources();$tables=array_column($store->all('SELECT table_name AS name FROM information_schema.tables WHERE table_schema=DATABASE()'),'name');
    $parts=[];$params=[];$unavailable=[];
    foreach($sources as$key=>$source){
        if($filters['source']!==''&&$filters['source']!==$key)continue;
        if(!in_array($source['table'],$tables,true)){$unavailable[]=$source['label'];continue;}
        // Only static identifiers and expressions enter SQL. Never select/decrypt payloads,
        // addresses, bearer links, credentials, SMTP errors or provider message identifiers.
        $state=$source['state'];$normalized="CASE $state WHEN 'pending' THEN 'queued' WHEN 'retry' THEN 'queued' WHEN 'sent' THEN 'accepted' WHEN 'delivery_uncertain' THEN 'uncertain' ELSE $state END";
        // Account access and digest queues deliberately grant the web app only
        // status/timing columns. Keep that boundary; do not widen their grants.
        $id=in_array($key,['account','digest'],true)?'NULL':'id';$attempts=in_array($key,['account','digest'],true)?'NULL':'attempts';
        $parts[]="SELECT $id id,created_at,$attempts attempts,'$key' source,{$source['purpose']} purpose,$normalized state FROM {$source['table']} WHERE {$source['where']} AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY)";
        if($source['table']==='marketing_service_mail')$params[]=$store->audienceId;$params[]=$filters['days'];
    }
    if(!$parts)return ['rows'=>[],'totals'=>[],'total'=>0,'pages'=>1,'page'=>1,'unavailable'=>$unavailable];
    $union=implode(' UNION ALL ',$parts);
    $totals=array_map('intval',array_column($store->all("SELECT state,COUNT(*) n FROM ($union) activity GROUP BY state",$params),'n','state'));
    $total=$filters['state']!==''?($totals[$filters['state']]??0):array_sum($totals);$pages=max(1,(int)ceil($total/25));$page=min($filters['page'],$pages);
    $where=$filters['state']!==''?' WHERE state=?':'';$rowParams=$params;if($filters['state']!=='')$rowParams[]=$filters['state'];$rowParams[]=($page-1)*25;
    $rows=$store->all("SELECT * FROM ($union) activity$where ORDER BY created_at DESC,source,id DESC LIMIT 25 OFFSET ?",$rowParams);
    return compact('rows','totals','total','pages','page','unavailable');
}

function renderAudienceTransactional(AudienceStore $store,array $audience,bool $canManage,bool $canDelete,string $displayName,int $actor): void
{
    if(!$canDelete){http_response_code(403);exit('Transactional activity requires an administrator.');}
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit('Transactional activity is read-only.');}
    header('Cache-Control: no-store');
    require_once __DIR__.'/audience_flow_presentation.php';
    $view='transactional';$pageTitle='Transactional';$id=0;$message='';$error='';$audienceId=$store->audienceId;
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$url=static fn(array $p=[])=>'audience.php'.($p?'?'.http_build_query($p):'');
    try{$transactionalFilters=audienceTransactionalFilters($_GET);}catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();$transactionalFilters=audienceTransactionalFilters([]);}
    $transactionalActivity=audienceTransactionalActivity($store,$transactionalFilters);$transactionalSources=audienceTransactionalSources();
    $tags=[];$groups=[];$fields=[];$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=null;$job=null;$editorRecovery=null;$csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
    // A stored snapshot is informative only. This page never calls a transport or
    // changes readiness; the worker still enforces every existing sending gate.
    $sesStatus=$store->sesAvailable()?$store->one('SELECT region,production_access,sending_enabled,check_state,checked_at FROM marketing_ses_settings WHERE audience_id=?',[$audienceId]):null;
    $bulkApproved=$store->deliveryRuntimeAvailable()?(bool)($store->one('SELECT bulk_approved FROM marketing_delivery_policy WHERE audience_id=?',[$audienceId])['bulk_approved']??false):false;
    releaseApplicationSessionLock();
    ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-flows.min.css'],'scripts'=>['assets/js/audience.min.js']]);include __DIR__.'/templates/audience/workspace.php';?></html><?php
}
