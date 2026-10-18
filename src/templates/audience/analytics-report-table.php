<?php
$reportMetricLabels=audienceAnalyticsMetricLabels();
$formatReportMetric=static function($value,string $key):string{
    if($value===null)return '—';
    if(str_contains($key,'rate')||$key==='clicks_per_open')return rtrim(rtrim(number_format((float)$value,2),'0'),'.').'%';
    return number_format((float)$value);
};
$renderReportTable=static function(array $rows,array $columns,string $dimension,?string $destination=null)use($h,$url,$reportMetricLabels,$formatReportMetric,$window):void{ ?>
<div class="analytics-table-scroll"><table class="data-table analytics-report-table"><thead><tr><th><?= $h(ucfirst($dimension)) ?></th><?php foreach($columns as$key): ?><th><?= $h($reportMetricLabels[$key]) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as$row): ?><tr><th scope="row"><?php if($destination&&!empty($row['id'])): ?><a href="<?= $h($url($destination==='activity'?['view'=>'analytics','section'=>'activity','messages_mode'=>'selected','messages'=>[$row['id']],'start'=>$window['start'],'end'=>$window['end'],'bots'=>$window['bots']]:['view'=>$destination,'id'=>$row['id']])) ?>"><?= $h($row['label']) ?></a><?php else: ?><?= $h($row['label']) ?><?php endif; ?></th><?php foreach($columns as$key): ?><td><?= $h($formatReportMetric($row[$key]??null,$key)) ?><?php if(isset($row['previous'][$key])&&$window['compare']!=='none'): ?><small class="analytics-prior">Previous: <?= $h($formatReportMetric($row['previous'][$key],$key)) ?></small><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?= count($columns)+1 ?>">No matching observations for these dates and filters.</td></tr><?php endif; ?>
</tbody></table></div>
<?php };
$renderReportPlots=static function(array $rows,array $plots)use($h,$reportMetricLabels,$formatReportMetric):void{ ?>
<div class="analytics-report-plots"><?php foreach($plots as$key):$max=max([1,...array_map(static fn($r)=>abs((float)($r[$key]??0)),$rows)]); ?><section class="analytics-card"><h3><?= $h($reportMetricLabels[$key]) ?></h3><div class="analytics-horizontal-bars" role="img" aria-label="<?= $h($reportMetricLabels[$key].' by report dimension; exact values in results table') ?>"><?php foreach(array_slice($rows,count($rows)>12?-12:0,12)as$r): ?><div><span title="<?= $h($r['label']) ?>"><?= $h($r['label']) ?></span><div><svg viewBox="0 0 100 18" preserveAspectRatio="none" aria-hidden="true"><rect class="<?= ($r[$key]??0)<0?'negative':'' ?>" width="<?= round(100*abs((float)($r[$key]??0))/$max,3) ?>" height="18"/></svg></div><strong><?= $h($formatReportMetric($r[$key]??null,$key)) ?></strong></div><?php endforeach; ?></div><?php if(count($rows)>12): ?><p class="analytics-explanation">Latest twelve groups shown. All groups appear in the table.</p><?php endif; ?></section><?php endforeach; ?></div>
<?php };
