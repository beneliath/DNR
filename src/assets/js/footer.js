(function () {
    'use strict';

    const checkingAdminUnlock = new WeakSet();
    const approvedUnlockForms = new WeakSet();

    // One short-lived snapshot per tab, scoped to the signed-in user and exact screen.
    // Never retain credentials, files, confirmation phrases, or security tokens.
    const sidebar = document.getElementById('app-sidebar');
    const resumeKey = sidebar ? 'dnr.admin-unlock-return.' + sidebar.dataset.navPreferenceUser : null;
    const screenUrl = () => window.location.pathname + window.location.search;
    const pageScroller = () => document.body.classList.contains('account-context-active') ? document.body : window;
    const pageForms = Array.from(document.querySelectorAll('main form'));
    const controls = form => Array.from(form.elements).filter(field => field.name
        && !['hidden', 'file', 'password', 'submit', 'button', 'reset'].includes(field.type)
        && !/password|secret|token|confirmation|admin_code/i.test(field.name));
    const values = form => controls(form).map(field => ({
        name: field.name, type: field.type, value: field.value, checked: !!field.checked,
        selected: field.type === 'select-multiple' ? Array.from(field.selectedOptions, option => option.value) : null
    }));
    const identity = form => JSON.stringify([form.getAttribute('id'), form.getAttribute('action'), form.getAttribute('method'),
        Array.from(form.elements).filter(field => field.type === 'hidden'
            && !/password|secret|token|confirmation|admin_code|_admin_unlock_return/i.test(field.name)).map(field => [field.name, field.value])]);
    const baselines = new Map(pageForms.map(form => [form, JSON.stringify(values(form))]));

    function saveUnlockActivity() {
        if (!resumeKey || window.location.pathname.endsWith('/admin_elevation.php')) return;
        const snapshot = {
            screen: screenUrl(), expires: Date.now() + 30 * 60 * 1000,
            forms: pageForms.map(form => ({ identity: identity(form), baseline: baselines.get(form), fields: values(form) })),
            x: pageScroller() === window ? window.scrollX : document.body.scrollLeft,
            y: pageScroller() === window ? window.scrollY : document.body.scrollTop,
            focus: document.activeElement?.id
        };
        try { window.DnrAccountContext.session.setItem(resumeKey, JSON.stringify(snapshot)); } catch (_) {}
    }
    document.addEventListener('admin-unlock-redirect', saveUnlockActivity);
    if (resumeKey) {
        try {
            const saved = JSON.parse(window.DnrAccountContext.session.getItem(resumeKey) || 'null');
            if (saved && (saved.expires < Date.now() || saved.screen === screenUrl())) {
                window.DnrAccountContext.session.removeItem(resumeKey);
                if (saved.expires >= Date.now() && Array.isArray(saved.forms)) {
                    pageForms.forEach(function (form) {
                        const draft = saved.forms.find(entry => entry.identity === identity(form)
                            && entry.baseline === baselines.get(form));
                        if (!draft || !Array.isArray(draft.fields)) return;
                        const fields = controls(form);
                        draft.fields.forEach(function (value, index) {
                            const field = fields[index];
                            if (!field || field.name !== value.name || field.type !== value.type) return;
                            if (['checkbox', 'radio'].includes(field.type)) field.checked = value.checked;
                            else if (field.type === 'select-multiple' && Array.isArray(value.selected)) {
                                Array.from(field.options).forEach(option => { option.selected = value.selected.includes(option.value); });
                            } else field.value = value.value;
                        });
                        fields.forEach(function (field) {
                            if (field.hasAttribute('data-email-template')) return;
                            field.dispatchEvent(new Event('input', { bubbles: true }));
                            field.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    });
                    window.addEventListener('pageshow', function () {
                        if (saved.focus) document.getElementById(saved.focus)?.focus({ preventScroll: true });
                        if (Number.isFinite(saved.x) && Number.isFinite(saved.y)) pageScroller().scrollTo(saved.x, saved.y);
                    }, { once: true });
                }
            }
        } catch (_) {}
    }

    // Fragments are client-side state, so preserve the current tab for proactive unlocks.
    document.querySelectorAll('[data-admin-unlock-link]').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.location.pathname.endsWith('/admin_elevation.php')) return;
            const destination = new URL(link.href, window.location.href);
            const intendedReturn = new URL(destination.searchParams.get('return') || window.location.href, window.location.href);
            destination.searchParams.set('return', window.location.pathname.split('/').pop()
                + window.location.search + (window.location.hash || intendedReturn.hash));
            link.href = destination.href;
            saveUnlockActivity();
        });
    });

    function requiresAdminUnlock(form, submitter) {
        return form.matches('[data-delete-confirmation], [data-sensitive-action], [data-admin-unlock-required]')
            || submitter?.matches('[data-admin-unlock-required]');
    }

    function submitUnlockedForm(form, submitter) {
        approvedUnlockForms.add(form);
        form.requestSubmit(submitter || undefined);
        approvedUnlockForms.delete(form);
    }

    async function confirmAdminUnlock(form, submitter) {
        if (!requiresAdminUnlock(form, submitter)) return true;
        if (checkingAdminUnlock.has(form)) return false;
        checkingAdminUnlock.add(form);

        // Keep the current filters and return to the section containing the action.
        const returnUrl = new URL(window.location.href);
        const actionUrl = new URL(form.getAttribute('action') || returnUrl.href, returnUrl);
        const section = form.closest('section[id], [role="tabpanel"]');
        if (actionUrl.pathname === returnUrl.pathname && actionUrl.hash) {
            returnUrl.hash = actionUrl.hash;
        } else if (section) {
            returnUrl.hash = section.id;
        }
        const unlockUrl = new URL('admin_elevation.php', returnUrl);
        const returnTo = returnUrl.pathname.split('/').pop() + returnUrl.search + returnUrl.hash;
        unlockUrl.searchParams.set('return', returnTo);
        // Preserve the source if the unlock expires between this check and the server's gate.
        let source = form.querySelector('input[name="_admin_unlock_return"]');
        if (!source) {
            source = document.createElement('input');
            source.type = 'hidden'; source.name = '_admin_unlock_return';
            form.appendChild(source);
        }
        source.value = returnTo;

        try {
            const response = await fetch('admin_unlock_status.php', {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
            });
            if (response.ok && !response.redirected) {
                const status = await response.json();
                if (status.unlocked === true && typeof status.csrf_token === 'string' && status.csrf_token !== '') {
                    const token = form.querySelector('input[name="csrf_token"]');
                    if (token) token.value = status.csrf_token;
                    return true;
                }
            }
        } catch (error) {
            // If the session cannot be checked, use the normal authenticated unlock flow.
        } finally {
            checkingAdminUnlock.delete(form);
        }
        saveUnlockActivity();
        window.location.assign(unlockUrl.href);
        return false;
    }

    // Protected saves and lifecycle actions can require unlock without a confirmation dialog.
    document.addEventListener('submit', async function (event) {
        if (event.defaultPrevented) return;
        const form = event.target.closest('form');
        if (!form || !requiresAdminUnlock(form, event.submitter)) return;
        if (approvedUnlockForms.has(form)) {
            approvedUnlockForms.delete(form);
            // Retain edits if the server sees expiry after a successful client check.
            saveUnlockActivity();
            return;
        }
        if (form.matches('[data-delete-confirmation], [data-sensitive-action], [data-confirm]')
            || event.submitter?.closest('[data-confirm]')) return;
        event.preventDefault();
        // Pause target listeners (for example invitation busy indicators) until the check succeeds.
        event.stopImmediatePropagation();
        if (!await confirmAdminUnlock(form, event.submitter)) return;
        submitUnlockedForm(form, event.submitter);
    }, { capture: true });

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
        form.addEventListener('submit', async function (event) {
            if (event.defaultPrevented) return;
            if (form.dataset.deleteConfirmed === 'true') return;
            event.preventDefault();
            if (!await confirmAdminUnlock(form, event.submitter)) return;
            pendingForm = form;
            pendingSubmitter = event.submitter;
            confirmationMessage.textContent = form.dataset.deleteConfirmation;
            archiveButton.textContent = form.dataset.archiveButtonLabel || 'Archive Instead';
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
        submitUnlockedForm(form);
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
        submitUnlockedForm(form, submitter);
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

    document.addEventListener('submit', async function (event) {
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
        if (!await confirmAdminUnlock(form, event.submitter)) return;
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
        submitUnlockedForm(form, submitter);
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

    document.addEventListener('submit', async function (event) {
        if (event.defaultPrevented) return;
        const form = event.target.closest('form[data-sensitive-action]');
        if (!form) return;
        if (approvedForms.has(form)) {
            approvedForms.delete(form);
            return;
        }

        event.preventDefault();
        if (!await confirmAdminUnlock(form, event.submitter)) return;
        const deleting = form.dataset.sensitiveAction === 'delete-user';
        pendingForm = form;
        pendingSubmitter = event.submitter;
        requiredPhrase = deleting ? 'DELETE USER' : 'RESET 2FA';
        confirmationFieldName = deleting ? 'delete_confirmation' : 'reset_confirmation';
        confirmationTitle.textContent = deleting ? 'Are you sure you want to delete this user?' : 'Reset two-factor authentication?';
        confirmationMessage.textContent = deleting
            ? 'This user and their retained account history will be permanently deleted. This cannot be undone.'
            : 'The user’s current authenticator and recovery codes will stop working.';
        confirmationPhrase.textContent = requiredPhrase;
        confirmButton.textContent = deleting ? 'Delete User' : 'Reset 2FA';
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
        submitUnlockedForm(form, submitter);
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

})();
