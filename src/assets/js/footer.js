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

(function () {
    const confirmation = document.getElementById('action-confirmation');
    const confirmationTitle = document.getElementById('action-confirmation-title');
    const confirmationMessage = document.getElementById('action-confirmation-message');
    const cancelButton = document.getElementById('cancel-action-confirmation');
    const confirmButton = document.getElementById('confirm-action');
    const approvedForms = new WeakSet();
    let pendingForm = null;
    let pendingSubmitter = null;

    if (!confirmation || !confirmationTitle || !confirmationMessage
        || !cancelButton || !confirmButton) return;

    function resetPendingAction() {
        pendingForm = null;
        pendingSubmitter = null;
    }

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        const form = event.target.closest('form');
        if (!form) return;
        if (approvedForms.has(form)) {
            approvedForms.delete(form);
            return;
        }

        const formTarget = form.matches('[data-confirm]') ? form : null;
        const submitterTarget = event.submitter?.closest('[data-confirm]');
        const confirmationTarget = submitterTarget || formTarget;
        if (!confirmationTarget) return;

        event.preventDefault();
        pendingForm = form;
        pendingSubmitter = event.submitter;
        const destructive = confirmationTarget.dataset.confirmTone === 'danger'
            || confirmationTarget.matches('.delete-button, .danger-button')
            || event.submitter?.matches('.delete-button, .danger-button');
        const submitterLabel = event.submitter?.getAttribute('aria-label')
            || event.submitter?.textContent?.trim();

        confirmationTitle.textContent = confirmationTarget.dataset.confirmTitle
            || (destructive ? 'Confirm destructive action' : 'Confirm action');
        confirmationMessage.textContent = String(
            confirmationTarget.dataset.confirm || 'Continue with this action?'
        );
        confirmButton.textContent = confirmationTarget.dataset.confirmLabel
            || submitterLabel
            || 'Continue';
        confirmButton.className = destructive ? 'danger-button' : 'save-button';
        confirmation.showModal();
    });

    cancelButton.addEventListener('click', function () {
        confirmation.close('cancel');
    });
    confirmation.addEventListener('cancel', resetPendingAction);
    confirmation.addEventListener('close', resetPendingAction);
    confirmButton.addEventListener('click', function () {
        if (!pendingForm) {
            confirmation.close('cancel');
            return;
        }
        const form = pendingForm;
        const submitter = pendingSubmitter;
        approvedForms.add(form);
        confirmation.close('confirm');
        form.requestSubmit(submitter || undefined);
        approvedForms.delete(form);
    });
})();

(function () {
    const confirmation = document.getElementById('sensitive-action-confirmation');
    const confirmationTitle = document.getElementById('sensitive-action-confirmation-title');
    const confirmationMessage = document.getElementById('sensitive-action-confirmation-message');
    const confirmationPhrase = document.getElementById('sensitive-action-confirmation-phrase');
    const confirmationInput = document.getElementById('sensitive-action-confirmation-input');
    const confirmationError = document.getElementById('sensitive-action-confirmation-error');
    const cancelButton = document.getElementById('cancel-sensitive-action');
    const confirmButton = document.getElementById('confirm-sensitive-action');
    const approvedForms = new WeakSet();
    let pendingForm = null;
    let pendingSubmitter = null;
    let requiredPhrase = '';
    let confirmationFieldName = '';

    if (!confirmation || !confirmationTitle || !confirmationMessage
        || !confirmationPhrase || !confirmationInput || !confirmationError
        || !cancelButton || !confirmButton) return;

    function resetSensitiveAction() {
        pendingForm = null;
        pendingSubmitter = null;
        requiredPhrase = '';
        confirmationFieldName = '';
        confirmationInput.value = '';
        confirmationInput.removeAttribute('aria-invalid');
        confirmationError.textContent = '';
        confirmationError.hidden = true;
    }

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        const form = event.target.closest('form[data-sensitive-action]');
        if (!form) return;
        if (approvedForms.has(form)) {
            approvedForms.delete(form);
            return;
        }

        event.preventDefault();
        const deleting = form.dataset.sensitiveAction === 'delete-user';
        pendingForm = form;
        pendingSubmitter = event.submitter;
        requiredPhrase = deleting ? 'DELETE USER' : 'RESET 2FA';
        confirmationFieldName = deleting ? 'delete_confirmation' : 'reset_confirmation';
        confirmationTitle.textContent = deleting ? 'Delete user permanently?' : 'Reset two-factor authentication?';
        confirmationMessage.textContent = deleting
            ? 'This user and their retained account history will be permanently deleted. This cannot be undone.'
            : 'The user’s current authenticator and recovery codes will stop working.';
        confirmationPhrase.textContent = requiredPhrase;
        confirmButton.textContent = deleting ? 'Delete user' : 'Reset 2FA';
        confirmationInput.value = '';
        confirmationInput.removeAttribute('aria-invalid');
        confirmationError.hidden = true;
        confirmation.showModal();
        confirmationInput.focus();
    });

    function submitSensitiveAction() {
        if (!pendingForm) {
            confirmation.close('cancel');
            return;
        }
        if (confirmationInput.value !== requiredPhrase) {
            confirmationInput.setAttribute('aria-invalid', 'true');
            confirmationError.textContent = 'The confirmation phrase must match '
                + requiredPhrase + ' exactly.';
            confirmationError.hidden = false;
            confirmationInput.focus();
            confirmationInput.select();
            return;
        }

        const form = pendingForm;
        const submitter = pendingSubmitter;
        const field = form.elements[confirmationFieldName];
        if (field) field.value = requiredPhrase;
        approvedForms.add(form);
        confirmation.close('confirm');
        form.requestSubmit(submitter || undefined);
        approvedForms.delete(form);
    }

    cancelButton.addEventListener('click', function () {
        confirmation.close('cancel');
    });
    confirmButton.addEventListener('click', submitSensitiveAction);
    confirmationInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            submitSensitiveAction();
        }
    });
    confirmation.addEventListener('cancel', resetSensitiveAction);
    confirmation.addEventListener('close', resetSensitiveAction);
})();

(function () {
    const preview = document.getElementById('qr-code-preview');
    const previewTitle = document.getElementById('qr-code-preview-title');
    const previewImage = document.getElementById('qr-code-preview-image');
    const closeButton = document.getElementById('close-qr-code-preview');
    if (!preview || !previewTitle || !previewImage || !closeButton) return;

    closeButton.addEventListener('click', function () {
        preview.close();
    });
    preview.addEventListener('close', function () {
        previewImage.removeAttribute('src');
        previewImage.alt = 'QR code preview';
        previewTitle.textContent = 'QR Code Preview';
    });
})();
