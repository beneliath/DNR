<?php $insights=$store->personInsights($id);$insightTotals=$insights['totals']; ?>
<h2>Email engagement</h2>
<p class="audience-help">Lifetime native email activity · known bot activity excluded</p>
<?php if(!$insightTotals['accepted']): ?>
<div class="audience-empty"><h3>No email activity yet</h3><p>This connection has no accepted MessageMonkey campaign messages.</p></div>
<?php else: ?>
<dl class="audience-insight-metrics"><?php foreach(['accepted'=>'Emails accepted','delivered'=>'Confirmed deliveries','opens'=>'Emails opened','clicks'=>'Emails clicked','bounced'=>'Bounced','complaints'=>'Complaints']as$key=>$label): ?><div><dt><?= $h($label) ?></dt><dd><?= number_format($insightTotals[$key]) ?></dd></div><?php endforeach; ?></dl>
<p>Open rate: <strong><?= $insightTotals['open_rate']===null?'—':$h(number_format($insightTotals['open_rate'],1)).'%' ?></strong> · Click rate: <strong><?= $insightTotals['click_rate']===null?'—':$h(number_format($insightTotals['click_rate'],1)).'%' ?></strong> · Pending delivery receipts: <?= (int)$insightTotals['awaiting_receipt'] ?></p>
<div class="audience-table-scroll"><table class="data-table audience-table audience-insight-table"><thead><tr><th>Campaign</th><th>Emails</th><th>Opened</th><th>Clicked</th><th>Open rate</th><th>Click rate</th></tr></thead><tbody>
<?php foreach($insights['campaigns']as$c): $m=$c['metrics']; ?><tr><th><a href="<?= $h($url(['view'=>'campaign-delivery','id'=>$c['id']])) ?>"><?= $h($c['name']) ?></a></th><td><?= (int)$m['accepted'] ?></td><td><?= (int)$m['opens'] ?></td><td><?= (int)$m['clicks'] ?></td><?php foreach(['open_rate','click_rate']as$key): ?><td><?= $m[$key]===null?'—':$h(number_format($m[$key],1)).'%' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<p class="audience-help"><?= $h(audienceEngagementExplanation()) ?> Observations do not prove that a person read an email.</p>
