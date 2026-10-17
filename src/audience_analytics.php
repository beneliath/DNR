<?php
declare(strict_types=1);
require_once __DIR__.'/audience_analytics_reports.php';

function audienceAnalyticsPresets(bool $home,?DateTimeImmutable $now=null):array
{
    $today=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->setTime(0,0);
    $end=$home?$today->modify('-1 day'):$today;$presets=[];
    foreach([7,30,90,365]as$n)$presets[(string)$n]=['label'=>'Last '.$n.' days','start'=>$end->modify('-'.($n-1).' days')->format('Y-m-d'),'end'=>$end->format('Y-m-d')];
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
    if ($days < 1 || $days > 366 || $end > $now->format('Y-m-d')) {
        throw new InvalidArgumentException('Choose a date range of up to 366 days ending today or earlier.');
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
    /** One row per accepted message prevents repeated events and joins inflating rates. */
    public function analyticsRecipients(string $from, string $until, string $bots, array $filters=[]): array
    {
        $bot = $bots === 'filtered' ? "AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')<>'true'" : '';
        [$scope,$params]=$this->analyticsRecipientScope(['from'=>$from,'until'=>$until,'channel'=>$filters['channel']??'all','messages_mode'=>$filters['messages_mode']??'all','messages'=>$filters['messages']??[],'segment'=>$filters['segment']??0]);
        return $this->all("SELECT campaign_id,day,COUNT(*) accepted,SUM(delivered) delivered,SUM(opens) opens,
            SUM(clicks) clicks,SUM(bounced) bounced,SUM(complaints) complaints,SUM(receipt) receipt,
            SUM(detected_bot) detected_bot,SUM(unsubscribed) unsubscribed FROM (SELECT r.id,r.delivery_id,d.campaign_id,DATE(r.accepted_at) day,
            MAX(e.event_type='delivered' AND e.source='provider') delivered,
            MAX(e.event_type='open' $bot) opens,MAX(e.event_type='click' $bot) clicks,
            MAX(e.event_type IN ('hard-bounce','soft-bounce') AND e.source='provider') bounced,
            MAX(e.event_type='complaint' AND e.source='provider') complaints,
            MAX(e.event_type IN ('send','delivered','delay','rejected','hard-bounce','soft-bounce','complaint') AND e.source='provider') receipt,
            MAX(e.event_type IN ('open','click') AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.bot')),'false')='true') detected_bot,
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
        $totals = array_fill_keys(['accepted','delivered','opens','clicks','bounced','complaints','receipt','detected_bot','unsubscribed'], 0);
        foreach ($recipients as $row) foreach (array_keys($totals) as $key) {
            $totals[$key] += (int)($row[$key] ?? 0);
        }
        $totals['delivery_rate'] = $totals['receipt'] ? audienceAnalyticsRate($totals['delivered'],$totals['accepted']) : null;
        foreach (['opens'=>'open_rate','clicks'=>'click_rate','unsubscribed'=>'unsubscribe_rate'] as $key=>$rate) {
            $totals[$rate] = audienceAnalyticsRate($totals[$key],$totals['accepted']);
        }
        $totals['clicks_per_open'] = audienceAnalyticsRate($totals['clicks'],$totals['opens']);
        $totals['bounce_rate'] = audienceAnalyticsRate($totals['bounced'],$totals['accepted']);
        return $totals;
    }

    public function analyticsOverview(array $window, bool $drafts = false): array
    {
        foreach($window['messages']??[] as$campaign)if(!$this->one('SELECT id FROM marketing_campaigns WHERE audience_id=? AND id=?',[$this->audienceId,$campaign]))throw new InvalidArgumentException('A selected message is unavailable in this audience.');
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
        $campaigns = $this->all("SELECT id,name,status,sent_at,updated_at FROM marketing_campaigns
            WHERE audience_id=? AND deleted_at IS NULL AND ((status='draft' AND ?=1)
                OR (status<>'draft' AND COALESCE(sent_at,updated_at)>=? AND COALESCE(sent_at,updated_at)<?))
            ORDER BY updated_at DESC,id DESC LIMIT 10",[$this->audienceId,(int)$drafts,$window['from'],$window['until']]);
        foreach ($campaigns as &$campaign) $campaign['metrics']=$this->analyticsTotals($byCampaign[(int)$campaign['id']]??[]);
        unset($campaign);
        $audience = $this->one("SELECT COUNT(*) total,SUM(email_status='subscribed') subscribed,
            SUM(email_status='unsubscribed') unsubscribed,SUM(email_status='non-subscribed') non_subscribed,
            SUM(email_status='cleaned') cleaned,SUM(email_status='pending') pending,
            SUM(created_at>=? AND created_at<?) added FROM marketing_contacts
            WHERE audience_id=? AND is_archived=0 AND deleted_at IS NULL",[$window['from'],$window['until'],$this->audienceId]);
        $audience['visible_total']=(int)$audience['total']-(int)$audience['cleaned']-(int)$audience['pending'];
        $collection=$this->all("SELECT c.id,c.name,c.kind,
            (SELECT COUNT(*) FROM marketing_collection_responses r WHERE r.audience_id=c.audience_id AND r.resource_id=c.id AND r.created_at>=? AND r.created_at<?) responses,
            (SELECT COUNT(*) FROM marketing_collection_visits v WHERE v.resource_id=c.id AND v.day>=? AND v.day<=?) daily_visitors
            FROM marketing_collection_resources c WHERE c.audience_id=? AND c.deleted_at IS NULL ORDER BY c.updated_at DESC LIMIT 5",
            [$window['from'],$window['until'],$window['start'],$window['end'],$this->audienceId]);
        $flows=$this->all("SELECT f.id,f.name,f.state,
            (SELECT COUNT(*) FROM marketing_flow_runs r WHERE r.audience_id=f.audience_id AND r.flow_id=f.id AND r.started_at>=? AND r.started_at<?) runs
            FROM marketing_flows f WHERE f.audience_id=? AND f.deleted_at IS NULL ORDER BY f.updated_at DESC LIMIT 5",
            [$window['from'],$window['until'],$this->audienceId]);
        $sample=$this->one("SELECT COUNT(*) n FROM marketing_campaign_deliveries d JOIN marketing_campaigns c ON c.audience_id=d.audience_id AND c.id=d.campaign_id WHERE d.audience_id=? AND d.due_at>=? AND d.due_at<? AND JSON_UNQUOTE(JSON_EXTRACT(c.draft_json,'$.sample_dataset'))='stage7-local'",[$this->audienceId,$window['from'],$window['until']]);
        return ['totals'=>$this->analyticsTotals($rows),'previous'=>$previous,'audience'=>$audience,'includes_samples'=>(int)$sample['n']>0,'cohorts'=>$rows,'previous_cohorts'=>$priorRows,
            'daily'=>$daily,'campaigns'=>$campaigns,'collections'=>$collection,'flows'=>$flows];
    }
}
