<?php
declare(strict_types=1);
require_once __DIR__.'/audience_analytics_reports.php';
require_once __DIR__.'/audience_engagement.php';
require_once __DIR__.'/audience_profile_engagement.php';

function audienceAnalyticsPresets(?DateTimeImmutable $now=null):array
{
    $today=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->setTime(0,0);
    $presets=[];
    foreach ([7,30,90,365] as $days) {
        $presets[$days]=['label'=>"Last $days days",'start'=>$today->modify('-'.($days-1).' days')->format('Y-m-d'),'end'=>$today->format('Y-m-d')];
    }
    $sunday=$today->modify('-'.$today->format('w').' days');$month=$today->modify('first day of this month');
    $quarter=$today->setDate((int)$today->format('Y'),1+3*intdiv((int)$today->format('n')-1,3),1);
    foreach(['week'=>['Last week',$sunday->modify('-7 days'),$sunday->modify('-1 day')],
        'month'=>['Last month',$month->modify('-1 month'),$month->modify('-1 day')],
        'quarter'=>['Last quarter',$quarter->modify('-3 months'),$quarter->modify('-1 day')],
        'qtd'=>['Quarter-to-date',$quarter,$today->modify('-1 day')],
        'ytd'=>['Year-to-date',$today->setDate((int)$today->format('Y'),1,1),$today->modify('-1 day')]]as$key=>[$label,$start,$finish]){
        if($start<=$finish)$presets[$key]=['label'=>$label,'start'=>$start->format('Y-m-d'),'end'=>$finish->format('Y-m-d')];
    }
    return $presets;
}

/** UTC calendar windows are shared by the dashboard, comparisons and exports. */
function audienceAnalyticsWindow(array $input, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $end = audienceText($input, 'end', 10) ?: $now->format('Y-m-d');
    $start = audienceText($input, 'start', 10) ?: $now->modify('-29 days')->format('Y-m-d');
    foreach ([$start, $end] as $day) {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $day)
            || !checkdate((int)substr($day, 5, 2), (int)substr($day, 8, 2), (int)substr($day, 0, 4))) {
            throw new InvalidArgumentException('Choose valid report dates.');
        }
    }
    $from = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    $until = (new DateTimeImmutable($end, new DateTimeZone('UTC')))->modify('+1 day');
    $days = (int)$from->diff($until)->format('%r%a');
    if ($days < 1 || $days > 3660 || $end > $now->format('Y-m-d')) {
        throw new InvalidArgumentException('Choose a date range of up to 10 years (3,660 days) ending today or earlier.');
    }
    if (!in_array(audienceText($input, 'origin', 20), ['', 'native'], true)
        || audienceText($input, 'source_audience', 250) !== '') {
        throw new InvalidArgumentException('This analytics source is no longer available. Use MessageMonkey results.');
    }
    $bots = audienceText($input, 'bots', 10) ?: 'filtered';
    if (!in_array($bots, ['filtered', 'all'], true)) throw new InvalidArgumentException('Choose a supported bot filter.');
    $channel=audienceText($input,'channel',20)?:'all';
    if(!in_array($channel,['all','regular','automation'],true))throw new InvalidArgumentException('Choose a supported email type.');
    $compare=audienceText($input,'compare',20)?:'previous';
    if(!in_array($compare,['previous','year','none'],true))throw new InvalidArgumentException('Choose a supported comparison.');
    $messages=$input['messages']??[];
    if(!is_array($messages)||count($messages)>100)throw new InvalidArgumentException('Choose up to 100 messages.');
    foreach($messages as$id)if(!is_scalar($id)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',(string)$id)||(int)$id>2147483647)throw new InvalidArgumentException('Choose valid messages.');
    $messages=array_values(array_unique(array_map('intval',$messages)));
    $messageMode=audienceText($input,'messages_mode',10)?:'all';
    if(!in_array($messageMode,['all','selected'],true))throw new InvalidArgumentException('Choose a supported message filter.');
    $segment=$input['segment']??'0';if(!is_scalar($segment)||!preg_match('/\A[0-9]{1,10}\z/D',(string)$segment)||(int)$segment>2147483647)throw new InvalidArgumentException('Choose a valid saved segment.');
    return ['start'=>$start, 'end'=>$end, 'from'=>$from->format('Y-m-d H:i:s'),
        'until'=>$until->format('Y-m-d H:i:s'), 'days'=>$days, 'bots'=>$bots,'channel'=>$channel,'messages'=>$messages,'messages_mode'=>$messageMode,'compare'=>$compare,'segment'=>(int)$segment,
        'previous_from'=>$from->modify($compare==='year'?'-1 year':'-'.$days.' days')->format('Y-m-d H:i:s'),
        'previous_until'=>($compare==='year'?$until->modify('-1 year'):$from)->format('Y-m-d H:i:s')];
}

function audienceAnalyticsRate(int $numerator, int $denominator): ?float
{
    return $denominator > 0 ? round(100 * $numerator / $denominator, 2) : null;
}

function audienceAnalyticsTrend(int|float|null $current,int|float|null $previous,string $metric,bool $enabled=true):string
{
    if(!$enabled||$current===null||$previous===null||$previous==0)return '<span class="analytics-trend steady" title="No comparable data">—</span>';
    $change=100*($current-$previous)/$previous;
    if(abs($change)<0.05)return '<span class="analytics-trend steady">—</span>';
    $better=in_array($metric,['unsubscribe_rate','unsubscribed','bounce_rate','bounced'],true)?$change<0:$change>0;
    $tone=$better?'improved':($metric==='open_rate'?'muted':'declined');
    $label=($change>0?'Up ':'Down ').number_format(abs($change),1).'%';
    $path=$change>0?'M11 3 21 20H1Z':'M1 2h20L11 20Z';
    return '<span class="analytics-trend '.$tone.'" title="Relative change versus comparison period" aria-label="'.$label.'"><svg viewBox="0 0 22 22" aria-hidden="true"><path d="'.$path.'"/></svg><span>'.rtrim(rtrim(number_format(abs($change),1),'0'),'.').'%</span></span>';
}

trait AudienceAnalytics
{
    use AudienceAnalyticsReports;
    use AudienceProfileEngagement;
    /** One row per accepted message prevents repeated events and joins inflating rates. */
    public function analyticsRecipients(string $from, string $until, string $bots, array $filters=[]): array
    {
        $columns=audienceEngagementColumns($bots);
        [$scope,$params]=$this->analyticsRecipientScope(['from'=>$from,'until'=>$until,'channel'=>$filters['channel']??'all','messages_mode'=>$filters['messages_mode']??'all','messages'=>$filters['messages']??[],'segment'=>$filters['segment']??0,'contact_id'=>$filters['contact_id']??0,'delivery_id'=>$filters['delivery_id']??0]);
        return $this->all("SELECT campaign_id,day,COUNT(*) accepted,SUM(delivered) delivered,SUM(opens) opens,
            SUM(clicks) clicks,SUM(bounced) bounced,SUM(complaints) complaints,SUM(receipt) receipt,
            SUM(detected_bot) detected_bot,SUM(unsubscribed) unsubscribed,SUM(failed) failed,SUM(rejected) rejected,
            SUM(total_opens) total_opens,SUM(total_clicks) total_clicks FROM (SELECT r.id,r.delivery_id,d.campaign_id,DATE(r.accepted_at) day,
            $columns,
            EXISTS(SELECT 1 FROM marketing_contact_events ce WHERE ce.contact_id=r.contact_id
                AND ce.event_type='status' AND JSON_UNQUOTE(JSON_EXTRACT(ce.details_json,'$.to'))='unsubscribed'
                AND JSON_UNQUOTE(JSON_EXTRACT(ce.details_json,'$.recipient_id'))=CAST(r.id AS CHAR)
                AND ce.created_at<?) unsubscribed
            FROM marketing_campaign_recipients r
            JOIN marketing_campaign_deliveries d ON d.audience_id=r.audience_id AND d.id=r.delivery_id
            LEFT JOIN marketing_message_events e ON e.audience_id=r.audience_id AND e.recipient_id=r.id AND e.event_at<?
            WHERE $scope
            GROUP BY r.id,r.delivery_id,d.campaign_id,DATE(r.accepted_at)) cohorts GROUP BY campaign_id,day",
            [$until,$until,...$params]);
    }

    public function analyticsTotals(array $recipients): array
    {
        $totals = array_fill_keys(['accepted','delivered','opens','clicks','total_opens','total_clicks','bounced','failed','rejected','complaints','receipt','detected_bot','unsubscribed'], 0);
        foreach ($recipients as $row) foreach (array_keys($totals) as $key) {
            $totals[$key] += (int)($row[$key] ?? 0);
        }
        $totals['delivery_rate'] = $totals['receipt'] ? audienceAnalyticsRate($totals['delivered'],$totals['accepted']) : null;
        $totals['successful']=max(0,$totals['accepted']-$totals['failed']);
        $totals['awaiting_receipt']=max(0,$totals['successful']-$totals['delivered']);
        foreach (['opens'=>'open_rate','clicks'=>'click_rate','unsubscribed'=>'unsubscribe_rate','complaints'=>'complaint_rate'] as $key=>$rate) {
            $totals[$rate] = audienceAnalyticsRate($totals[$key],$totals['successful']);
        }
        $totals['clicks_per_open'] = audienceAnalyticsRate($totals['clicks'],$totals['opens']);
        $totals['bounce_rate'] = audienceAnalyticsRate($totals['bounced'],$totals['accepted']);
        return $totals;
    }

    /** Full lifetime report totals, including repeat observations and unsubscribes. */
    public function analyticsCampaignMetrics(array $ids):array
    {
        if(!$ids)return [];
        $groups=[];
        foreach($this->analyticsRecipients('1970-01-01 00:00:00',gmdate('Y-m-d H:i:s',time()+1),'filtered',['messages_mode'=>'selected','messages'=>$ids])as$row)$groups[(int)$row['campaign_id']][]=$row;
        $result=[];foreach($ids as$id)$result[(int)$id]=$this->analyticsTotals($groups[(int)$id]??[]);
        return $result;
    }

    /** These views need unique receipt outcomes per campaign, not daily report rows or counts of repeat observations. */
    private function analyticsHeadlineRows(array $window,bool $home):array
    {
        [$scope,$params]=$this->analyticsRecipientScope($window);
        $keys=['failed','delivered','opens','clicks'];
        if($home)array_push($keys,'receipt','detected_bot');
        $columns=audienceEngagementColumns($window['bots'],$keys);
        $sums=implode(',',array_map(static fn($key)=>"SUM(m.$key) $key",$keys));
        $types=$home?'':" AND e.event_type IN ('open','click','delivered','hard-bounce','soft-bounce','rejected')";
        $join='';$extra='';
        if($home){
            // A repeated status event counts once. Match both the contact and the exact receipt ID,
            // and keep the report cutoff even when the same receipt was unsubscribed later.
            $join="LEFT JOIN (SELECT DISTINCT ce.contact_id,ce.details_json->>'$.recipient_id' recipient_key
                FROM marketing_contact_events ce JOIN marketing_contacts c ON c.id=ce.contact_id AND c.audience_id=?
                WHERE ce.event_type='status' AND ce.created_at<? AND ce.details_json->>'$.to'='unsubscribed'
                AND ce.details_json->>'$.recipient_id' IS NOT NULL) u ON u.contact_id=m.contact_id AND u.recipient_key=CAST(m.id AS CHAR)";
            $extra=',SUM(u.contact_id IS NOT NULL) unsubscribed';
            array_push($params,$this->audienceId,$window['until']);
        }
        return $this->all("SELECT m.campaign_id,COUNT(*) accepted,$sums $extra FROM (
            SELECT r.id,r.contact_id,MAX(d.campaign_id) campaign_id,$columns
            FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.audience_id=r.audience_id AND d.id=r.delivery_id
            LEFT JOIN marketing_message_events e ON e.audience_id=r.audience_id AND e.recipient_id=r.id AND e.event_at<? $types
            WHERE $scope GROUP BY r.id) m $join GROUP BY m.campaign_id",[$window['until'],...$params]);
    }

    /** Lifetime open/click rates and pending receipts for just the visible page. */
    public function analyticsCampaignListMetrics(array $ids):array
    {
        if(!$ids)return [];
        $rows=array_column($this->analyticsHeadlineRows(['from'=>'1970-01-01 00:00:00','until'=>gmdate('Y-m-d H:i:s',time()+1),'bots'=>'filtered',
            'channel'=>'all','messages_mode'=>'selected','messages'=>$ids],false),null,'campaign_id');
        $keys=array_flip(['open_rate','click_rate','awaiting_receipt']);$result=[];
        foreach($ids as$id)$result[(int)$id]=array_intersect_key($this->analyticsTotals(isset($rows[$id])?[$rows[$id]]:[]),$keys);
        return $result;
    }

    public function analyticsHomeMetrics(array $window,bool $drafts=false):array
    {
        $current=$this->analyticsHeadlineRows($window,true);
        $prior=$window['compare']==='none'?[]:$this->analyticsHeadlineRows(array_replace($window,['from'=>$window['previous_from'],'until'=>$window['previous_until']]),true);
        $byId=array_column($current,null,'campaign_id');
        $fields=array_flip(['accepted','failed','delivered','successful','receipt','opens','clicks','detected_bot','unsubscribed',
            'awaiting_receipt','delivery_rate','open_rate','click_rate','clicks_per_open','unsubscribe_rate']);
        $totals=fn(array $rows)=>array_intersect_key($this->analyticsTotals($rows),$fields);
        $campaigns=$this->analyticsOverviewCampaigns($window,$drafts);
        foreach($campaigns as&$campaign)$campaign['metrics']=$totals(isset($byId[$campaign['id']])?[$byId[$campaign['id']]]:[]);
        unset($campaign);
        return ['totals'=>$totals($current),'previous'=>$totals($prior),'campaigns'=>$campaigns];
    }

    private function analyticsOverviewCampaigns(array $window,bool $drafts):array
    {
        return $this->all("SELECT id,name,status,sent_at,updated_at FROM marketing_campaigns
            WHERE audience_id=? AND deleted_at IS NULL AND ((status='draft' AND ?=1)
                OR (status<>'draft' AND COALESCE(sent_at,updated_at)>=? AND COALESCE(sent_at,updated_at)<?))
            ORDER BY updated_at DESC,id DESC LIMIT 10",[$this->audienceId,(int)$drafts,$window['from'],$window['until']]);
    }

    private function validateAnalyticsMessages(array $window): void
    {
        $messages=array_values(array_unique($window['messages']??[]));
        if(!$messages)return;
        $placeholders=implode(',',array_fill(0,count($messages),'?'));
        $count=$this->one("SELECT COUNT(*) n FROM marketing_campaigns WHERE audience_id=? AND id IN ($placeholders)",[$this->audienceId,...$messages]);
        if((int)$count['n']!==count($messages))throw new InvalidArgumentException('A selected message is unavailable in this audience.');
    }

    private function analyticsIncludesSamples(array $window): bool
    {
        return $this->one("SELECT 1 FROM marketing_campaign_deliveries d JOIN marketing_campaigns c ON c.audience_id=d.audience_id AND c.id=d.campaign_id WHERE d.audience_id=? AND d.due_at>=? AND d.due_at<? AND JSON_UNQUOTE(JSON_EXTRACT(c.draft_json,'$.sample_dataset'))='stage7-local' LIMIT 1",[$this->audienceId,$window['from'],$window['until']])!==null;
    }

    public function analyticsReportMetadata(array $window): array
    {
        $this->validateAnalyticsMessages($window);
        return ['includes_samples'=>$this->analyticsIncludesSamples($window)];
    }

    private function analyticsRecentCollections(array $window): array
    {
        return $this->all("SELECT c.id,c.name,c.kind,
            (SELECT COUNT(*) FROM marketing_collection_responses r WHERE r.audience_id=c.audience_id AND r.resource_id=c.id AND r.created_at>=? AND r.created_at<?) responses,
            (SELECT COUNT(*) FROM marketing_collection_visits v WHERE v.resource_id=c.id AND v.day>=? AND v.day<=?) daily_visitors
            FROM marketing_collection_resources c WHERE c.audience_id=? AND c.deleted_at IS NULL ORDER BY c.updated_at DESC LIMIT 5",
            [$window['from'],$window['until'],$window['start'],$window['end'],$this->audienceId]);
    }

    public function analyticsConversionReport(array $window): array
    {
        $metadata=$this->analyticsReportMetadata($window);
        $current=$this->analyticsRecipients($window['from'],$window['until'],$window['bots'],$window);
        return $metadata+['totals'=>$this->analyticsTotals($current),
            'collections'=>$this->analyticsRecentCollections($window),
            'forms'=>$this->analyticsReportRows($window,['dataset'=>'forms']),
            'flows'=>$this->analyticsReportRows($window,['dataset'=>'flows'])];
    }

    public function analyticsOverview(array $window, bool $drafts = false, bool $includeEmailMetrics = true): array
    {
        $metadata=$this->analyticsReportMetadata($window);
        $audience = $this->one("SELECT COUNT(*) total,SUM(email_status='subscribed') subscribed,
            SUM(email_status='unsubscribed') unsubscribed,SUM(email_status='non-subscribed') non_subscribed,
            SUM(email_status='cleaned') cleaned,SUM(email_status='pending') pending,
            SUM(created_at>=? AND created_at<?) added FROM marketing_contacts
            WHERE audience_id=? AND is_archived=0 AND deleted_at IS NULL",[$window['from'],$window['until'],$this->audienceId]);
        $audience['visible_total']=(int)$audience['total']-(int)$audience['cleaned']-(int)$audience['pending'];
        $snapshot = ['audience' => $audience] + $metadata;
        if (!$includeEmailMetrics) {
            // Validate a bookmarked segment even when the view only uses the snapshot.
            if (!empty($window['segment'])) $this->analyticsRecipientScope($window);
            return $snapshot;
        }
        $rows = $this->analyticsRecipients($window['from'],$window['until'],$window['bots'],$window);
        $priorRows=$this->analyticsRecipients($window['previous_from'],$window['previous_until'],$window['bots'],$window);
        $previous = $this->analyticsTotals($priorRows);
        $byCampaign = []; $daily = [];
        for ($day = new DateTimeImmutable($window['start']); $day->format('Y-m-d') <= $window['end']; $day=$day->modify('+1 day')) {
            $daily[$day->format('Y-m-d')] = ['accepted'=>0,'opens'=>0,'clicks'=>0];
        }
        foreach ($rows as $row) {
            $byCampaign[(int)$row['campaign_id']][] = $row;
            $daily[$row['day']]['accepted'] += (int)$row['accepted'];
            foreach (['opens','clicks'] as $key) $daily[$row['day']][$key] += (int)$row[$key];
        }
        $campaigns=$this->analyticsOverviewCampaigns($window,$drafts);
        foreach ($campaigns as &$campaign) $campaign['metrics']=$this->analyticsTotals($byCampaign[(int)$campaign['id']]??[]);
        unset($campaign);
        $collection=$this->analyticsRecentCollections($window);
        $flows=$this->all("SELECT f.id,f.name,f.state,
            (SELECT COUNT(*) FROM marketing_flow_runs r WHERE r.audience_id=f.audience_id AND r.flow_id=f.id AND r.started_at>=? AND r.started_at<?) runs
            FROM marketing_flows f WHERE f.audience_id=? AND f.deleted_at IS NULL ORDER BY f.updated_at DESC LIMIT 5",
            [$window['from'],$window['until'],$this->audienceId]);
        return $snapshot + ['totals'=>$this->analyticsTotals($rows),'previous'=>$previous,'cohorts'=>$rows,'previous_cohorts'=>$priorRows,
            'daily'=>$daily,'campaigns'=>$campaigns,'collections'=>$collection,'flows'=>$flows];
    }
}
