<?php
$totals=$overview['totals'];$previous=$overview['previous'];$a=$overview['audience'];
$reportQuery=['view'=>'analytics','start'=>$window['start'],'end'=>$window['end'],'bots'=>$window['bots']];
$rate=static fn($value):string=>$value===null?'—':rtrim(rtrim(number_format((float)$value,2),'0'),'.').'%';
$metrics=['accepted'=>['Total sends',false],'delivery_rate'=>['Delivery rate',true],'open_rate'=>['Open rate',true],'click_rate'=>['Click rate',true],'clicks_per_open'=>['Clicks per unique opens',true],'unsubscribe_rate'=>['Unsubscribed rate',true]];
?>
<div class="analytics-home-layout">
<?php if($canDelete): ?><div class="analytics-home-toolbar">
    <a class="audience-button" href="<?= $h($url(['view'=>'assistant','mode'=>'analytics']+array_intersect_key($window,array_flip(['start','end','compare','bots','channel','messages_mode','messages','segment'])))) ?>">Ask Analytics AI</a>
</div><?php endif; ?>
<div class="analytics-workspace">
<div class="analytics-home-date-tools"><?php include __DIR__.'/analytics-filters.php'; ?></div>
<?php if($overview['includes_samples']): ?><p class="analytics-sample-note" role="note">Local sample data included · Campaigns named “Sample” are fictional and did not send email.</p><?php endif; ?>
<section class="analytics-card" aria-labelledby="snapshot-heading">
    <header><h2 id="snapshot-heading">Email snapshot</h2><details class="analytics-bot-info"><summary><?= $window['bots']==='filtered'?'● Known bots excluded':'All observed data' ?></summary><div><h3>Bot filtering</h3><p>Exclude known automated opens and clicks. Privacy proxy opens may still be counted.</p><form method="get"><?php audienceInputs(['view'=>'home','start'=>$window['start'],'end'=>$window['end'],'drafts'=>$showDrafts?'1':'0']); ?><label>Engagement data<select name="bots" data-analytics-submit><option value="filtered"<?= $window['bots']==='filtered'?' selected':'' ?>>Known bots excluded</option><option value="all"<?= $window['bots']==='all'?' selected':'' ?>>Include detected bots</option></select></label></form></div></details><?php if($view==='home'): ?><a class="audience-button" href="<?= $h($url($reportQuery)) ?>">Email dashboard →</a><?php endif; ?></header>
    <dl class="analytics-metrics">
    <?php foreach($metrics as$key=>[$label,$isRate]): $value=$totals[$key];$before=$previous[$key]; ?>
        <div><dt><?= $h($label) ?></dt><dd><?= $h($isRate?$rate($value):number_format($value)) ?></dd><small><?= audienceAnalyticsTrend($value,$before,$key,$window['compare']!=='none') ?></small></div>
    <?php endforeach; ?>
    </dl>
    <p class="analytics-explanation">Total sends counts SES-accepted messages. Delivery requires provider receipts. Opens and clicks are observations, not proof a person read the email; privacy tools can affect them. <?= $h(audienceEngagementExplanation()) ?> Pending delivery receipts: <?= (int)$totals['awaiting_receipt'] ?>. <?= number_format($totals['detected_bot']) ?> messages have detected bot activity.</p>
    <?php if(!$totals['accepted']): ?><p class="analytics-empty">No accepted email in this date range. Your results will appear here after qualified sending.</p><?php endif; ?>
</section>
<div class="analytics-home-grid">
    <section class="analytics-card" aria-labelledby="audience-snapshot"><header><h2 id="audience-snapshot">Audience</h2><a href="<?= $h($url(['view'=>'contacts'])) ?>">View connections →</a></header>
        <dl class="analytics-audience"><div><dt>Total connections</dt><dd><?= number_format($a['visible_total']) ?></dd></div><div><dt>Email subscribers</dt><dd><?= number_format((int)$a['subscribed']) ?></dd></div><div><dt>Active connections added in this period</dt><dd><?= number_format((int)$a['added']) ?></dd></div></dl>
        <p class="analytics-explanation">Current active audience. Total connections excludes pending and cleaned addresses. Observed subscription changes appear below; imported join dates cannot reconstruct past subscriber totals.</p>
    </section>
    <?php include __DIR__.'/analytics-home-growth.php'; ?>
    <section class="analytics-card analytics-recent-campaigns" aria-labelledby="recent-campaigns"><header><h2 id="recent-campaigns">Recent campaigns</h2><form method="get" class="analytics-show-drafts"><?php audienceInputs(['view'=>'home','start'=>$window['start'],'end'=>$window['end'],'bots'=>$window['bots']]); ?><label>Show drafts <input type="checkbox" role="switch" name="drafts" value="1" data-analytics-submit<?= $showDrafts?' checked':'' ?>></label></form><a href="<?= $h($url(['view'=>'campaigns'])) ?>">All campaigns →</a></header>
        <div class="analytics-table-scroll"><table class="data-table"><thead><tr><th>Name</th><th>Status</th><th>Sent in period</th><th>Open rate</th><th>Click rate</th><th><span class="visually-hidden">Report</span></th></tr></thead><tbody>
        <?php foreach($overview['campaigns'] as$c): ?><tr><td><a href="<?= $h($url(['view'=>'campaign','id'=>$c['id']])) ?>"><?= $h($c['name']) ?></a></td><td><span class="campaign-status"><?= $h(ucfirst($c['status'])) ?></span></td><td><?= number_format($c['metrics']['accepted']) ?></td><td><?= $h($rate($c['metrics']['open_rate'])) ?></td><td><?= $h($rate($c['metrics']['click_rate'])) ?></td><td><a href="<?= $h($url(['view'=>'campaign-delivery','id'=>$c['id']])) ?>">View report</a></td></tr><?php endforeach; ?>
        <?php if(!$overview['campaigns']): ?><tr><td colspan="6">No campaigns to show.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php if($view==='home'&&$canManage): ?><section class="analytics-card"><header><h2>Start with a template</h2><a href="<?= $h($url(['view'=>'templates'])) ?>">View email templates →</a></header><div class="analytics-quick-actions"><a class="audience-button" href="<?= $h($url(['view'=>'campaigns','create'=>1])) ?>">Create email</a><a class="audience-button" href="<?= $h($url(['view'=>'flow-templates'])) ?>">Choose a flow template</a><a class="audience-button" href="<?= $h($url(['view'=>'collection-create','kind'=>'hosted'])) ?>">Create signup form</a></div></section><?php endif; ?>
</div>
</div>
