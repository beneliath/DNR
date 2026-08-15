<footer>
    <p style="opacity: 0.2; display: inline;">&copy; <?php echo date("Y"); ?> beneliath</p>
    &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
    <a href="calendar_subscription.php" style="text-decoration: none;">Calendar</a>
    &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
    <a href="two_factor_settings.php" style="text-decoration: none;">Account Security</a>
    &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
    <form method="post" action="logout.php" id="logout-form" style="display: inline;">
        <?php echo csrfInput(); ?>
        <button type="submit" class="logout-link-button" style="border: 0; padding: 0; background: none; color: var(--link-color); font: inherit; cursor: pointer;">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</button>
    </form>

    <dialog id="logout-confirmation" class="confirmation-dialog" aria-labelledby="logout-confirmation-title">
        <h2 id="logout-confirmation-title">Confirm logout</h2>
        <p>Are you sure you want to log out?</p>
        <div class="confirmation-dialog-actions">
            <button type="button" id="cancel-logout">Cancel</button>
            <button type="button" id="confirm-logout" class="danger-button">Log out</button>
        </div>
    </dialog>

    <dialog id="delete-confirmation" class="confirmation-dialog" aria-labelledby="delete-confirmation-title" aria-describedby="delete-confirmation-message">
        <h2 id="delete-confirmation-title">Confirm deletion</h2>
        <p id="delete-confirmation-message">Are you sure you want to delete this item?</p>
        <p>Archive it instead to keep the record available for later restoration.</p>
        <div class="confirmation-dialog-actions">
            <button type="button" id="cancel-delete" autofocus>Cancel</button>
            <button type="button" id="archive-instead" class="archive-button">Archive instead</button>
            <button type="button" id="confirm-delete" class="danger-button">Delete permanently</button>
        </div>
    </dialog>
    
    <!-- ASCII Art Container -->
    <div class="ascii-art-container" style="opacity: 0.2; font-size: 1.0em;">
    <pre style="font-size: 0.8em;">
     ("`-''-/").___..--''"`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'                repo:  https://github.com/beneliath/DNR
  (il),-''  (li),'  ((!.-'                 title:  DNR - deploy & report
                                         version:  0.0.5
Genesis 49:9,10 ... Revelation 5:5     timestamp:  2026-08-15 11:27:23
         Do you see Him?
    </pre>
    </div>
</footer>
<script>
(function () {
    const logoutForm = document.getElementById('logout-form');
    const confirmation = document.getElementById('logout-confirmation');
    const cancelButton = document.getElementById('cancel-logout');
    const confirmButton = document.getElementById('confirm-logout');
    let logoutConfirmed = false;

    logoutForm.addEventListener('submit', function (event) {
        if (!logoutConfirmed) {
            event.preventDefault();
            confirmation.showModal();
        }
    });

    cancelButton.addEventListener('click', function () {
        confirmation.close();
    });

    confirmButton.addEventListener('click', function () {
        logoutConfirmed = true;
        logoutForm.requestSubmit();
    });
})();

(function () {
    const confirmation = document.getElementById('delete-confirmation');
    const confirmationMessage = document.getElementById('delete-confirmation-message');
    const cancelButton = document.getElementById('cancel-delete');
    const archiveButton = document.getElementById('archive-instead');
    const confirmButton = document.getElementById('confirm-delete');
    let pendingForm = null;
    let pendingSubmitter = null;

    document.querySelectorAll('form[data-delete-confirmation]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.deleteConfirmed === 'true') {
                return;
            }

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

        // An already archived record only needs the destructive action cancelled.
        if (!archiveAction || !actionInput) {
            return;
        }

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
