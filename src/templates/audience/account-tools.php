<?php
// Shared account controls for the marketing workspace and email designer.
$accountNotifications = isset($store) && $store instanceof AudienceStore && $canManage
    ? $store->userNotifications()
    : [];
$inboxNotifications = $canManage && isset($store) && $store instanceof AudienceStore && $store->inboxAvailable() ? $store->inboxNotifications() : [];
$subscriberNotifications = $canManage && isset($store) && $store instanceof AudienceStore ? $store->subscriberNotifications() : [];
$notificationCount=count($accountNotifications)+count($inboxNotifications)+count($subscriberNotifications);
$notificationScope=hash('sha256',currentAccountKey().':'.(int)($_SESSION['user_id']??0).':'.(int)($store->audienceId??1));
$accountPhotoVersion = (string) ($_SESSION['profile_picture_version'] ?? 0);
$notificationClearButton=static function(string $label)use($h):void{ ?>
    <button type="button" class="audience-notification-dismiss" data-notification-dismiss aria-label="Clear notification: <?= $h($label) ?>" title="Clear notification"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7m0-7-7 7"/></svg></button>
<?php };
?>
<div class="audience-account-tools" aria-label="Account tools">
    <button type="button" class="audience-theme-trigger" data-theme-toggle aria-label="Switch to Dark Theme" aria-pressed="false" title="Change color theme">
        <svg class="audience-theme-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
        <svg class="audience-theme-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
    </button>
    <button type="button" class="audience-notification-trigger" popovertarget="audience-notifications" aria-label="Notifications" title="Notifications" data-notification-scope="<?= $h($notificationScope) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4M12 2V1"/></svg>
        <span class="audience-notification-badge" data-notification-count aria-hidden="true"<?= $notificationCount===0?' hidden':'' ?>><?= $notificationCount ?></span>
    </button>
    <a href="profile.php" class="audience-account" aria-label="Open profile for <?= $h($displayName) ?>" title="Your profile"><img src="profile_picture.php?v=<?= $h(rawurlencode($accountPhotoVersion)) ?>" alt="" width="40" height="40"></a>
    <section popover id="audience-notifications" class="audience-notifications" aria-labelledby="audience-notifications-title">
        <header><h2 id="audience-notifications-title">Notifications</h2><button type="button" popovertarget="audience-notifications" popovertargetaction="hide" aria-label="Close notifications">×</button></header>
        <?php if ($accountNotifications || $inboxNotifications || $subscriberNotifications): ?>
        <div class="audience-notification-actions" data-notification-actions><p class="audience-notification-intro">Your recent audience activity</p><button type="button" data-notification-clear-all>Clear all</button></div><ul data-notification-list>
            <?php foreach ($inboxNotifications as $notification): ?><li data-notification-key="<?= $h(hash('sha256','inbox:'.$notification['id'])) ?>"><a href="audience.php?view=inbox&amp;thread=<?= (int)$notification['thread_id'] ?>"><strong><?= $notification['kind']==='comment'?'New internal Inbox comment':'New Inbox message' ?></strong><span>Open conversation</span><small><?= $h($notification['created_at']) ?> UTC</small></a><?php $notificationClearButton($notification['kind']==='comment'?'New internal Inbox comment':'New Inbox message'); ?></li><?php endforeach; ?>
            <?php foreach($subscriberNotifications as$notification): ?><li data-notification-key="<?= $h(hash('sha256','subscriber:'.$notification['kind'].':'.($notification['id']??$notification['created_at']))) ?>"><a href="<?= $notification['kind']==='digest'?'audience.php?view=analytics&amp;section=audience':'audience.php?view=contact&amp;id='.(int)$notification['contact_id'] ?>"><strong><?= $notification['kind']==='digest'?'Daily audience summary':($notification['kind']==='signup'?'New audience subscription':'Audience unsubscribe') ?></strong><span><?= $notification['kind']==='digest'?(int)$notification['signup'].' subscriptions · '.(int)$notification['unsubscribe'].' unsubscribes':'Review connection activity' ?></span><small><?= $h($notification['created_at']) ?> UTC</small></a><?php $notificationClearButton($notification['kind']==='digest'?'Daily audience summary':($notification['kind']==='signup'?'New audience subscription':'Audience unsubscribe')); ?></li><?php endforeach; ?>
            <?php foreach ($accountNotifications as $notification): ?>
            <li data-notification-key="<?= $h(hash('sha256','job:'.$notification['id'].':'.$notification['state'].':'.$notification['updated_at'])) ?>"><a href="audience.php?view=job&amp;id=<?= (int) $notification['id'] ?>"><strong><?= $h(ucfirst($notification['job_type']).' '.match($notification['state']){'complete'=>'complete','failed'=>'needs attention','processing'=>'in progress','queued'=>'queued',default=>'updated'}) ?></strong><span><?= $h($notification['name']) ?></span><small><?= $h(gmdate('M j, Y, g:i A', strtotime($notification['updated_at'].' UTC'))) ?> UTC<?= (int) $notification['errors'] > 0 ? ' · '.(int) $notification['errors'].' row errors' : '' ?></small></a><?php $notificationClearButton($notification['name']); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?><div class="audience-notifications-empty" data-notification-empty<?= $notificationCount>0?' hidden':'' ?>><strong>You’re all caught up</strong><p>No recent notifications to show.</p></div>
    </section>
</div>

<?php renderScript('assets/js/theme.min.js'); ?>
<?php renderScript('assets/js/audience-notifications.min.js'); ?>
