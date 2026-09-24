(function () {
    'use strict';
    const button = document.querySelector('button[value="prepare_clear"]');
    const form = button?.form;
    if (!form || typeof HTMLDialogElement === 'undefined') return;

    const dialog = document.createElement('dialog');
    dialog.className = 'confirmation-dialog';
    dialog.setAttribute('aria-labelledby', 'clear-log-heading');
    document.body.appendChild(dialog);
    const error = document.createElement('p');
    error.className = 'error';
    error.setAttribute('role', 'alert');
    error.hidden = true;
    (form.closest('.page-heading') || form).after(error);
    let loading = false;

    dialog.addEventListener('close', () => {
        dialog.replaceChildren();
        button.focus();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (loading) return;
        loading = true;
        error.hidden = true;
        button.disabled = true;
        try {
            const unlockResponse = await fetch('admin_unlock_status.php', {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            });
            if (!unlockResponse.ok || unlockResponse.redirected) throw new Error('Unlock status unavailable');
            const unlock = await unlockResponse.json();
            if (unlock.unlocked !== true) {
                const url = new URL('admin_elevation.php', window.location.href);
                url.searchParams.set('return', window.location.pathname.split('/').pop() + window.location.search);
                window.location.assign(url.href);
                return;
            }
            const csrf = form.querySelector('input[name="csrf_token"]');
            if (csrf && typeof unlock.csrf_token === 'string') csrf.value = unlock.csrf_token;
            const data = new FormData(form);
            data.set('action', 'prepare_clear');
            // Obtain the same expiring snapshot/token as the non-JavaScript
            // confirmation, without navigating away from the filtered log.
            // The submit button named "action" shadows the form.action property.
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
            });
            if (!response.ok || response.redirected) throw new Error('Confirmation unavailable');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const section = page.querySelector('[aria-labelledby="clear-log-heading"]');
            const confirmation = section?.querySelector('form');
            const token = confirmation?.querySelector('input[name="clear_token"]');
            if (!token?.value) throw new Error('Confirmation unavailable');
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'button-secondary';
            cancel.textContent = 'Cancel';
            cancel.addEventListener('click', () => dialog.close('cancel'));
            confirmation.querySelector('a').replaceWith(cancel);
            confirmation.classList.add('confirmation-dialog-actions');
            dialog.replaceChildren(...section.childNodes);
            dialog.showModal();
            cancel.focus();
        } catch {
            error.textContent = 'The confirmation could not be loaded. Please try again or reload the page.';
            error.hidden = false;
        } finally {
            loading = false;
            button.disabled = false;
        }
    });
})();
