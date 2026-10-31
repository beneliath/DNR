<?php if(is_array($detail['interaction_location']??null)):
    $observed=$detail['interaction_location'];
    $place=audienceInteractionPlace($observed);
    if($place!=='' || !empty($observed['ip_address'])): ?>
<p><strong>Approximate location:</strong> <?= $h($place!==''?$place:'Unavailable') ?></p>
<?php if($canManage&&!empty($observed['ip_address'])): ?><p class="audience-help">IP address: <?= $h($observed['ip_address']) ?></p><?php endif; ?>
<?php if(in_array($event['event_type'],['email_open','email_click'],true)): ?><p class="audience-help">Tracked email activity may reflect a mail service or scanner's location.</p><?php endif; ?>
<?php endif; endif; ?>
