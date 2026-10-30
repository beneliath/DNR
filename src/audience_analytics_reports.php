<?php
declare(strict_types=1);

function audienceAnalyticsMetricLabels():array
{
    return ['accepted'=>'Total sends','delivered'=>'Confirmed deliveries','successful'=>'Rate denominator','awaiting_receipt'=>'Pending receipts','total_opens'=>'Total opens','total_clicks'=>'Total clicks','complaints'=>'Complaints','complaint_rate'=>'Complaint rate','opens'=>'Opened','open_rate'=>'Open rate','clicks'=>'Clicked','click_rate'=>'Click rate','clicks_per_open'=>'Clicks per unique opens','bounced'=>'Bounces','bounce_rate'=>'Bounce rate','unsubscribed'=>'Unsubscribed','unsubscribe_rate'=>'Unsubscribe rate','delivery_rate'=>'Delivery rate','added'=>'Connections added','subscriptions'=>'Observed subscriptions','optouts'=>'Observed unsubscribes','net'=>'Observed net subscription changes','visitors'=>'Daily unique visitors','responses'=>'Submissions','confirmed'=>'Confirmed subscriptions','conversion_rate'=>'Visitor-to-subscription rate','runs'=>'Started','completed'=>'Completed','exited'=>'Exited','failed'=>'Failed','active'=>'Active','completion_rate'=>'Completion rate'];
}

function audienceAnalyticsReportCapabilities():array
{
    return [
        'email'=>[['campaign','day','week','month','quarter','year'],['accepted','delivered','opens','open_rate','clicks','click_rate','clicks_per_open','bounced','bounce_rate','unsubscribed','unsubscribe_rate','delivery_rate','successful','awaiting_receipt','total_opens','total_clicks','complaints','complaint_rate']],
        'audience'=>[['source','day','week','month'],['added','subscriptions','optouts','net']],
        'forms'=>[['resource'],['visitors','responses','confirmed','conversion_rate']],
        'flows'=>[['flow'],['runs','completed','exited','failed','active','completion_rate']],
    ];
}

function audienceAnalyticsMetricUnit(string $metric):string
{
    return str_ends_with($metric,'_rate')||$metric==='clicks_per_open'?'%':'count';
}

function audienceAnalyticsReportDefinition(array $input):array
{
    $dataset=audienceText($input,'dataset',20)?:'email';
    $allowed=audienceAnalyticsReportCapabilities();
    if(!isset($allowed[$dataset]))throw new InvalidArgumentException('Choose a supported report type.');
    [$dimensions,$metrics]=$allowed[$dataset];$dimension=audienceText($input,'dimension',20)?:$dimensions[0];
    if(!in_array($dimension,$dimensions,true))throw new InvalidArgumentException('Choose a supported report dimension.');
    $columns=$input['columns']??(isset($input['columns_present'])?[]:($dataset==='email'?['accepted','opens','open_rate','clicks','click_rate','unsubscribed','unsubscribe_rate']:array_slice($metrics,0,4)));
    if(!is_array($columns)||count($columns)<1||count($columns)>20)throw new InvalidArgumentException('Choose between one and twenty metrics.');
    foreach($columns as$column)if(!is_string($column)||!in_array($column,$metrics,true))throw new InvalidArgumentException('Choose metrics available for this report type.');
    $columns=array_values(array_unique($columns));$plots=$input['plots']??(isset($input['plots_present'])?[]:array_slice($columns,0,2));
    if(!is_array($plots)||count($plots)>2)throw new InvalidArgumentException('Visualize up to two metrics.');
    foreach($plots as$plot)if(!is_string($plot)||!in_array($plot,$columns,true))throw new InvalidArgumentException('Chart metrics must also be included in the report.');
    return ['dataset'=>$dataset,'dimension'=>$dimension,'columns'=>$columns,'plots'=>array_values(array_unique($plots)),'source'=>audienceText($input,'source',100)];
}

/** Coarse observations only. No raw IP, raw user-agent or URL query is retained. */
function audienceAnalyticsClientEvidence(string $agent,?string $country,string $url=''):array
{
    $device=$agent===''||preg_match('/proxy|bot|crawler|scanner/i',$agent)?'Unknown':(preg_match('/ipad|tablet/i',$agent)?'Tablet':(preg_match('/mobi|iphone|android/i',$agent)?'Mobile':'Desktop'));
    $client='Unknown';foreach(['Edge'=>'Edg/','Opera'=>'OPR/','Chrome'=>'Chrome/','Firefox'=>'Firefox/','Safari'=>'Safari/','Outlook'=>'Outlook','Thunderbird'=>'Thunderbird','Image proxy'=>'GoogleImageProxy']as$name=>$needle)if(str_contains($agent,$needle)){$client=$name;break;}
    if(str_contains($agent,'GoogleImageProxy'))$client='Image proxy';
    $evidence=['device'=>$device,'client'=>$client,'country'=>$country??'Unknown'];
    if($url!==''){$parts=parse_url($url);$evidence['link_label']=mb_substr(($parts['host']??'').($parts['path']??'/'),0,250);}
    return $evidence;
}

trait AudienceAnalyticsReports
{
    private function analyticsRecipientScope(array $w):array
    {
        $sql="r.audience_id=? AND r.state='accepted' AND r.accepted_at>=? AND r.accepted_at<?";$p=[$this->audienceId,$w['from'],$w['until']];
        foreach(['contact_id'=>'r.contact_id','delivery_id'=>'r.delivery_id']as$key=>$column)if(!empty($w[$key])){$sql.=" AND $column=?";$p[]=(int)$w[$key];}
        if($w['channel']!=='all')$sql.=$w['channel']==='automation'?' AND d.flow_run_id>0':' AND d.flow_run_id=0';
        if($w['messages_mode']==='selected'){
            if(!$w['messages'])$sql.=' AND 1=0';
            else{$sql.=' AND d.campaign_id IN ('.implode(',',array_fill(0,count($w['messages']),'?')).')';array_push($p,...$w['messages']);}
        }
        if(!empty($w['segment'])){
            $segment=$this->one('SELECT filters_json FROM marketing_segments WHERE audience_id=? AND id=?',[$this->audienceId,$w['segment']]);
            if(!$segment)throw new InvalidArgumentException('This saved segment is unavailable in this audience.');
            $tree=audienceLegacyRules(audienceJson($segment['filters_json']),$this->tags(true));[$membership,$values]=$this->where(audienceListFilters([]),$tree);
            $sql.=" AND r.contact_id IN (SELECT c.id FROM marketing_contacts c WHERE $membership)";array_push($p,...$values);
        }
        return [$sql,$p];
    }

    public function analyticsRecentCampaigns(array $window,int $limit=3):array
    {
        if($limit<1||$limit>10)throw new InvalidArgumentException('Choose between one and ten recent campaigns.');
        [$scope,$params]=$this->analyticsRecipientScope($window);
        $rows=$this->all("SELECT d.campaign_id FROM marketing_campaign_recipients r
            JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id
            JOIN marketing_campaigns m ON m.id=d.campaign_id AND m.audience_id=d.audience_id
            WHERE $scope AND m.deleted_at IS NULL GROUP BY d.campaign_id ORDER BY MAX(r.accepted_at) DESC,d.campaign_id DESC LIMIT $limit",$params);
        return array_map('intval',array_column($rows,'campaign_id'));
    }

    public function analyticsActivity(array $w,string $activity='accepted',int $page=1,int $size=25,string $link=''):array
    {
        $flags=['accepted','delivered','opens','clicks','bounced','complaints','unsubscribed'];
        if(!in_array($activity,$flags,true)||$page<1||$page>100000||$size<1||$size>500)throw new InvalidArgumentException('Choose a valid recipient report page.');
        [$scope,$params]=$this->analyticsRecipientScope($w);
        $columns=audienceEngagementColumns($w['bots']);
        if($link!==''){
            if(!preg_match('/^[a-f0-9]{64}$/D',$link))throw new InvalidArgumentException('Choose a valid tracked link.');
            $scope.=" AND EXISTS(SELECT 1 FROM marketing_message_events le WHERE le.recipient_id=r.id AND le.audience_id=r.audience_id AND le.event_type='click' AND le.event_at<? AND JSON_UNQUOTE(JSON_EXTRACT(le.details_json,'$.link_hash'))=?".($w['bots']==='filtered'?" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(le.details_json,'$.bot')),'false')<>'true'":'').')';array_push($params,$w['until'],$link);
        }
        $query="SELECT r.id,r.contact_id,r.accepted_at,r.context_ciphertext,c.email,c.first_name,c.last_name,c.deleted_at,d.campaign_id,m.name campaign,
            $columns,
            EXISTS(SELECT 1 FROM marketing_contact_events ce WHERE ce.contact_id=r.contact_id AND ce.event_type='status'
                AND JSON_UNQUOTE(JSON_EXTRACT(ce.details_json,'$.to'))='unsubscribed'
                AND JSON_UNQUOTE(JSON_EXTRACT(ce.details_json,'$.recipient_id'))=CAST(r.id AS CHAR) AND ce.created_at<?) unsubscribed
            FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id
            JOIN marketing_campaigns m ON m.id=d.campaign_id AND m.audience_id=d.audience_id
            JOIN marketing_contacts c ON c.id=r.contact_id AND c.audience_id=r.audience_id
            LEFT JOIN marketing_message_events e ON e.recipient_id=r.id AND e.audience_id=r.audience_id AND e.event_at<?
            WHERE $scope GROUP BY r.id";
        $params=[$w['until'],$w['until'],...$params];$where=$activity==='accepted'?'1=1':$activity.'=1';
        $total=(int)$this->one("SELECT COUNT(*) n FROM ($query) observations WHERE $where",$params)['n'];
        $page=min($page,max(1,(int)ceil($total/$size)));
        $rows=$this->all("SELECT * FROM ($query) observations WHERE $where ORDER BY id DESC LIMIT $size OFFSET ".(($page-1)*$size),$params);
        foreach($rows as&$row){
            $row['destination']='Deleted connection';
            if($row['deleted_at']===null){$row['destination']=$row['email'];try{$context=audienceJson(\Dnr\Security\ApplicationKey::open($row['context_ciphertext']));$row['destination']=$context['email']??$row['email'];}catch(Throwable){/* Old receipt may no longer be decryptable. */}}
            unset($row['context_ciphertext']);foreach(['delivered','opens','clicks','bounced','complaints','unsubscribed']as$key)$row[$key]=(int)($row[$key]??0);
        }unset($row);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'size'=>$size];
    }

    public function analyticsEvidence(array $w):array
    {
        [$scope,$params]=$this->analyticsRecipientScope($w);$bot=$w['bots']==='filtered'?" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')<>'true'":'';
        $base="FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id AND r.audience_id=e.audience_id
            JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id
            WHERE $scope AND e.event_at<? AND e.event_type IN ('open','click') $bot
            AND NOT EXISTS(SELECT 1 FROM marketing_message_events failure WHERE failure.recipient_id=r.id AND failure.audience_id=r.audience_id AND failure.source='provider' AND failure.event_type IN ('hard-bounce','soft-bounce','rejected') AND failure.event_at<?)";
        $p=[...$params,$w['until'],$w['until']];$result=[];
        foreach(['country','client','device']as$key)$result[$key]=$this->all("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.$key')),''),'Unknown') label,COUNT(DISTINCT r.id) messages,COUNT(*) observations $base GROUP BY label ORDER BY messages DESC,label",$p);
        $result['links']=$this->all("SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.link_hash')),''),'unknown') link_hash,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.link_label')),'')) label,COUNT(DISTINCT r.id) messages,COUNT(*) observations $base AND e.event_type='click' GROUP BY link_hash ORDER BY messages DESC,link_hash",$p);
        $result['hours']=$this->all("SELECT HOUR(e.event_at) hour,COUNT(DISTINCT r.contact_id) contacts,COUNT(DISTINCT r.id) messages $base AND e.event_type='click' GROUP BY hour ORDER BY contacts DESC,messages DESC,hour",$p);
        $result['timing_contacts']=(int)$this->one("SELECT COUNT(DISTINCT r.contact_id) n $base AND e.event_type='click'",$p)['n'];
        return $result;
    }

    public function analyticsAudienceHistory(array $w,string $source=''):array
    {
        $sourceWhere=$source!==''?' AND c.source=?':'';$sp=$source!==''?[$source]:[];
        $sources=$this->all("SELECT c.source label,COUNT(*) total,SUM(c.email_status='subscribed') subscribed,
            SUM(c.created_at>=? AND c.created_at<?) added FROM marketing_contacts c WHERE c.audience_id=? AND c.deleted_at IS NULL AND c.is_archived=0 AND c.email_status NOT IN ('cleaned','pending') $sourceWhere GROUP BY c.source ORDER BY total DESC,label",[$w['from'],$w['until'],$this->audienceId,...$sp]);
        $history=[];$unknown=0;
        $from=min($w['from'],$w['previous_from']);$until=max($w['until'],$w['previous_until']);
        foreach($this->all("SELECT DATE(c.created_at) day,c.source label,COUNT(*) added FROM marketing_contacts c WHERE c.audience_id=? AND c.deleted_at IS NULL AND c.created_at>=? AND c.created_at<? $sourceWhere GROUP BY day,c.source",[$this->audienceId,$from,$until,...$sp])as$row)$history[$row['day'].'|'.$row['label']]=['day'=>$row['day'],'source'=>$row['label'],'added'=>(int)$row['added'],'subscriptions'=>0,'optouts'=>0,'net'=>0];
        $events=$this->all("SELECT DATE(e.created_at) day,c.source label,
            JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.from')) old_status,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.to')),IF(e.event_type='contact_added',JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.status')),NULL)) new_status,
            COUNT(*) n FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id
            WHERE c.audience_id=? AND c.deleted_at IS NULL AND e.created_at>=? AND e.created_at<? $sourceWhere
            AND e.event_type IN ('status','subscription_changed','subscription-confirmed','contact_added') GROUP BY day,c.source,old_status,new_status",[$this->audienceId,$from,$until,...$sp]);
        foreach($events as$row){$key=$row['day'].'|'.$row['label'];$history[$key]??=['day'=>$row['day'],'source'=>$row['label'],'added'=>0,'subscriptions'=>0,'optouts'=>0,'net'=>0];
            $n=(int)$row['n'];if(!$row['new_status']){$unknown+=$n;continue;}
            if($row['new_status']==='subscribed'&&$row['old_status']!=='subscribed')$history[$key]['subscriptions']+=$n;
            if($row['new_status']==='unsubscribed'&&$row['old_status']!=='unsubscribed')$history[$key]['optouts']+=$n;
            $history[$key]['net']+=(int)($row['new_status']==='subscribed'&&$row['old_status']!=='subscribed')*$n-(int)($row['old_status']==='subscribed'&&$row['new_status']!=='subscribed')*$n;
        }
        ksort($history);$current=[];$previous=[];
        foreach($history as$row){$at=$row['day'].' 00:00:00';if($at>=$w['from']&&$at<$w['until'])$current[]=$row;if($at>=$w['previous_from']&&$at<$w['previous_until'])$previous[]=$row;}
        return ['sources'=>$sources,'current'=>$current,'previous'=>$previous,'unclassified'=>$unknown];
    }

    public function analyticsNativeFunnels(array $w):array
    {
        $forms=$this->all("SELECT c.id,c.name label,c.kind,
            (SELECT COUNT(*) FROM marketing_collection_visits v WHERE v.resource_id=c.id AND v.day>=? AND v.day<=?) visitors,
            (SELECT COUNT(*) FROM marketing_collection_responses r WHERE r.audience_id=c.audience_id AND r.resource_id=c.id AND r.created_at>=? AND r.created_at<?) responses,
            (SELECT COUNT(DISTINCT e.contact_id) FROM marketing_contact_events e JOIN marketing_contacts p ON p.id=e.contact_id AND p.audience_id=c.audience_id WHERE p.deleted_at IS NULL AND e.event_type='collection-converted' AND JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.resource_id'))=CAST(c.id AS CHAR) AND e.created_at>=? AND e.created_at<?) confirmed
            FROM marketing_collection_resources c WHERE c.audience_id=? AND c.deleted_at IS NULL ORDER BY label,c.id",[$w['start'],$w['end'],$w['from'],$w['until'],$w['from'],$w['until'],$this->audienceId]);
        foreach($forms as&$r){foreach(['visitors','responses','confirmed']as$key)$r[$key]=(int)$r[$key];$r['conversion_rate']=audienceAnalyticsRate($r['confirmed'],$r['visitors']);}unset($r);
        $flows=$this->all("SELECT f.id,f.name label,COUNT(r.id) runs,COALESCE(SUM(r.state='completed'),0) completed,
            COALESCE(SUM(r.state='exited'),0) exited,COALESCE(SUM(r.state='failed'),0) failed,COALESCE(SUM(r.state='active'),0) active
            FROM marketing_flows f LEFT JOIN marketing_flow_runs r ON r.flow_id=f.id AND r.audience_id=f.audience_id AND r.started_at>=? AND r.started_at<?
            WHERE f.audience_id=? AND f.deleted_at IS NULL GROUP BY f.id ORDER BY label,f.id",[$w['from'],$w['until'],$this->audienceId]);
        foreach($flows as&$r){foreach(['runs','completed','exited','failed','active']as$key)$r[$key]=(int)$r[$key];$r['completion_rate']=audienceAnalyticsRate($r['completed'],$r['runs']);}unset($r);
        return ['forms'=>$forms,'flows'=>$flows];
    }

    public function analyticsReportRows(array $w,array $definition):array
    {
        $d=audienceAnalyticsReportDefinition($definition);$groups=[];$previous=[];
        $groupRows=static function(array $rows,string $dimension,string $dataset,array $names=[])use(&$groups):array{
            $g=[];foreach($rows as$row){$date=new DateTimeImmutable($row['day']??'today');$key=match($dimension){'campaign'=>(string)$row['campaign_id'],'source'=>$row['source'],'week'=>$date->modify('monday this week')->format('Y-m-d'),'month'=>$date->format('Y-m'),'quarter'=>$date->format('Y').'-Q'.(1+intdiv((int)$date->format('n')-1,3)),'year'=>$date->format('Y'),default=>$row['day']};$g[$key]['label']=$names[$key]??$key;$g[$key]['rows'][]=$row;}ksort($g);return $g;
        };
        if($d['dataset']==='email'){
            $names=array_column($this->all('SELECT id,name FROM marketing_campaigns WHERE audience_id=?',[$this->audienceId]),'name','id');
            foreach(['current'=>[$w['from'],$w['until']],'previous'=>[$w['previous_from'],$w['previous_until']]]as$period=>[$from,$until]){
                $rows=$this->analyticsRecipients($from,$until,$w['bots'],$w);$grouped=$groupRows($rows,$d['dimension'],'email',$names);$out=[];
                foreach($grouped as$key=>$g)$out[$key]=['label'=>$g['label'],'id'=>$d['dimension']==='campaign'?(int)$key:0]+$this->analyticsTotals($g['rows']);
                if($period==='current')$groups=$out;else$previous=$out;
            }
        }elseif($d['dataset']==='audience'){
            $history=$this->analyticsAudienceHistory($w,$d['source']);
            foreach(['current','previous']as$period){$out=[];foreach($groupRows($history[$period],$d['dimension'],'audience')as$key=>$g){$out[$key]=['label'=>$g['label']];foreach(['added','subscriptions','optouts','net']as$m)$out[$key][$m]=array_sum(array_column($g['rows'],$m));}if($period==='current')$groups=$out;else$previous=$out;}
        }else{
            $prior=array_replace($w,['from'=>$w['previous_from'],'until'=>$w['previous_until'],'start'=>substr($w['previous_from'],0,10),'end'=>(new DateTimeImmutable($w['previous_until']))->modify('-1 day')->format('Y-m-d')]);
            foreach($this->analyticsNativeFunnels($w)[$d['dataset']]as$row)$groups[$row['id']]=$row;
            foreach($this->analyticsNativeFunnels($prior)[$d['dataset']]as$row)$previous[$row['id']]=$row;
        }
        // Align dated comparison buckets by their relative position, not their different calendar dates.
        if(in_array($d['dimension'],['day','week','month'],true)){
            $aligned=[];$previousStart=new DateTimeImmutable($w['previous_from']);$currentStart=new DateTimeImmutable($w['from']);
            foreach($previous as$key=>$row){
                if($d['dimension']==='day'){$offset=(int)$previousStart->diff(new DateTimeImmutable($key))->format('%r%a');$target=$currentStart->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d');}
                elseif($d['dimension']==='week'){$first=$previousStart->modify('monday this week');$offset=(int)$first->diff(new DateTimeImmutable($key))->format('%r%a');$target=$currentStart->modify('monday this week')->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d');}
                else{$point=new DateTimeImmutable($key.'-01');$offset=((int)$point->format('Y')-(int)$previousStart->format('Y'))*12+(int)$point->format('n')-(int)$previousStart->format('n');$target=$currentStart->modify('first day of this month')->modify(($offset>=0?'+':'').$offset.' months')->format('Y-m');}
                $aligned[$target]=$row;
            }$previous=$aligned;
        }
        foreach($groups as$key=>&$row)$row['previous']=$previous[$key]??null;unset($row);
        return array_values($groups);
    }

    /** Saved definitions share the audience settings row's existing transactional/version boundary. */
    public function analyticsSavedReports(bool $administrator=false,bool $removed=false):array
    {
        $settings=audienceJson($this->one('SELECT settings_json FROM marketing_audiences WHERE id=?',[$this->audienceId])['settings_json']);
        return array_values(array_filter($settings['analytics_reports']??[],fn($r)=>(!empty($r['deleted'])===$removed)&&($administrator||($r['owner']??0)===$this->actorId||(!$removed&&!empty($r['shared'])))));
    }
    public function analyticsSavedReport(string $key,bool $administrator=false):array
    {
        foreach($this->analyticsSavedReports($administrator)as$r)if(hash_equals($r['key'],$key))return $r;
        throw new InvalidArgumentException('This report is unavailable or has not been shared with you.',403);
    }
    public function analyticsSaveReport(array $input,bool $administrator=false):string
    {
        $key=audienceText($input,'report',32);$operation=audienceText($input,'report_action',20)?:'save';
        if(!in_array($operation,['save','favorite','delete','restore','copy'],true))throw new InvalidArgumentException('Choose a supported report action.');
        return $this->transaction(function()use($input,$administrator,$key,$operation){
            $row=$this->one('SELECT settings_json FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);$settings=audienceJson($row['settings_json']);$reports=$settings['analytics_reports']??[];$old=null;$index=null;
            foreach($reports as$i=>$r)if($r['key']===$key){$old=$r;$index=$i;break;}
            if($key!==''&&(!$old||(!$administrator&&$old['owner']!==$this->actorId&&empty($old['shared']))))throw new InvalidArgumentException('This report is unavailable.',403);
            if(!empty($old['deleted'])&&$operation!=='restore')throw new InvalidArgumentException('Restore this removed report before using it.',403);
            if($old&&!in_array($operation,['favorite','copy'],true)&&!$administrator&&$old['owner']!==$this->actorId)throw new InvalidArgumentException('Only the report owner or an administrator can change this report.',403);
            if($old&&(int)($input['report_version']??0)!==$old['version'])throw new InvalidArgumentException('This report changed. Reload before saving.');
            if($operation==='copy'){$input=array_replace($old['definition'],$input);$input['name']=$old['name'].' (copy)';$old=null;$index=null;$key='';}
            if($key===''){
                if($operation!=='save'&&$operation!=='copy')throw new InvalidArgumentException('Save the report first.');
                if(count(array_filter($reports,static fn($r)=>empty($r['deleted'])))>=50)throw new InvalidArgumentException('This audience can retain up to fifty active reports. Remove a report to free a place.');
                $key=bin2hex(random_bytes(12));$old=['key'=>$key,'owner'=>$this->actorId,'version'=>0,'favorites'=>[],'shared'=>false,'created_at'=>gmdate(DATE_ATOM)];$index=count($reports);
            }
            if($operation==='save'||$operation==='copy'){
                $name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter a report name.');
                $definition=audienceAnalyticsReportDefinition($input);$window=audienceAnalyticsWindow($input);
                foreach($window['messages']as$cid)if(!$this->one('SELECT id FROM marketing_campaigns WHERE id=? AND audience_id=?',[$cid,$this->audienceId]))throw new InvalidArgumentException('A selected message is unavailable.');
                $this->analyticsRecipientScope($window);
                $old['name']=$name;$old['description']=audienceText($input,'description',500);$old['definition']=$definition+array_intersect_key($window,array_flip(['start','end','bots','compare','channel','messages','messages_mode','segment']));$old['shared']=($input['shared']??'')==='1';
            }elseif($operation==='favorite'){$favorites=$old['favorites'];$actor=(string)$this->actorId;if(in_array($actor,$favorites,true))$favorites=array_values(array_diff($favorites,[$actor]));else$favorites[]=$actor;$old['favorites']=$favorites;}
            elseif($operation==='delete')$old['deleted']=true;
            elseif($operation==='restore'){if(count(array_filter($reports,static fn($r)=>empty($r['deleted'])))>=50)throw new InvalidArgumentException('Remove an active report before restoring this one.');$old['deleted']=false;}
            $old['version']++;$old['updated_at']=gmdate(DATE_ATOM);$reports[$index]=$old;$settings['analytics_reports']=$reports;
            $this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);return $key;
        });
    }

    /** Shared with optimized scheduling; unique recipients prevent repeated clicks dominating an hour. */
    public function analyticsTimingEvidence():array
    {
        $base="FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id AND r.audience_id=e.audience_id
            WHERE e.audience_id=? AND r.state='accepted' AND e.event_type='click' AND e.event_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
            AND e.event_at<=UTC_TIMESTAMP() AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')<>'true'";
        $contacts=(int)$this->one("SELECT COUNT(DISTINCT r.contact_id) n $base",[$this->audienceId])['n'];
        $hours=$this->all("SELECT HOUR(e.event_at) hour,COUNT(DISTINCT r.contact_id) contacts,COUNT(DISTINCT r.id) messages $base GROUP BY hour ORDER BY contacts DESC,messages DESC,hour",[$this->audienceId]);
        return ['contacts'=>$contacts,'hours'=>$hours,'best_hour'=>$hours?(int)$hours[0]['hour']:null,'ready'=>$contacts>=100];
    }

    public function analyticsContentPatterns(array $w,array $overview):array
    {
        $scopeWindow=array_replace($w,['from'=>min($w['from'],$w['previous_from']),'until'=>max($w['until'],$w['previous_until'])]);
        [$scope,$params]=$this->analyticsRecipientScope($scopeWindow);$subjects=[];
        foreach($this->all("SELECT DISTINCT d.id,d.campaign_id,d.snapshot_ciphertext FROM marketing_campaign_deliveries d JOIN marketing_campaign_recipients r ON r.delivery_id=d.id AND r.audience_id=d.audience_id WHERE $scope",$params)as$delivery){
            try{$snapshot=audienceJson(\Dnr\Security\ApplicationKey::open($delivery['snapshot_ciphertext']));$subject=$snapshot['draft']['subject']??null;if(is_string($subject))$subjects[$delivery['campaign_id']][$subject]=true;}catch(Throwable){/* Missing immutable content stays unknown. */}
        }
        $buckets=[];$prior=[];
        foreach(['cohorts','previous_cohorts']as$period){$grouped=[];
            foreach($overview[$period]as$row){$choices=array_keys($subjects[$row['campaign_id']]??[]);$length=count($choices)===1?mb_strlen($choices[0]):null;
                $label=count($choices)>1?'Multiple subjects':($length===null?'Subject unavailable':($length<=40?'Up to 40 characters':($length<=70?'41–70 characters':'Over 70 characters')));$grouped[$label][]=$row;
            }
            $out=[];foreach($grouped as$label=>$rows)$out[$label]=['label'=>$label]+$this->analyticsTotals($rows);
            if($period==='cohorts')$buckets=$out;else$prior=$out;
        }
        foreach($buckets as$key=>&$row)$row['previous']=$prior[$key]??null;unset($row);return array_values($buckets);
    }

    public function analyticsClickMap(array $w,int $delivery=0):array
    {
        [$scope,$params]=$this->analyticsRecipientScope($w);
        $options=$this->all("SELECT DISTINCT d.id,d.due_at,c.name FROM marketing_campaign_deliveries d JOIN marketing_campaign_recipients r ON r.delivery_id=d.id AND r.audience_id=d.audience_id JOIN marketing_campaigns c ON c.id=d.campaign_id AND c.audience_id=d.audience_id WHERE $scope ORDER BY d.due_at DESC,d.id DESC",$params);
        if(!$options)return ['options'=>[],'delivery'=>0,'html'=>''];
        $selected=null;foreach($options as$o)if(!$delivery||(int)$o['id']===$delivery){$selected=$o;break;}
        if(!$selected)throw new InvalidArgumentException('Choose a delivery available in this report.');
        $sealed=$this->one('SELECT snapshot_ciphertext FROM marketing_campaign_deliveries WHERE audience_id=? AND id=?',[$this->audienceId,$selected['id']]);
        $snapshot=audienceJson(\Dnr\Security\ApplicationKey::open($sealed['snapshot_ciphertext']));$draft=$snapshot['draft']??[];
        $plain=htmlspecialchars((string)($draft['text']??''),ENT_QUOTES,'UTF-8');$plain=preg_replace_callback('~https://[^\s<>]+~u',static fn($m)=>'<a href="'.$m[0].'">'.$m[0].'</a>',$plain);
        $html=isset($draft['design'])?audienceDesignHtml($draft['design']):('<!doctype html><html><head></head><body><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'.$plain.'</pre></body></html>');
        $bot=$w['bots']==='filtered'?" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')<>'true'":'';
        $counts=array_column($this->all("SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.link_hash')),'unknown') link_hash,COUNT(DISTINCT r.id) messages FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id AND r.audience_id=e.audience_id JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id WHERE $scope AND d.id=? AND e.event_type='click' AND e.event_at<? $bot AND NOT EXISTS(SELECT 1 FROM marketing_message_events failure WHERE failure.audience_id=r.audience_id AND failure.recipient_id=r.id AND failure.source='provider' AND failure.event_type IN ('hard-bounce','soft-bounce','rejected') AND failure.event_at<?) GROUP BY link_hash",[...$params,$selected['id'],$w['until'],$w['until']]),'messages','link_hash');
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);
        foreach(['script','iframe','frame','object','embed','form','input','button','video','audio','source','link','base','meta']as$tag)foreach(iterator_to_array($dom->getElementsByTagName($tag))as$node)$node->parentNode?->removeChild($node);
        foreach($dom->getElementsByTagName('*')as$node){foreach(iterator_to_array($node->attributes)as$attribute)if(str_starts_with(strtolower($attribute->name),'on'))$node->removeAttribute($attribute->name);}
        foreach(iterator_to_array($dom->getElementsByTagName('img'))as$node){$placeholder=$dom->createElement('div');$placeholder->appendChild($dom->createTextNode($node->getAttribute('alt')?:'Template image'));$placeholder->setAttribute('style','padding:30px;background:#eef1f2;color:#52616b;text-align:center');$node->parentNode?->replaceChild($placeholder,$node);}
        foreach(iterator_to_array($dom->getElementsByTagName('a'))as$node){$target=$node->getAttribute('href');$node->removeAttribute('href');$node->removeAttribute('target');$badge=$dom->createElement('span');
            $label=str_contains($target,'*|')?'Personalized link':((int)($counts[hash('sha256',$target)]??0).' observed clicks');$badge->appendChild($dom->createTextNode(' ['.$label.']'));$badge->setAttribute('style','background:#ffe781;color:#18252b;padding:4px 6px;font-size:12px;font-family:Arial,sans-serif');$node->appendChild($badge);}
        $nonce=contentSecurityPolicyNonce();$meta=$dom->createElement('meta');$meta->setAttribute('http-equiv','Content-Security-Policy');$meta->setAttribute('content',"default-src 'none'; style-src 'nonce-$nonce'; img-src 'none'; base-uri 'none'; form-action 'none'");$head=$dom->getElementsByTagName('head')->item(0);$head?->insertBefore($meta,$head->firstChild);
        $preview=audienceDesignPreviewSource($dom->saveHTML(),$nonce);foreach($options as&$o)unset($o['snapshot_ciphertext']);unset($o);
        return ['options'=>$options,'delivery'=>(int)$selected['id'],'html'=>$preview];
    }
}
