<?php
$totals=$overview['totals'];$previous=$overview['previous'];
$reportQuery=['view'=>'analytics','section'=>$section,'start'=>$window['start'],'end'=>$window['end'],'compare'=>$window['compare'],'bots'=>$window['bots'],'channel'=>$window['channel'],'messages_mode'=>$window['messages_mode'],'messages'=>$window['messages'],'metric'=>$metric,'group'=>$group,'delivery_metric'=>$deliveryMetric,'delivery_group'=>$deliveryGroup];
$reportQuery+=['source'=>$source,'activity'=>$activity,'link'=>$linkHash,'segment'=>$window['segment']];
if($definition)$reportQuery=array_replace($reportQuery,$definition,['columns_present'=>1,'plots_present'=>1]);
if($savedReport)$reportQuery['report']=$savedReport['key'];
$reportLink=static fn(array $changes=[]):string=>'audience.php?'.http_build_query(array_replace($reportQuery,$changes));
$rate=static fn($v):string=>$v===null?'—':rtrim(rtrim(number_format((float)$v,2),'0'),'.').'%';
$metricLabels=['accepted'=>'Total sends','open_rate'=>'Open rate','opens'=>'Opened','click_rate'=>'Click rate','clicks'=>'Clicked','clicks_per_open'=>'Clicks per unique opens','bounce_rate'=>'Bounce rate','bounced'=>'Bounces','unsubscribe_rate'=>'Unsubscribe rate','unsubscribed'=>'Unsubscribed','delivery_rate'=>'Delivery rate','delivered'=>'Deliveries'];
include __DIR__.'/analytics-report-table.php';
if($canDelete): $analyticsAiQuery=['view'=>'assistant','mode'=>'analytics','dataset'=>$definition['dataset']??($section==='audience'?'audience':($section==='conversions'?'forms':'email')),'source'=>$source]+array_intersect_key($window,array_flip(['start','end','compare','bots','channel','messages_mode','messages','segment'])); ?>
<div class="analytics-ai-tools"><p><a class="audience-button" href="<?= $h('audience.php?'.http_build_query($analyticsAiQuery)) ?>">Ask Analytics AI</a></p><?php include __DIR__.'/analytics-ai-questions.php'; ?></div>
<?php endif;
$renderMetrics=static function(array $keys,string $selected,bool $delivery=false)use($totals,$previous,$metricLabels,$h,$rate,$reportLink,$window):void{ ?>
<div class="analytics-metric-carousel"><div class="analytics-metric-strip"><?php foreach($keys as$key): $isRate=str_contains($key,'rate')||$key==='clicks_per_open'; ?><a class="analytics-metric-choice<?= $selected===$key?' active':'' ?>" href="<?= $h($reportLink([$delivery?'delivery_metric':'metric'=>$key])) ?>" role="button" aria-pressed="<?= $selected===$key?'true':'false' ?>"><span><?= $h($metricLabels[$key]) ?></span><strong><?= $h($isRate?$rate($totals[$key]):number_format($totals[$key])) ?></strong><?= audienceAnalyticsTrend($totals[$key],$previous[$key],$key,$window['compare']!=='none') ?></a><?php endforeach; ?></div><button type="button" data-analytics-scroll aria-label="Scroll metrics right">›</button></div>
<?php };
$renderChart=static function(string $chosen,string $chartGroup,bool $delivery=false)use($overview,$store,$metricLabels,$messageOptions,$window,$h,$reportLink,$rate):void{
    $labels=array_column($messageOptions,'name','id');$chartGroups=[];$priorGroups=[];
    foreach(['cohorts'=>&$chartGroups,'previous_cohorts'=>&$priorGroups]as$source=>&$target){
        foreach($overview[$source]as$row){$day=new DateTimeImmutable($row['day']);$key=match($chartGroup){'campaign'=>(string)$row['campaign_id'],'week'=>$day->modify('monday this week')->format('Y-m-d'),'month'=>$day->format('Y-m'),default=>$row['day']};$target[$key][]=$row;}
    }unset($target);
    if($chartGroup!=='campaign')ksort($chartGroups);
    $points=[];foreach($chartGroups as$key=>$rows)$points[]=['label'=>$chartGroup==='campaign'?($labels[$key]??'Email #'.$key):$key,'value'=>$store->analyticsTotals($rows)[$chosen]];
    $averages=[];foreach($priorGroups as$rows){$v=$store->analyticsTotals($rows)[$chosen];if($v!==null)$averages[]=$v;}
    $average=$averages?array_sum($averages)/count($averages):null;
    $highest=max([1,...array_map(static fn($p)=>(float)($p['value']??0),$points),$window['compare']!=='none'?($average??0):0]);$highest=ceil($highest/5)*5;
    $count=max(1,count($points));$step=860/$count;$isRate=str_contains($chosen,'rate')||$chosen==='clicks_per_open';
    ?>
    <div class="analytics-chart-controls" aria-label="Chart interval"><?php foreach(['campaign'=>'Campaign','day'=>'Day','week'=>'Week','month'=>'Month']as$key=>$label): ?><a class="<?= $chartGroup===$key?'active':'' ?>" href="<?= $h($reportLink([$delivery?'delivery_group':'group'=>$key])) ?>"<?= $chartGroup===$key?' aria-current="true"':'' ?>><?= $label ?></a><?php endforeach; ?></div>
    <?php if(!$points): ?><p class="analytics-empty">No email activity matches these filters.</p><?php endif; ?>
    <div class="analytics-plot"><svg viewBox="0 0 1000 320" role="img" aria-label="<?= $h($metricLabels[$chosen].' by '.$chartGroup) ?>">
    <?php for($i=0;$i<=4;$i++):$y=235-50*$i;$tick=$highest*$i/4; ?><line x1="70" x2="960" y1="<?= $y ?>" y2="<?= $y ?>" class="analytics-grid-line"/><text x="57" y="<?= $y+4 ?>" text-anchor="end"><?= $h($isRate?$rate($tick):number_format($tick)) ?></text><?php endfor; ?>
    <?php foreach($points as$i=>$point):$height=200*(float)($point['value']??0)/$highest;$x=85+$i*$step; ?><rect x="<?= $x+max(0,($step-min(55,$step*.65))/2) ?>" y="<?= 235-$height ?>" width="<?= min(55,$step*.65) ?>" height="<?= $height ?>" rx="2"><title><?= $h($point['label'].': '.($isRate?$rate($point['value']):number_format($point['value']??0))) ?></title></rect><text x="<?= $x+$step/2 ?>" y="260" text-anchor="middle"><?= $h(mb_strimwidth($point['label'],0,19,'…')) ?></text><?php endforeach; ?>
    <?php if($average!==null&&$window['compare']!=='none'): $y=235-200*$average/$highest; ?><line x1="70" x2="960" y1="<?= $y ?>" y2="<?= $y ?>" class="analytics-average-line"/><?php endif; ?>
    </svg></div><div class="analytics-chart-legend"><span>■ <?= $h($metricLabels[$chosen]) ?></span><?php if($window['compare']!=='none'): ?><span>┄ Previous period average</span><?php endif; ?></div>
    <details class="analytics-chart-data"><summary>View chart data</summary><div class="analytics-table-scroll"><table class="data-table"><thead><tr><th><?= $h(ucfirst($chartGroup)) ?></th><th><?= $h($metricLabels[$chosen]) ?></th></tr></thead><tbody><?php foreach($points as$p): ?><tr><td><?= $h($p['label']) ?></td><td><?= $h($isRate?$rate($p['value']):number_format($p['value']??0)) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
<?php };
?>
<div class="analytics-workspace analytics-dashboard">
<?php if($overview['includes_samples']): ?><p class="analytics-sample-note" role="note">Local sample data included · Campaigns named “Sample” are fictional and did not send email.</p><?php endif; ?>
<?php include __DIR__.'/analytics-filters.php'; ?>
<div data-analytics-results>
<?php if($window['segment']): ?><p class="analytics-explanation">Email results use the saved segment’s current active connection membership, rather than its membership at send time.</p><?php endif; ?>
<?php if(in_array($section,['reports','custom','activity','links','devices','insights'],true))include __DIR__.'/analytics-reports.php'; ?>
<?php if($section==='email'): ?>
<section class="analytics-card analytics-engagement" aria-labelledby="engagement-title"><header><h2 id="engagement-title">Email engagement</h2><a href="<?= $h($reportLink(['section'=>'activity'])) ?>">View recipient activity →</a></header>
    <nav class="analytics-tabs" aria-label="Email type"><?php foreach(['all'=>'All','regular'=>'Regular','automation'=>'Automation']as$key=>$label): ?><a class="<?= $window['channel']===$key?'active':'' ?>" href="<?= $h($reportLink(['channel'=>$key])) ?>"<?= $window['channel']===$key?' aria-current="true"':'' ?>><?= $label ?></a><?php endforeach; ?></nav>
    <?php $engagementMetric=in_array($metric,['accepted','open_rate','opens','click_rate','clicks','clicks_per_open'],true)?$metric:'accepted';$renderMetrics(['accepted','open_rate','opens','click_rate','clicks','clicks_per_open'],$engagementMetric);$renderChart($engagementMetric,$group); ?>
    <div class="analytics-insight-grid"><article><strong>Grow your audience</strong><p>Compare your sources and review subscriber activity.</p><a class="audience-button" href="<?= $h($reportLink(['section'=>'audience'])) ?>">View audience dashboard</a></article><article><strong>Keep your audience engaged</strong><p>Review your audience filters before choosing recipients.</p><a class="audience-button" href="<?= $h($url(['view'=>'segments'])) ?>">View segments</a></article></div>
</section>
<?php endif; ?>
<?php if($section==='email'||$section==='conversions'): ?>
<h2 class="analytics-section-title">Email funnel</h2><div class="analytics-funnel-grid"><section class="analytics-card"><header><h2>Email engagement funnel</h2><span>Count</span></header>
    <?php foreach(['accepted'=>'Sent','delivered'=>'Delivered','opens'=>'Opened','clicks'=>'Clicked']as$key=>$label):$width=$totals['accepted']?max(2,100*$totals[$key]/$totals['accepted']):0; ?><div class="analytics-funnel-step"><span><?= $label ?></span><strong><?= number_format($totals[$key]) ?></strong><meter min="0" max="<?= max(1,$totals['accepted']) ?>" value="<?= $totals[$key] ?>"><?= $width ?>%</meter></div><?php endforeach; ?>
    <p class="analytics-explanation">These are separate message observations, not guaranteed sequential steps. Delivery requires provider receipts; an open can be blocked or triggered by a privacy proxy. Purchases and external conversions are not inferred.</p></section>
    <section class="analytics-card"><header><h2>Forms and surveys</h2></header><p class="analytics-explanation">Native submissions and daily unique visitors, kept separate from confirmed subscriptions.</p><ul class="analytics-resource-list"><?php foreach($overview['collections']as$r): ?><li><a href="<?= $h($url(['view'=>'collection-report','id'=>$r['id']])) ?>"><?= $h($r['name']) ?></a><span><?= number_format((int)$r['responses']) ?> submissions · <?= number_format((int)$r['daily_visitors']) ?> daily unique visitors</span></li><?php endforeach; ?><?php if(!$overview['collections']): ?><li>No form results in this audience yet.</li><?php endif; ?></ul></section></div>
<?php endif; ?>
<?php if($section==='email'): ?>
<section class="analytics-card analytics-delivery"><h2>Delivery by channel</h2><p class="analytics-period">Email</p><?php $renderMetrics(['bounce_rate','bounced','unsubscribe_rate','unsubscribed','delivery_rate','delivered'],$deliveryMetric,true);$renderChart($deliveryMetric,$deliveryGroup,true); ?><p class="analytics-explanation">Provider-confirmed delivery and bounce events. Missing receipts do not prove failure. Known bots are <?= $window['bots']==='filtered'?'excluded from':'included in' ?> engagement metrics. <?= $h(audienceEngagementExplanation()) ?> Trends are relative changes. Pending delivery receipts: <?= (int)$totals['awaiting_receipt'] ?>.</p></section>
<?php endif; ?>
<?php if($section==='audience')include __DIR__.'/analytics-audience.php'; ?>
<?php if($section==='conversions')include __DIR__.'/analytics-funnels.php'; ?>
</div></div>
