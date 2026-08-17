<footer class="app-footer">
    <p>&copy; <?php echo date("Y"); ?> beneliath <span aria-hidden="true">·</span> DNR <?php echo htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : '0.1.1', ENT_QUOTES, 'UTF-8'); ?></p>
</footer>

<dialog id="logout-confirmation" class="confirmation-dialog" aria-labelledby="logout-confirmation-title">
    <h2 id="logout-confirmation-title">Log out?</h2>
    <p>You’ll need to sign in again to manage DNR records.</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-logout" class="button-secondary">Cancel</button>
        <button type="button" id="confirm-logout" class="danger-button">Log out</button>
    </div>
</dialog>

<dialog id="delete-confirmation" class="confirmation-dialog" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
    <h2 id="delete-confirmation-title">Delete permanently?</h2>
    <p id="delete-confirmation-message">Are you sure you want to delete this item?</p>
    <p class="dialog-supporting-text">Archive it instead to keep the record available for later restoration.</p>
    <div class="confirmation-dialog-actions">
        <button type="button" id="cancel-delete" class="button-secondary" autofocus>Cancel</button>
        <button type="button" id="archive-instead" class="archive-button">Archive instead</button>
        <button type="button" id="confirm-delete" class="danger-button">Delete permanently</button>
    </div>
</dialog>

<script>
(function () {
    const logoutForm = document.getElementById('logout-form');
    const confirmation = document.getElementById('logout-confirmation');
    const cancelButton = document.getElementById('cancel-logout');
    const confirmButton = document.getElementById('confirm-logout');
    let logoutConfirmed = false;

    if (logoutForm && confirmation && cancelButton && confirmButton) {
        logoutForm.addEventListener('submit', function (event) {
            if (!logoutConfirmed) {
                event.preventDefault();
                confirmation.showModal();
            }
        });
        cancelButton.addEventListener('click', function () { confirmation.close(); });
        confirmButton.addEventListener('click', function () {
            logoutConfirmed = true;
            logoutForm.requestSubmit();
        });
    }
})();

(function () {
    const confirmation = document.getElementById('delete-confirmation');
    const confirmationMessage = document.getElementById('delete-confirmation-message');
    const cancelButton = document.getElementById('cancel-delete');
    const archiveButton = document.getElementById('archive-instead');
    const confirmButton = document.getElementById('confirm-delete');
    let pendingForm = null;
    let pendingSubmitter = null;

    if (!confirmation || !confirmationMessage || !cancelButton || !archiveButton || !confirmButton) return;

    document.querySelectorAll('form[data-delete-confirmation]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.deleteConfirmed === 'true') return;
            event.preventDefault();
            pendingForm = form;
            pendingSubmitter = event.submitter;
            confirmationMessage.textContent = form.dataset.deleteConfirmation;
            archiveButton.textContent = form.dataset.archiveButtonLabel || 'Archive instead';
            confirmation.showModal();
        });
    });

    cancelButton.addEventListener('click', function () {
        pendingForm = null;
        pendingSubmitter = null;
        confirmation.close();
    });
    confirmation.addEventListener('cancel', function () {
        pendingForm = null;
        pendingSubmitter = null;
    });
    archiveButton.addEventListener('click', function () {
        if (!pendingForm) {
            confirmation.close();
            return;
        }
        const form = pendingForm;
        const archiveAction = form.dataset.archiveAction;
        const actionInput = form.querySelector('input[name="action"]');
        pendingForm = null;
        pendingSubmitter = null;
        confirmation.close();
        if (!archiveAction || !actionInput) return;
        actionInput.value = archiveAction;
        form.dataset.deleteConfirmed = 'true';
        form.requestSubmit();
    });
    confirmButton.addEventListener('click', function () {
        if (!pendingForm) {
            confirmation.close();
            return;
        }
        const form = pendingForm;
        const submitter = pendingSubmitter;
        pendingForm = null;
        pendingSubmitter = null;
        confirmation.close();
        form.dataset.deleteConfirmed = 'true';
        form.requestSubmit(submitter || undefined);
    });
})();
</script>
