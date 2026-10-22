<?php
$homeHistory=$store->analyticsAudienceHistory($window);
$homeGrowth=$store->analyticsReportRows($window,['dataset'=>'audience','dimension'=>'week']);
include __DIR__.'/analytics-report-table.php';
?>
<section class="analytics-card"><header><h2>Audience growth</h2><a href="<?= $h($url(['view'=>'analytics','section'=>'audience','start'=>$window['start'],'end'=>$window['end'],'compare'=>$window['compare']])) ?>">View audience analytics →</a></header>
<dl class="analytics-metrics"><?php foreach(['added'=>'Connections added','subscriptions'=>'Observed subscriptions','net'=>'Observed net subscriber change']as$key=>$label): $value=array_sum(array_column($homeHistory['current'],$key));$prior=array_sum(array_column($homeHistory['previous'],$key)); ?><div><dt><?= $h($label) ?></dt><dd><?= number_format($value) ?></dd><?= audienceAnalyticsTrend($value,$prior,$key,$window['compare']!=='none') ?></div><?php endforeach; ?></dl>
<?php $renderReportPlots($homeGrowth,['added','net']);$renderReportTable($homeGrowth,['added','subscriptions','optouts','net'],'Week'); ?>
<p class="analytics-explanation">Additions use recorded join dates and include archived or cleaned connections. Net subscriber changes use recorded transitions, including exits from subscribed status. Imported snapshots cannot reconstruct a historical subscriber total.</p>
</section>
