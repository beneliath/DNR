<?php
$banner_preview_role = activeRolePreview();
if ($banner_preview_role !== null):
    $banner_preview_label = $banner_preview_role === 'admin' ? 'Administrator' : ucfirst($banner_preview_role);
    $banner_preview_return_url = safeRolePreviewReturnUrl(
        basename($_SERVER['PHP_SELF'] ?? '')
            . (!empty($_SERVER['QUERY_STRING']) ? '?' . (string) $_SERVER['QUERY_STRING'] : ''),
        (string) ($_SESSION['role'] ?? '')
    );
?>
<section class="role-preview-banner" data-role-preview-banner aria-label="Administrator Role Preview">
    <div class="role-preview-banner-copy">
        <strong>Viewing as <?php echo htmlspecialchars($banner_preview_label, ENT_QUOTES, 'UTF-8'); ?></strong>
        <span>Navigation and permissions reflect this role. Actions still affect live data.</span>
    </div>
    <form method="post" action="role_preview.php" class="role-preview-return-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="role" value="superadmin">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($banner_preview_return_url, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="button-secondary">Return to SuperAdmin</button>
    </form>
</section>
<?php endif; ?>
