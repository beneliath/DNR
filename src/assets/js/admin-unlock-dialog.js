(function () {
    'use strict';
    const dialog = document.getElementById('admin-unlock-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const form = dialog.querySelector('form');
    const password = form.querySelector('[name="admin_password"]');
    const code = form.querySelector('[name="admin_code"]');
    const csrf = form.querySelector('[name="csrf_token"]');
    const submit = form.querySelector('[data-unlock-submit]');
    const cancel = form.querySelector('[data-unlock-cancel]');
    const error = dialog.querySelector('[data-unlock-error]');
    let pending = null;
    let resolvePending;
    let submitting = false;
    let revision = 0;
    let stopChecking;

    function applyStatus(status) {
        if (typeof status.csrf_token !== 'string' || !status.csrf_token) throw new Error('Invalid session response');
        // Elevation rotates the session token. Keep every pending form usable.
        document.querySelectorAll('input[name="csrf_token"]').forEach(input => { input.value = status.csrf_token; });
        document.querySelectorAll('[data-csrf-token]').forEach(node => { node.dataset.csrfToken = status.csrf_token; });
        document.dispatchEvent(new CustomEvent('admin-unlock-changed', { detail: status }));
    }

    async function fetchStatus(options = {}) {
        const response = await fetch('admin_elevation.php', {
            credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, ...options
        });
        if (response.redirected || (!response.ok && response.status !== 422)) throw new Error('Unlock unavailable');
        const status = await response.json();
        applyStatus(status);
        return status;
    }

    function finish(unlocked) {
        revision++;
        stopChecking?.();
        stopChecking = null;
        password.value = '';
        code.value = '';
        const resolve = resolvePending;
        pending = null;
        resolvePending = null;
        if (dialog.open) dialog.close();
        resolve?.(unlocked);
    }

    function request() {
        if (pending) return pending;
        pending = new Promise(resolve => { resolvePending = resolve; });
        const result = pending;
        const current = ++revision;
        error.hidden = true;
        password.value = '';
        code.value = '';
        submit.disabled = true;
        dialog.showModal();
        const finishFeedback = window.DnrButtonFeedback?.begin(submit, 'Checking Access…');
        stopChecking = finishFeedback;
        fetchStatus().then(status => {
            if (current !== revision) return;
            if (status.unlocked === true) { finish(true); return; }
            csrf.value = status.csrf_token;
            submit.disabled = false;
            password.focus({ preventScroll: true });
        }).catch(() => {
            if (current !== revision) return;
            error.textContent = 'Unable to check administrator access. Close this dialog and try again.';
            error.hidden = false;
        }).finally(() => {
            finishFeedback?.();
            if (stopChecking === finishFeedback) stopChecking = null;
        });
        return result;
    }

    cancel.addEventListener('click', () => { if (!submitting) finish(false); });
    dialog.addEventListener('cancel', event => {
        event.preventDefault();
        if (!submitting) finish(false);
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting || submit.disabled) return;
        submitting = true;
        submit.disabled = true;
        cancel.disabled = true;
        error.hidden = true;
        const finishFeedback = window.DnrButtonFeedback?.begin(submit, 'Unlocking…');
        try {
            const status = await fetchStatus({ method: 'POST', body: new FormData(form) });
            if (status.unlocked === true && !status.error) finish(true);
            else {
                error.textContent = status.error || 'Administrator access could not be confirmed.';
                error.hidden = false;
            }
        } catch (_) {
            error.textContent = 'Unable to unlock administrator actions. Please try again.';
            error.hidden = false;
        } finally {
            finishFeedback?.();
            password.value = '';
            code.value = '';
            submitting = false;
            submit.disabled = false;
            cancel.disabled = false;
            if (dialog.open) password.focus({ preventScroll: true });
        }
    });

    // These links open protected screens after unlocking; proactive links stay put.
    document.addEventListener('click', async event => {
        const link = event.target.closest('a[data-admin-unlock-required]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (await request()) window.location.assign(link.href);
    });
    window.DnrAdminUnlock = Object.freeze({ request });
})();
