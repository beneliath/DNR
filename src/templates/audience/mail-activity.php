<section class="audience-panel mail-activity-panel">
<h2>Test and confirmation activity</h2>
<p>Accepted means the sending service accepted the email. Delivered means the recipient’s mail server accepted it; it does not confirm Inbox placement. Bounced means delivery was unsuccessful.</p>
<div class="campaign-table-scroll"><table class="data-table mail-activity-table">
<thead><tr><th scope="col">Email</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
<tbody><?php foreach($mailActivity as$m): $mailStatus=$m['delivery_status']; ?>
<tr data-service-mail-id="<?= (int)$m['id'] ?>">
<td><strong><?= $h($m['recipient']?:'Recipient unavailable') ?></strong><small><?= $h(ucfirst($m['kind'])) ?></small></td>
<td><?= $h($m['actor_label']?:'System') ?><small><time title="<?= $h($m['created_at']) ?> UTC"><?= $h(substr($m['created_at'],0,19)) ?> UTC</time></small></td>
<td><?= $h($mailStatus['label']) ?></td>
<td><?= $h($mailStatus['detail']) ?><?php if($mailStatus['event_at']): ?><small><time title="<?= $h($mailStatus['event_at']) ?> UTC"><?= $h(substr($mailStatus['event_at'],0,19)) ?> UTC</time></small><?php endif; ?>
<?php if($m['delivery_events']): ?><details><summary>Delivery history</summary><ul><?php foreach($m['delivery_events']as$event): ?><li><?= $h(match($event['event_type']){'send'=>'Accepted','delivered'=>'Delivered','hard-bounce'=>'Bounced (permanent)','soft-bounce'=>'Bounced (temporary)','delay'=>'Delayed','rejected'=>'Rejected','complaint'=>'Complaint',default=>$event['event_type']}) ?> · <time title="<?= $h($event['event_at']) ?> UTC"><?= $h(substr($event['event_at'],0,19)) ?> UTC</time></li><?php endforeach; ?></ul></details><?php endif; ?></td>
</tr><?php endforeach; ?>
<?php if(!$mailActivity): ?><tr><td colspan="4">No test or confirmation emails yet.</td></tr><?php endif; ?></tbody>
</table></div>
</section>
