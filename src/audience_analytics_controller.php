<?php
declare(strict_types=1);

function renderAudienceAnalytics(AudienceStore $store,array $audience,string $view,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor):void
{
    $audienceId=$store->audienceId;$id=0;$savedReport=null;
    $h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $url=static fn(array $p=[]):string=>'audience.php'.($p?'?'.http_build_query($p):'');
    $reportListParams=['view'=>'analytics','section'=>'reports']+array_intersect_key($_GET,array_flip(['removed','report_search','favorites','list_page','list_size','range','start','end']));
    $reportListUrl=static fn(array $changes=[]):string=>$url(array_replace($reportListParams,$changes));
    if($_SERVER['REQUEST_METHOD']==='POST'){
        requireValidCsrfToken();if(!$canManage){http_response_code(403);exit('Saving reports requires an editor or administrator.');}
        try{
            if($view!=='analytics'||audienceText($_POST,'action',30)!=='analytics_report')throw new InvalidArgumentException('Reports do not support this action.');
            $operation=audienceText($_POST,'report_action',20);
            if($operation==='purge'){
                if(!$canDelete){http_response_code(403);exit('Only an administrator can permanently delete an archived report.');}
                requireRecentAdminElevation($reportListUrl(['removed'=>'1']));
            }
            $key=$store->analyticsSaveReport($_POST,$canDelete);
            $_SESSION['audience_message']=match($operation){
                'archive','delete'=>'Report archived.', 'restore'=>'Report restored to Active.', 'purge'=>'Report permanently deleted.', default=>'Report updated.'
            };
            $redirect=match($operation){
                'archive','delete','favorite'=>$reportListUrl(['removed'=>'0']),
                'purge','restore'=>$reportListUrl(['removed'=>'1']),
                default=>$url(['view'=>'analytics','section'=>'custom','report'=>$key])
            };
            header('Location: '.$redirect,true,303);return;
        }catch(InvalidArgumentException $e){http_response_code($e->getCode()===403?403:400);$error=$e->getMessage();}
    }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET, POST');exit('Unsupported report method.');}
    $csrf=csrfInput();
    releaseApplicationSessionLock();
    $reportInput=$_SERVER['REQUEST_METHOD']==='POST'?$_POST+$_GET:$_GET;
    $reportKey=audienceText($reportInput,'report',32);$section=audienceText($reportInput,'section',20)?:'email';
    if(!in_array($section,['email','audience','conversions','reports','custom','activity','links','devices','insights','locations'],true)){http_response_code(400);$error='Choose a supported report.';$section='email';}
    if($reportKey!==''&&$section==='custom'){
        try{
            $savedReport=$store->analyticsSavedReport($reportKey,$canDelete);$base=$savedReport['definition'];
            if(isset($reportInput['dataset'])&&$reportInput['dataset']!==$base['dataset'])$base=array_diff_key($base,array_flip(['columns','plots','dimension']));
            $reportInput=array_replace($base,$reportInput);
        }
        catch(InvalidArgumentException $e){http_response_code(403);exit($h($e->getMessage()));}
    }
    $presets=audienceAnalyticsPresets();
    $rangeKey=audienceText($reportInput,'range',20)?:((isset($reportInput['start'])||isset($reportInput['end']))?'custom':($view==='home'?'30':($section==='locations'?'7':'90')));
    if(!isset($reportInput['range'])&&isset($reportInput['start'],$reportInput['end']))foreach($presets as$key=>$preset)if($reportInput['start']===$preset['start']&&$reportInput['end']===$preset['end']){$rangeKey=(string)$key;break;}
    $download=audienceText($_GET,'download',20);
    $showDrafts=($reportInput['drafts']??'')==='1';
    $conversionSection=$view==='analytics'&&$section==='conversions';
    $homeSummary=$view==='home'&&$section==='email'&&$download==='';
    $includeEmailMetrics = !$homeSummary && ($view === 'home' || $download === 'csv' || in_array($section, ['email','insights'], true));
    $loadOverview = match (true) {
        $conversionSection && $download==='' => $store->analyticsConversionReport(...),
        $view==='analytics' && $section==='activity' && $download==='' => $store->analyticsReportMetadata(...),
        default => fn(array $w)=>$store->analyticsOverview($w,$showDrafts,$includeEmailMetrics),
    };
    try{
        if($rangeKey!=='custom'){
            if(!isset($presets[$rangeKey]))throw new InvalidArgumentException('Choose a supported date range.');
            $reportInput['start']=$presets[$rangeKey]['start'];$reportInput['end']=$presets[$rangeKey]['end'];
        }
        $window=audienceAnalyticsWindow($reportInput);$overview=$loadOverview($window);
    }catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();$window=audienceAnalyticsWindow([]);}
    $overview??=$loadOverview($window);
    // Finish the Home summary before releasing HTML so the worker can also serve cold assets.
    if($homeSummary)$overview+=$store->analyticsHomeMetrics($window,$showDrafts);
    $messageOptions=($view==='home'||$conversionSection)?[]:$store->all("SELECT id,name FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL AND status<>'draft' ORDER BY updated_at DESC,id DESC",[$audienceId]);
    $metric=audienceText($reportInput,'metric',30)?:'accepted';$group=audienceText($reportInput,'group',10)?:'campaign';
    $deliveryMetric=audienceText($reportInput,'delivery_metric',30)?:'bounce_rate';$deliveryGroup=audienceText($reportInput,'delivery_group',10)?:'campaign';
    if(!in_array($deliveryMetric,['bounce_rate','bounced','unsubscribe_rate','unsubscribed','delivery_rate','delivered'],true)||!in_array($deliveryGroup,['campaign','day','week','month'],true)){http_response_code(400);$error='Choose supported delivery report controls.';$deliveryMetric='bounce_rate';$deliveryGroup='campaign';}
    if(!in_array($metric,array_keys(audienceAnalyticsMetricLabels()),true)||!in_array($group,['campaign','day','week','month'],true)){http_response_code(400);$error='Choose supported report controls.';$metric='accepted';$group='campaign';}
    $definition=null;$customRows=[];$activityReport=null;$activitySize=audiencePageSize($reportInput);$activity=audienceText($reportInput,'activity',20)?:'accepted';$page=max(1,(int)($reportInput['page']??1));
    $linkHash=audienceText($reportInput,'link',64);
    $source=audienceText($reportInput,'source',100);$analyticsSegmentOptions=[];$sourceOptions=[];$history=null;$evidence=null;$savedReports=[];$clickMap=null;$interactionLocations=null;$interactionMapProvider=null;
    $reportsArchived=($reportInput['removed']??'')==='1';$favoritesOnly=($reportInput['favorites']??'')==='1';
    $reportSearch='';$reportTotal=0;$reportSize=audiencePageSize($reportInput,'list_size');$reportPage=1;
    try{
        if($section==='custom'){
            $definition=audienceAnalyticsReportDefinition($reportInput);$customRows=$store->analyticsReportRows($window,$definition);
            if($definition['dataset']==='email'&&$download==='')$analyticsSegmentOptions=$store->all('SELECT id,name FROM marketing_segments WHERE audience_id=?'.($store->definitionArchiveAvailable()?' AND archived_at IS NULL':'').' ORDER BY name',[$audienceId]);
        }
        if($section==='activity')$activityReport=$store->analyticsActivity($window,$activity,$page,$activitySize,$linkHash);
        if(in_array($section,['links','devices'],true))$evidence=$store->analyticsEvidence($window,$section);
        if($section==='links'&&($reportInput['map']??'')==='1')$clickMap=$store->analyticsClickMap($window,max(0,(int)($reportInput['delivery']??0)));
        if($section==='locations'){
            require_once __DIR__.'/map_helpers.php';
            $interactionLocations=$store->analyticsInteractionLocations($window);
            $interactionMapProvider=[
                'type'=>deploymentConfig()->string('map.provider'),
                'styleUrl'=>engagementMapStyleUrl(),
                'rasterTileUrl'=>deploymentConfig()->string('map.tile_url'),
                'attributionText'=>deploymentConfig()->string('map.attribution_text'),
                'attributionUrl'=>deploymentConfig()->string('map.attribution_url'),
                'maximumZoom'=>deploymentConfig()->integer('map.maximum_zoom'),
            ];
        }
        if($section==='custom'&&$definition['dataset']==='audience')$sourceOptions=$store->all('SELECT DISTINCT source FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL ORDER BY source',[$audienceId]);
        if($section==='audience'){$history=$store->analyticsAudienceHistory($window,$source);$sourceOptions=$store->all('SELECT DISTINCT source FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL ORDER BY source',[$audienceId]);}
        if($section==='reports'){
            $reportSearch=audienceText($reportInput,'report_search',100);
            $savedReports=array_values(array_filter($store->analyticsSavedReports($canDelete,$reportsArchived),
                static fn($r)=>(!$favoritesOnly||in_array((string)$actor,$r['favorites'],true))&&($reportSearch===''||mb_stripos($r['name'],$reportSearch)!==false)));
            $reportTotal=count($savedReports);
            $reportPage=min(max(1,(int)ceil($reportTotal/$reportSize)),max(1,(int)($reportInput['list_page']??1)));
            $savedReports=array_slice($savedReports,($reportPage-1)*$reportSize,$reportSize);
        }
    }catch(InvalidArgumentException $e){http_response_code($e->getCode()===403?403:400);$error=$e->getMessage();}
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
        }elseif($download==='links'){$csv(['Link hash','Destination without query','Unique messages','Observations']);foreach(($evidence['links']??$store->analyticsEvidence($window,'links')['links'])as$r)$csv([$r['link_hash'],$r['label']??'Unavailable',$r['messages'],$r['observations']]);}
        else{
            $exportDefinition=$download==='custom'?($definition??audienceAnalyticsReportDefinition($reportInput)):audienceAnalyticsReportDefinition(['dataset'=>$download==='audience'?'audience':$download,'source'=>$source]);
            $exportRows=$download==='custom'&&$definition?$customRows:$store->analyticsReportRows($window,$exportDefinition);$labels=audienceAnalyticsMetricLabels();
            $csv([ucfirst($exportDefinition['dimension']),...array_map(static fn($m)=>$labels[$m],$exportDefinition['columns'])]);
            foreach($exportRows as$r)$csv([$r['label'],...array_map(static fn($m)=>$r[$m]??'',$exportDefinition['columns'])]);
        }
        fclose($out);return;
    }
    $catalog=[];$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=null;$job=null;
    $pageTitle=$view==='home'?'Home':match($section){'audience'=>'Audience analytics','conversions'=>'Conversion insights','reports','custom'=>'Custom reports','activity'=>'Recipient activity','links'=>'Link performance','devices'=>'Locations and clients','insights'=>'Benchmarks and timing','locations'=>'Interaction Locations',default=>'Email dashboard'};
    $themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
    $styles=['assets/css/pages/audience.min.css','assets/css/pages/audience-analytics.min.css'];
    if($section==='locations')$styles[]='assets/css/map.min.css';
    ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>$styles,'scripts'=>['assets/js/audience.min.js','assets/js/audience-analytics.min.js']]);include __DIR__.'/templates/audience/workspace.php';?></html><?php
}
