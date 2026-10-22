<?php
require_once __DIR__ . '/../two_factor_helpers.php';
// Share the same session-bound unlock banner across MOED and MessageMonkey.
$admin_unlock_available = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
$admin_unlock_expires_at = $admin_unlock_available ? adminElevationExpiresAt() : null;
$admin_unlock_return_url = safeAdminElevationReturnUrl(
    basename((string) ($_SERVER['PHP_SELF'] ?? 'dashboard.php'))
        . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''),
    'dashboard.php'
);
?>
    <?php if ($admin_unlock_available): ?>
        <section class="admin-unlock-banner" data-admin-unlock data-expires-at="<?php echo $admin_unlock_expires_at ?? 0; ?>" data-server-now="<?php echo microtime(true); ?>" aria-label="Administrator Unlock Status" hidden>
            <div class="admin-unlock-copy">
                <strong role="status">Administrator actions unlocked</strong>
                <span>Sensitive actions are available until the timer expires.</span>
            </div>
            <form method="post" action="admin_lock.php" class="admin-unlock-lock-form" data-admin-lock-form>
                <?php echo csrfInput(); ?>
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($admin_unlock_return_url, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="admin-unlock-lock-button action-icon-button" formaction="admin_extend.php" data-admin-extend-button aria-label="Add 5 Minutes" data-tooltip="Add 5 Minutes"><?php echo actionIconSvg('extend'); ?></button>
                <button type="submit" class="admin-unlock-lock-button action-icon-button" data-admin-lock-button aria-label="Lock Admin Actions" data-tooltip="Lock Admin Actions"><?php echo actionIconSvg('lock'); ?></button>
                <span data-admin-lock-error role="alert" hidden></span>
            </form>
            <div class="admin-unlock-countdown">
                <span>Automatically locks in</span>
                <span data-admin-unlock-timer role="timer" aria-label="Time Until Administrator Actions Lock" aria-live="off"></span>
            </div>
        </section>
    <?php endif; ?>
<?php if ($admin_unlock_available) renderScript('assets/js/admin-unlock.min.js', false); ?>
