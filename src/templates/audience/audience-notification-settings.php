<?php
$noticePrefs=$settings['subscriber_notifications']??[];
$noticeUsers=$store->audienceNotificationUsers();
$noticeError=$error!==''&&($_POST['action']??'')==='save_audience'&&isset($_POST['notifications_present']);
?>
<h3>Subscriber notifications</h3>
<input type="hidden" name="notifications_present" value="1">
<p class="audience-help">Receive individual subscription changes or a summary of the previous UTC day. Individual alerts start with new activity after these preferences are saved. Pending signups and imported snapshots do not count as subscriptions. Daily summary emails are queued at 11:11 p.m. America/Chicago every day, including days with no changes. Delivery may be delayed by the sending queue or provider limits.</p>
<?php foreach(['signup'=>'New subscriptions','unsubscribe'=>'Unsubscribes','digest'=>'Daily summary']as$kind=>$label): ?>
<label class="audience-check"><input type="checkbox" name="in_app_<?= $kind ?>" value="1"<?= ($noticeError?($_POST['in_app_'.$kind]??'')==='1':!empty($noticePrefs['in_app_'.$kind]))?' checked':'' ?>>Show <?= $h(strtolower($label)) ?> in the notification bell</label>
<label for="notification-<?= $kind ?>">Email <?= $h(strtolower($label)) ?> to</label>
<textarea id="notification-<?= $kind ?>" name="<?= $kind ?>_emails" rows="2" maxlength="2000" aria-describedby="notification-staff-help" placeholder="staff@example.org"><?= $h($noticeError?audienceText($_POST,$kind.'_emails',2000):implode(', ',$noticePrefs[$kind.'_emails']??[])) ?></textarea>
<?php endforeach; ?>
<p id="notification-staff-help" class="audience-help">Up to five comma-separated addresses per notification. Recipients must be active editors or administrators with verified email addresses in this account. Leave blank to disable email alerts. Email alerts use the approved MessageMonkey sender and sending controls; saving preferences does not enable sending.</p>
<?php if($noticeUsers): ?><details><summary>Eligible staff email addresses</summary><ul><?php foreach($noticeUsers as$user): ?><li><?= $h($user['email']) ?></li><?php endforeach; ?></ul></details><?php else: ?><p class="audience-help">No verified staff email addresses are currently eligible.</p><?php endif; ?>
