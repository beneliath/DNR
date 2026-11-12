<?php $place=audienceInteractionPlace($observed);
    if($place!=='' || !empty($observed['ip_address'])): ?>
<p><strong>Recorded location:</strong> <?= $h($place!==''?$place:'Unavailable') ?></p>
<?php $estimate=audienceInteractionCurrentEstimate($observed,$estimateLookup); if($estimate!==null): ?>
<p><strong>Current IP estimate:</strong> <?= $h($estimate['place']) ?></p>
<?php endif; ?>
<?php if($canManage&&!empty($observed['ip_address'])): ?><p class="audience-help">IP Address: <?= $h($observed['ip_address']) ?></p><?php endif; ?>
<?php endif; ?>
