<?php
// The Home chart groups transitions by week, regardless of acquisition source.
$homeHistory=$store->analyticsAudienceHistory($window,bySource:false);
$homeGrowth=$store->analyticsReportRows($window,['dataset'=>'audience','dimension'=>'week'],$homeHistory);
include __DIR__.'/analytics-report-table.php';
?>
<section class="analytics-card analytics-growth"><header><h2>Audience Growth</h2><a href="<?= $h($url(['view'=>'analytics','section'=>'audience','start'=>$window['start'],'end'=>$window['end'],'compare'=>$window['compare']])) ?>">View Audience Analytics →</a></header>
<dl class="analytics-metrics"><?php foreach(['added'=>'Connections Added','subscriptions'=>'Observed Subscriptions','net'=>'Observed Net Subscriber Change']as$key=>$label): $value=array_sum(array_column($homeHistory['current'],$key));$prior=array_sum(array_column($homeHistory['previous'],$key)); ?><div><dt><?= $h($label) ?></dt><dd><?= number_format($value) ?></dd><?= audienceAnalyticsTrend($value,$prior,$key,$window['compare']!=='none') ?></div><?php endforeach; ?></dl>
<?php $renderReportPlots($homeGrowth,['added','net']);$renderReportTable($homeGrowth,['added','subscriptions','optouts','net'],'Week'); ?>
<p class="analytics-explanation">Additions use recorded join dates and include archived or cleaned connections. Net subscriber changes use recorded transitions, including exits from subscribed status. Imported snapshots cannot reconstruct a historical subscriber total.</p>
</section>
