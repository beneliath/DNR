<?php
declare(strict_types=1);

/** Explicit import policy: identify devotional campaigns, not every message to the old audience. */
function audienceMailchimpDailyDevotional(array $report):bool
{
    return (bool)preg_match('/^\s*DD(?:[\s_.\/-]|\d)|daily[\s_-]+devot/iu',$report['title'])||(bool)preg_match('/daily[\s_-]+devot/iu',$report['subject']);
}

/** The campaign CSV's summary only. Recipient activity, URLs and demographics are not imported. */
function audienceMailchimpReport(string $csv,string $timezone,string $filter,string $sourceId=''):array
{
    if(strlen($csv)>2*1024*1024||!mb_check_encoding($csv,'UTF-8'))throw new InvalidArgumentException('Choose a UTF-8 campaign report under 2 MB.');
    if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Specify the source account timezone.');
    if(!in_array($filter,['filtered','unfiltered','unknown'],true))throw new InvalidArgumentException('Specify the export bot-filter provenance.');
    if($sourceId!==''&&!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$sourceId))throw new InvalidArgumentException('Invalid source campaign identifier.');
    $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
    $first=fgetcsv($stream,0,',','"','');if($first!==['Email Campaign Report'])throw new InvalidArgumentException('This is not a Mailchimp Email Campaign Report CSV.');
    $values=[];$rows=0;
    while(($row=fgetcsv($stream,0,',','"',''))!==false){
        if(++$rows>100)throw new InvalidArgumentException('The campaign summary is too large.');
        if($row===[]||$row===[null])continue;
        if(count($row)===1&&$row[0]==='Clicks by URL')break;
        if(count($row)===1&&$row[0]==='Overall Stats')continue;
        if(count($row)!==2||isset($values[$row[0]]))throw new InvalidArgumentException('The campaign summary has an unsupported or duplicate field.');
        $values[$row[0]]=$row[1];
    }fclose($stream);
    $text=static function(string $key,int $max)use($values):string{$value=trim($values[$key]??'');if($value===''||mb_strlen($value)>$max||preg_match('/[\x00-\x1f]/',$value))throw new InvalidArgumentException('Invalid or missing campaign '.$key.'.');return $value;};
    $title=$text('Title',250);$subject=$text('Subject Line',998);$local=$text('Delivery Date/Time',100);
    $date=DateTimeImmutable::createFromFormat('!l, F j, Y \a\t g:i A',$local,new DateTimeZone($timezone));
    if(!$date||$date->format('l, F j, Y \a\t g:i A')!==$local||(int)$date->format('Y')<2000||$date>new DateTimeImmutable('+1 day'))throw new InvalidArgumentException('The delivery timestamp is invalid or needs an explicit source timezone.');
    $counts=[];$displayRates=[];
    foreach(['Total Recipients'=>'accepted','Successful Deliveries'=>'delivered','Bounces'=>'bounced','Recipients Who Opened'=>'opens','Total Opens'=>'total_opens','Recipients Who Clicked'=>'clicks','Total Clicks'=>'total_clicks','Total Unsubs'=>'unsubscribed','Total Abuse Complaints'=>'complaints']as$label=>$key){
        $value=$values[$label]??'';
        if(!preg_match('/^([0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?: \(([0-9]+(?:\.[0-9]+)?)%\))?$/D',$value,$match))throw new InvalidArgumentException('Missing or invalid count: '.$label.'.');
        $counts[$key]=(int)str_replace(',','',$match[1]);if($counts[$key]>100000000)throw new InvalidArgumentException('Campaign counts exceed the supported limit.');
        if(isset($match[2])){if((float)$match[2]>100)throw new InvalidArgumentException('Invalid campaign percentage.');$displayRates[$key]=(float)$match[2];}
    }
    if($counts['delivered']+$counts['bounced']!==$counts['accepted']||max($counts['opens'],$counts['clicks'],$counts['unsubscribed'],$counts['complaints'])>$counts['delivered']||$counts['total_opens']<$counts['opens']||$counts['total_clicks']<$counts['clicks'])throw new InvalidArgumentException('Campaign counts do not reconcile.');
    $sent=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    // Missing vendor IDs use an explicit deterministic fingerprint, never a native campaign ID.
    return ['source_key'=>$sourceId!==''?'id:'.$sourceId:'fingerprint:'.hash('sha256',$title."\n".$local."\n".$timezone),'title'=>$title,'subject'=>$subject,'sent_at'=>$sent,'metrics'=>$counts,'provenance'=>['provider'=>'mailchimp','source_id'=>$sourceId?:null,'source_local_time'=>$local,'source_timezone'=>$timezone,'bot_filter'=>$filter,'reported_percentages'=>$displayRates,'identity'=>$sourceId!==''?'vendor-id':'title-and-send-time fingerprint','scope'=>'lifetime campaign summary as exported; not event-time activity']];
}

/** Mailchimp's all-reports bundle contains one campaigns.csv with stable vendor IDs. */
function audienceMailchimpCampaigns(string $csv,string $timezone,string $filter):array
{
    if(strlen($csv)>16*1024*1024||!mb_check_encoding($csv,'UTF-8'))throw new InvalidArgumentException('Choose a UTF-8 campaign summary CSV under 16 MB.');
    $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);$header=fgetcsv($stream,0,',','"','');
    $map=['Total Recipients'=>'Total Recipients','Successful Deliveries'=>'Successful Deliveries','Total Bounces'=>'Bounces','Unique Opens'=>'Recipients Who Opened','Total Opens'=>'Total Opens','Unique Clicks'=>'Recipients Who Clicked','Total Clicks'=>'Total Clicks','Unsubscribes'=>'Total Unsubs','Abuse Complaints'=>'Total Abuse Complaints'];
    $required=['Title','Subject','Audience','Send Date','Unique Id','Soft Bounces','Hard Bounces','Open Rate','Click Rate',...array_keys($map)];
    if(!is_array($header)||count(array_unique($header))!==count($header)||array_diff($required,$header))throw new InvalidArgumentException('This is not a supported all-campaigns report CSV.');
    $reports=[];$line=1;
    while(($row=fgetcsv($stream,0,',','"',''))!==false){$line++;if($row===[null])continue;if(count($row)!==count($header)||count($reports)>=20000)throw new InvalidArgumentException('Invalid or oversized campaign summary at row '.$line.'.');$fields=array_combine($header,$row);
        try{
            if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Specify the source account timezone.');
            $date=DateTimeImmutable::createFromFormat('!M d, Y h:i a',$fields['Send Date'],new DateTimeZone($timezone));
            if(!$date||$date->format('M d, Y h:i a')!==$fields['Send Date'])throw new InvalidArgumentException('Invalid source send timestamp.');
            $temp=fopen('php://temp','w+');$write=static fn(array $values)=>fputcsv($temp,$values,',','"','');
            $write(['Email Campaign Report']);$write(['Title',$fields['Title']]);$write(['Subject Line',$fields['Subject']]);$write(['Delivery Date/Time',$date->format('l, F j, Y \a\t g:i A')]);$write(['Overall Stats']);
            foreach($map as$key=>$label)$write([$label,$fields[$key]]);rewind($temp);$single=stream_get_contents($temp);fclose($temp);
            if(trim($fields['Unique Id'])==='')throw new InvalidArgumentException('A stable vendor campaign ID is required.');
            $report=audienceMailchimpReport($single,$timezone,$filter,$fields['Unique Id']);
            $audience=trim($fields['Audience']);if($audience===''||mb_strlen($audience)>250||preg_match('/[\x00-\x1f]/',$audience))throw new InvalidArgumentException('Invalid original audience name.');
            foreach(['Soft Bounces'=>'soft_bounces','Hard Bounces'=>'hard_bounces']as$key=>$metric){if(!preg_match('/^[0-9]{1,9}$/D',$fields[$key]))throw new InvalidArgumentException('Invalid bounce count.');$report['metrics'][$metric]=(int)$fields[$key];}
            if($report['metrics']['soft_bounces']+$report['metrics']['hard_bounces']!==$report['metrics']['bounced'])throw new InvalidArgumentException('Bounce totals do not reconcile.');
            $rates=[];foreach(['Open Rate'=>'opens','Click Rate'=>'clicks']as$key=>$metric){if(!preg_match('/^([0-9]+(?:\.[0-9]+)?)%$/D',$fields[$key],$m)||(float)$m[1]>100)throw new InvalidArgumentException('Invalid vendor report percentage.');$rates[$metric]=(float)$m[1];}
            $report['provenance']=array_replace($report['provenance'],['source_local_time'=>$fields['Send Date'],'source_audience'=>$audience,'reported_percentages'=>$rates,'format'=>'Mailchimp all-reports campaigns.csv']);$reports[]=$report;
        }catch(InvalidArgumentException $error){throw new InvalidArgumentException('Campaign row '.$line.': '.$error->getMessage());}
    }fclose($stream);if(!$reports)throw new InvalidArgumentException('The campaign summary is empty.');return $reports;
}

function audienceMailchimpTotals(array $reports):array
{
    $totals=array_fill_keys(['accepted','delivered','opens','clicks','bounced','complaints','unsubscribed','total_opens','total_clicks','receipt','detected_bot'],0);
    foreach($reports as$report)foreach(array_keys($totals)as$key)$totals[$key]+=(int)($report['metrics'][$key]??0);
    $totals['delivery_rate']=audienceAnalyticsRate($totals['delivered'],$totals['accepted']);
    $totals['bounce_rate']=audienceAnalyticsRate($totals['bounced'],$totals['accepted']);
    foreach(['opens'=>'open_rate','clicks'=>'click_rate','unsubscribed'=>'unsubscribe_rate']as$count=>$key)$totals[$key]=audienceAnalyticsRate($totals[$count],$totals['delivered']);
    $totals['clicks_per_open']=audienceAnalyticsRate($totals['clicks'],$totals['opens']);
    return $totals;
}

trait AudienceMailchimpHistory
{
    public function mailchimpHistorySummary():array
    {
        return $this->one('SELECT COUNT(*) campaigns,MIN(sent_at) first_sent,MAX(sent_at) last_sent,MAX(imported_at) last_imported FROM marketing_historical_campaigns WHERE audience_id=?',[$this->audienceId]);
    }
    public function importMailchimpHistory(array $reports,string $bundleHash,string $filename,bool $apply=false):array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$bundleHash)||count($reports)<1||count($reports)>20000||mb_strlen($filename)>250)throw new InvalidArgumentException('Invalid history bundle.');
        return $this->transaction(function()use($reports,$bundleHash,$filename,$apply):array{
            // One audience lock serializes imports and preserves concurrent account settings.
            $this->one('SELECT id FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);$seen=[];$counts=['added'=>0,'updated'=>0,'unchanged'=>0];
            foreach($reports as$report){
                $key=$report['source_key'];$canonical=json_encode($report,JSON_THROW_ON_ERROR);$digest=hash('sha256',$canonical);
                if(isset($seen[$key])){if($seen[$key]!==$digest)throw new InvalidArgumentException('Conflicting reports share the same campaign identity. Supply vendor IDs before importing.');continue;}$seen[$key]=$digest;
                $old=$this->one('SELECT id,content_sha256 FROM marketing_historical_campaigns WHERE audience_id=? AND source_key=?',[$this->audienceId,$key]);
                $state=!$old?'added':($old['content_sha256']===$digest?'unchanged':'updated');$counts[$state]++;
                if(!$apply||$state==='unchanged')continue;
                $this->write('INSERT INTO marketing_historical_campaigns(audience_id,source_key,name,subject,sent_at,metrics_json,provenance_json,content_sha256,bundle_sha256,source_filename,imported_by) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),subject=VALUES(subject),sent_at=VALUES(sent_at),metrics_json=VALUES(metrics_json),provenance_json=VALUES(provenance_json),content_sha256=VALUES(content_sha256),bundle_sha256=VALUES(bundle_sha256),source_filename=VALUES(source_filename),imported_by=VALUES(imported_by),imported_at=UTC_TIMESTAMP(6)',[$this->audienceId,$key,$report['title'],$report['subject'],$report['sent_at'],json_encode($report['metrics'],JSON_THROW_ON_ERROR),json_encode($report['provenance'],JSON_THROW_ON_ERROR),$digest,$bundleHash,$filename,$this->actorId]);
            }
            return $counts+['applied'=>$apply,'campaigns'=>count($seen),'bundle_sha256'=>$bundleHash];
        });
    }
    public function mailchimpHistoryAudiences():array
    {
        return $this->all("SELECT JSON_UNQUOTE(JSON_EXTRACT(provenance_json,'$.source_audience')) name,COUNT(*) campaigns FROM marketing_historical_campaigns WHERE audience_id=? AND JSON_EXTRACT(provenance_json,'$.source_audience') IS NOT NULL GROUP BY JSON_UNQUOTE(JSON_EXTRACT(provenance_json,'$.source_audience')) ORDER BY 1",[$this->audienceId]);
    }
    public function mailchimpHistoryReports(string $from,string $until,string $sourceAudience=''):array
    {
        $rows=$this->all('SELECT * FROM marketing_historical_campaigns WHERE audience_id=? AND sent_at>=? AND sent_at<?'.($sourceAudience!==''?" AND JSON_UNQUOTE(JSON_EXTRACT(provenance_json,'$.source_audience'))=?":'').' ORDER BY sent_at DESC,id DESC',[$this->audienceId,$from,$until,...($sourceAudience!==''?[$sourceAudience]:[])]);
        foreach($rows as&$row){$row['metrics']=audienceJson($row['metrics_json']);$row['provenance']=audienceJson($row['provenance_json']);}unset($row);return $rows;
    }
    public function mailchimpHistoryOverview(array $window):array
    {
        if($window['messages_mode']!=='all'||$window['segment']||$window['channel']!=='all')throw new InvalidArgumentException('Mailchimp summary history does not contain recipient membership or email-type filters.');
        $sourceAudience=$window['source_audience']??'';$sources=$this->mailchimpHistoryAudiences();if($sourceAudience!==''&&!in_array($sourceAudience,array_column($sources,'name'),true))throw new InvalidArgumentException('This original Mailchimp audience is unavailable.');
        $current=$this->mailchimpHistoryReports($window['from'],$window['until'],$sourceAudience);$prior=$this->mailchimpHistoryReports($window['previous_from'],$window['previous_until'],$sourceAudience);
        $daily=[];for($day=new DateTimeImmutable($window['start']);$day->format('Y-m-d')<=$window['end'];$day=$day->modify('+1 day'))$daily[$day->format('Y-m-d')]=['accepted'=>0,'opens'=>0,'clicks'=>0];
        foreach($current as$row){$day=substr($row['sent_at'],0,10);foreach(['accepted','opens','clicks']as$key)$daily[$day][$key]+=$row['metrics'][$key];}
        return ['totals'=>audienceMailchimpTotals($current),'previous'=>audienceMailchimpTotals($prior),'daily'=>$daily,'includes_samples'=>false,'campaigns'=>$current,'history_summary'=>$this->mailchimpHistorySummary(),'source_audiences'=>$sources,'origin'=>'mailchimp','cohorts'=>[],'previous_cohorts'=>[],'collections'=>[],'flows'=>[],'audience'=>[]];
    }
}
