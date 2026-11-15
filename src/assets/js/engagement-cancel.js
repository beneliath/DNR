(function () {
    const dialog = document.getElementById('engagement-cancel-dialog');
    const form = document.getElementById('engagement-cancel-form');
    if (!dialog || !form) return;
    const confirmation = form.elements.cancel_confirmation;
    const reason = form.elements.cancellation_reason;
    const confirm = form.querySelector('[data-confirm-engagement-cancel]');
    const name = document.getElementById('engagement-cancel-name');
    let opener = null;

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-cancel-engagement]');
        if (!button) return;
        opener = button;
        form.reset();
        form.elements.engagement_id.value = button.dataset.cancelEngagement;
        form.elements.expected_version.value = button.dataset.engagementVersion;
        name.textContent = button.dataset.engagementLabel;
        confirm.disabled = true;
        dialog.showModal();
        reason.focus();
    });
    confirmation.addEventListener('input', function () {
        confirm.disabled = confirmation.value !== 'CANCEL';
    });
    form.addEventListener('submit', function (event) {
        if (confirmation.value !== 'CANCEL') {
            event.preventDefault();
            confirmation.focus();
        }
    });
    form.querySelector('[data-keep-engagement]').addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('close', function () {
        form.reset();
        confirm.disabled = true;
        opener?.focus();
        opener = null;
    });
})();
