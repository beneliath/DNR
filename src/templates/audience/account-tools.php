<?php
// Shared account controls for the marketing workspace and email designer.
$accountNotifications = isset($store) && $store instanceof AudienceStore && $canManage
    ? $store->userNotifications()
    : [];
$accountPhotoVersion = (string) ($_SESSION['profile_picture_version'] ?? 0);
?>
<div class="audience-account-tools" aria-label="Account tools">
    <button type="button" class="audience-notification-trigger" popovertarget="audience-notifications" aria-label="Notifications" title="Notifications">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4M12 2V1"/></svg>
    </button>
    <a href="profile.php" class="audience-account" aria-label="Open profile for <?= $h($displayName) ?>" title="Your profile"><img src="profile_picture.php?v=<?= $h(rawurlencode($accountPhotoVersion)) ?>" alt="" width="40" height="40"></a>
    <section popover id="audience-notifications" class="audience-notifications" aria-labelledby="audience-notifications-title">
        <header><h2 id="audience-notifications-title">Notifications</h2><button type="button" popovertarget="audience-notifications" popovertargetaction="hide" aria-label="Close notifications">×</button></header>
        <?php if ($accountNotifications): ?>
        <p class="audience-notification-intro">Your recent audience activity</p><ul>
            <?php foreach ($accountNotifications as $notification): ?>
            <li><a href="audience.php?view=job&amp;id=<?= (int) $notification['id'] ?>"><strong><?= $h(ucfirst($notification['job_type']).' '.match($notification['state']){'complete'=>'complete','failed'=>'needs attention','processing'=>'in progress','queued'=>'queued',default=>'updated'}) ?></strong><span><?= $h($notification['name']) ?></span><small><?= $h(gmdate('M j, Y, g:i A', strtotime($notification['updated_at'].' UTC'))) ?> UTC<?= (int) $notification['errors'] > 0 ? ' · '.(int) $notification['errors'].' row errors' : '' ?></small></a></li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><div class="audience-notifications-empty"><strong>You’re all caught up</strong><p>No recent notifications to show.</p></div><?php endif; ?>
    </section>
</div>
