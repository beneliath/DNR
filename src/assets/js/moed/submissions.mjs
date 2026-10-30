export function initializeDatabaseBackupSubmission() {
    const form = document.getElementById('database-backup-form');
    const error = document.getElementById('database-backup-error');
    if (!form || !error) return;
    form.addEventListener('submit', function () {
        // A successful download leaves this document open, so discard the previous attempt's error.
        error.remove();
    });
}

export function initializeInvitationSubmission() {
    document.querySelectorAll('[data-invitation-form]').forEach(function (form) {
        const button = form.querySelector('[data-invitation-submit]');
        const status = form.querySelector('[data-invitation-submit-status]');
        if (!button || !status) return;
        const idleLabel = button.textContent;
        const submittingLabel = button.dataset.submittingLabel || 'Sending Invitation…';

        const reset = function () {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = idleLabel;
            status.hidden = true;
        };

        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = 'true';
            form.setAttribute('aria-busy', 'true');
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = submittingLabel;
            status.hidden = false;
        });
        window.addEventListener('pageshow', reset);
    });
}
