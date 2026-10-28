<?php
// Shared account controls for the marketing workspace and email designer.
require_once __DIR__.'/../../audience_notification_state.php';
$notificationEnabled=isset($store)&&$store instanceof AudienceStore&&$canManage;
$notificationItems=$notificationEnabled?audienceBellItems($store):[];
$notificationCleared=$notificationEnabled?array_values(array_intersect(array_column($notificationItems,'key'),audienceBellDismissals($store))):[];
$notificationCount=count(array_diff(array_column($notificationItems,'key'),$notificationCleared));
$notificationScope=hash('sha256',currentAccountKey().':'.(int)($_SESSION['user_id']??0).':'.(int)($store->audienceId??1));
$accountPhotoVersion = (string) ($_SESSION['profile_picture_version'] ?? 0);
$notificationClearButton=static function(string $label)use($h):void{ ?>
    <button type="button" class="audience-notification-dismiss" data-notification-dismiss aria-label="Clear notification: <?= $h($label) ?>" title="Clear notification"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7m0-7-7 7"/></svg></button>
<?php };
?>
<div class="audience-account-tools" aria-label="Account tools">
    <?php if(hasRole(['admin'])): ?><button type="button" class="audience-admin-unlock-trigger" data-audience-admin-unlock data-admin-unlock-hint aria-label="Unlock administrator actions"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4M12 14v3"/></svg></button><?php endif; ?>
    <button type="button" class="audience-theme-trigger" data-theme-toggle aria-label="Switch to Dark Theme" aria-pressed="false">
        <svg class="audience-theme-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
        <svg class="audience-theme-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
    </button>
    <button type="button" class="audience-notification-trigger" popovertarget="audience-notifications" aria-label="Notifications" data-notification-scope="<?= $h($notificationScope) ?>"<?php if($notificationEnabled): ?> data-notification-endpoint="audience.php?api=notifications" data-notification-csrf="<?= $h(generateCsrfToken()) ?>" data-notification-cleared="<?= $h(json_encode($notificationCleared,JSON_THROW_ON_ERROR)) ?>"<?php endif; ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4M12 2V1"/></svg>
        <span class="audience-notification-badge" data-notification-count aria-hidden="true" hidden><?= $notificationCount ?></span>
    </button>
    <a href="profile.php" class="audience-account" aria-label="Open profile for <?= $h($displayName) ?>" title="Your profile"><img src="profile_picture.php?v=<?= $h(rawurlencode($accountPhotoVersion)) ?>" alt="" width="40" height="40"></a>
    <section popover id="audience-notifications" class="audience-notifications" aria-labelledby="audience-notifications-title">
        <header><h2 id="audience-notifications-title">Notifications</h2><button type="button" popovertarget="audience-notifications" popovertargetaction="hide" aria-label="Close notifications">×</button></header>
        <div class="audience-notification-actions" data-notification-actions<?= $notificationCount?'':' hidden' ?>><p class="audience-notification-intro">Your recent audience activity</p><button type="button" data-notification-clear-all>Dismiss all</button></div><ul data-notification-list<?= $notificationCount?'':' hidden' ?>>
            <?php foreach($notificationItems as $notification): ?>
            <li data-notification-key="<?= $h($notification['key']) ?>"<?= in_array($notification['key'],$notificationCleared,true)?' hidden':'' ?>><a href="<?= $h($notification['href']) ?>"><strong><?= $h($notification['label']) ?></strong><span><?= $h($notification['description']) ?></span><small><?= $h($notification['time']) ?></small></a><?php $notificationClearButton($notification['label']); ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="audience-notifications-empty" data-notification-empty<?= $notificationCount>0?' hidden':'' ?>><strong>You’re all caught up</strong><p>No recent notifications to show.</p></div>
    </section>
</div>

<?php renderScript('assets/js/theme.min.js'); ?>
<?php renderScript('assets/js/audience-notifications.min.js'); ?>
