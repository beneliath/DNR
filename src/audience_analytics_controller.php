<?php
declare(strict_types=1);

function renderAudienceAnalytics(AudienceStore $store,array $audience,string $view,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor):void
{
    $audienceId=$store->audienceId;$id=0;$savedReport=null;
    $h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $url=static fn(array $p=[]):string=>'audience.php'.($p?'?'.http_build_query($p):'');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        requireValidCsrfToken();if(!$canManage){http_response_code(403);exit('Saving reports requires an editor or administrator.');}
        try{
            if($view!=='analytics'||audienceText($_POST,'action',30)!=='analytics_report')throw new InvalidArgumentException('Reports do not support this action.');
            $key=$store->analyticsSaveReport($_POST,$canDelete);$_SESSION['audience_message']='Report updated.';
            header('Location: '.$url(['view'=>'analytics','section'=>in_array($_POST['report_action']??'',['delete','favorite'],true)?'reports':'custom','report'=>$key]),true,303);return;
        }catch(InvalidArgumentException $e){http_response_code($e->getCode()===403?403:400);$error=$e->getMessage();}
    }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET, POST');exit('Unsupported report method.');}
    $reportInput=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET;
    $reportKey=audienceText($reportInput,'report',32);$section=audienceText($reportInput,'section',20)?:'email';
    if(!in_array($section,['email','audience','conversions','reports','custom','activity','links','devices','insights'],true)){http_response_code(400);$error='Choose a supported report.';$section='email';}
    if($reportKey!==''&&$section==='custom'){
        try{
            $savedReport=$store->analyticsSavedReport($reportKey,$canDelete);$base=$savedReport['definition'];
            if(isset($reportInput['dataset'])&&$reportInput['dataset']!==$base['dataset'])$base=array_diff_key($base,array_flip(['columns','plots','dimension']));
            $reportInput=array_replace($base,$reportInput);
        }
        catch(InvalidArgumentException $e){http_response_code(403);exit($h($e->getMessage()));}
    }
    $presets=audienceAnalyticsPresets($view==='home');
    $rangeKey=audienceText($reportInput,'range',20)?:((isset($reportInput['start'])||isset($reportInput['end']))?'custom':($view==='home'?'30':'90'));
    if(!isset($reportInput['range'])&&isset($reportInput['start'],$reportInput['end']))foreach($presets as$key=>$preset)if($reportInput['start']===$preset['start']&&$reportInput['end']===$preset['end']){$rangeKey=(string)$key;break;}
    try{
        if($rangeKey!=='custom'){
            if(!isset($presets[$rangeKey]))throw new InvalidArgumentException('Choose a supported date range.');
            $reportInput['start']=$presets[$rangeKey]['start'];$reportInput['end']=$presets[$rangeKey]['end'];
        }
        $window=audienceAnalyticsWindow($reportInput);$overview=$store->analyticsOverview($window,($reportInput['drafts']??'')==='1');
    }catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();$window=audienceAnalyticsWindow([]);}
    $showDrafts=($reportInput['drafts']??'')==='1';$overview??=$store->analyticsOverview($window,$showDrafts);
    $messageOptions=$store->all("SELECT id,name FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL AND status<>'draft' ORDER BY updated_at DESC,id DESC",[$audienceId]);
    $metric=audienceText($reportInput,'metric',30)?:'accepted';$group=audienceText($reportInput,'group',10)?:'campaign';
    $deliveryMetric=audienceText($reportInput,'delivery_metric',30)?:'bounce_rate';$deliveryGroup=audienceText($reportInput,'delivery_group',10)?:'campaign';
    if(!in_array($deliveryMetric,['bounce_rate','bounced','unsubscribe_rate','unsubscribed','delivery_rate','delivered'],true)||!in_array($deliveryGroup,['campaign','day','week','month'],true)){http_response_code(400);$error='Choose supported delivery report controls.';$deliveryMetric='bounce_rate';$deliveryGroup='campaign';}
    if(!in_array($metric,array_keys(audienceAnalyticsMetricLabels()),true)||!in_array($group,['campaign','day','week','month'],true)){http_response_code(400);$error='Choose supported report controls.';$metric='accepted';$group='campaign';}
    $definition=null;$customRows=[];$activityReport=null;$activity=audienceText($reportInput,'activity',20)?:'accepted';$page=max(1,(int)($reportInput['page']??1));
    $linkHash=audienceText($reportInput,'link',64);
    $source=audienceText($reportInput,'source',100);$analyticsSegmentOptions=$store->all('SELECT id,name FROM marketing_segments WHERE audience_id=? ORDER BY name',[$audienceId]);$sourceOptions=[];$history=null;$nativeFunnels=null;$evidence=null;$savedReports=[];$clickMap=null;
    try{
        if($section==='custom'){$definition=audienceAnalyticsReportDefinition($reportInput);$customRows=$store->analyticsReportRows($window,$definition);}
        if($section==='activity')$activityReport=$store->analyticsActivity($window,$activity,$page,25,$linkHash);
        if(in_array($section,['links','devices','insights'],true))$evidence=$store->analyticsEvidence($window);
        if($section==='links'&&($reportInput['map']??'')==='1')$clickMap=$store->analyticsClickMap($window,max(0,(int)($reportInput['delivery']??0)));
        if($section==='custom'&&$definition['dataset']==='audience')$sourceOptions=$store->all('SELECT DISTINCT source FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL ORDER BY source',[$audienceId]);
        if($section==='audience'){$history=$store->analyticsAudienceHistory($window,$source);$sourceOptions=$store->all('SELECT DISTINCT source FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL ORDER BY source',[$audienceId]);}
        if($section==='conversions')$nativeFunnels=$store->analyticsNativeFunnels($window);
        if($section==='reports')$savedReports=$store->analyticsSavedReports($canDelete,($reportInput['removed']??'')==='1');
    }catch(InvalidArgumentException $e){http_response_code($e->getCode()===403?403:400);$error=$e->getMessage();}
    $download=audienceText($_GET,'download',20);
    if($download!==''){
        if(!$canManage){http_response_code(403);exit('Export requires an editor or administrator.');}
        if($error!==''){http_response_code(400);exit($h($error));}
        if(!in_array($download,['csv','recipients','links','custom','audience','forms','flows'],true)){http_response_code(400);exit('Choose a supported report export.');}
        header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="message-monkey-'.$download.'.csv"');header('Cache-Control: no-store');
        $out=fopen('php://output','w');$csv=static function(array $row)use($out):void{
            // Prevent formula execution when a spreadsheet opens user-provided names or addresses.
            $row=array_map(static fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v,$row);fputcsv($out,$row,',','"','');
        };
        if($download==='csv'){$csv(['UTC day','Accepted messages','Observed unique opens','Observed unique clicks','Bot filter']);foreach($overview['daily']as$day=>$row)$csv([$day,$row['accepted'],$row['opens'],$row['clicks'],$window['bots']]);}
        elseif($download==='recipients'){
            $csv(['Recipient receipt','Email at sending','Campaign','Accepted UTC','Delivered','Opened','Clicked','Bounced','Complained','Unsubscribed']);
            for($n=1;;$n++){$batch=$store->analyticsActivity($window,$activity,$n,500,$linkHash);foreach($batch['rows']as$r)$csv([$r['id'],$r['destination'],$r['campaign'],$r['accepted_at'],$r['delivered'],$r['opens'],$r['clicks'],$r['bounced'],$r['complaints'],$r['unsubscribed']]);if($n*500>=$batch['total'])break;}
        }elseif($download==='links'){$csv(['Link hash','Destination without query','Unique messages','Observations']);foreach(($evidence??$store->analyticsEvidence($window))['links']as$r)$csv([$r['link_hash'],$r['label']??'Unavailable',$r['messages'],$r['observations']]);}
        else{
            $exportDefinition=$download==='custom'?($definition??audienceAnalyticsReportDefinition($reportInput)):audienceAnalyticsReportDefinition(['dataset'=>$download==='audience'?'audience':$download,'source'=>$source]);
            $exportRows=$download==='custom'&&$definition?$customRows:$store->analyticsReportRows($window,$exportDefinition);$labels=audienceAnalyticsMetricLabels();
            $csv([ucfirst($exportDefinition['dimension']),...array_map(static fn($m)=>$labels[$m],$exportDefinition['columns'])]);
            foreach($exportRows as$r)$csv([$r['label'],...array_map(static fn($m)=>$r[$m]??'',$exportDefinition['columns'])]);
        }
        fclose($out);return;
    }
    $catalog=$store->catalog();$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=null;$job=null;
    $pageTitle=$view==='home'?'Home':match($section){'audience'=>'Audience analytics','conversions'=>'Conversion insights','reports','custom'=>'Custom reports','activity'=>'Recipient activity','links'=>'Link performance','devices'=>'Locations and clients','insights'=>'Benchmarks and timing',default=>'Email dashboard'};
    $csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
    releaseApplicationSessionLock();
    ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-analytics.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-analytics.min.js']]);include __DIR__.'/templates/audience/workspace.php';?></html><?php
}
