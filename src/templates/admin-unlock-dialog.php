<?php if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin'): ?>
<dialog id="admin-unlock-dialog" class="confirmation-dialog" aria-labelledby="admin-unlock-title" aria-describedby="admin-unlock-description">
    <h2 id="admin-unlock-title">Confirm Administrator Access</h2>
    <p id="admin-unlock-description" class="dialog-supporting-text">Enter your current password<?php if (empty($_SESSION['_demo_password_only_auth'])): ?> and a fresh authenticator or recovery code<?php endif; ?> to unlock sensitive actions for five minutes. Your current page will stay open.</p>
    <p data-unlock-error class="dialog-inline-error" role="alert" hidden></p>
    <form method="post" action="admin_elevation.php" autocomplete="off" data-admin-unlock-dialog-form>
        <?php echo csrfInput(); ?>
        <label for="unlock-password" class="confirmation-dialog-label">Administrator Password</label>
        <input type="password" id="unlock-password" name="admin_password" class="confirmation-dialog-input" autocomplete="current-password" maxlength="72" required>
        <?php if (empty($_SESSION['_demo_password_only_auth'])): ?>
            <label for="unlock-code" class="confirmation-dialog-label">Fresh Authenticator Code or Recovery Code</label>
            <input type="text" id="unlock-code" name="admin_code" class="confirmation-dialog-input" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>
        <?php else: ?>
            <input type="hidden" name="admin_code" value="">
        <?php endif; ?>
        <div class="confirmation-dialog-actions">
            <button type="button" class="button-secondary" data-unlock-cancel>Cancel</button>
            <button type="submit" class="button-primary" data-unlock-submit>Unlock Sensitive Actions</button>
        </div>
    </form>
</dialog>
<?php renderScript('assets/js/admin-unlock-dialog.min.js'); ?>
<?php endif; ?>
