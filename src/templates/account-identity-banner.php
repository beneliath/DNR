<?php if (authenticatedSuperAdmin()): ?>
<section class="account-identity-banner" aria-label="Current Account">
    <span class="account-identity-label">
        <strong><?php echo htmlspecialchars(currentAccountProfile()['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
        <?php if (accountIsPrimary()): ?>
        <span class="account-primary-badge"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg>Primary Account</span>
        <?php endif; ?>
    </span>
    <?php if (activeRolePreview() !== null): ?>
    <form method="post" action="role_preview.php" class="account-identity-switch-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="role" value="superadmin">
        <input type="hidden" name="return_to" value="accounts.php">
        <button type="submit" class="account-identity-switch">Switch Account</button>
    </form>
    <?php else: ?>
    <a href="accounts.php">Switch Account</a>
    <?php endif; ?>
</section>
<?php endif; ?>
