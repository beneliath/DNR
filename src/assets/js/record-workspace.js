(function () {
    'use strict';
    function openNote() {
        if (window.location.hash !== '#add-note') return;
        const details = document.getElementById('add-note');
        if (details) {
            details.open = true;
            const textarea = details.querySelector('textarea');
            if (textarea) textarea.focus();
        }
    }
    window.addEventListener('hashchange', function () { window.setTimeout(openNote, 0); });
    document.querySelectorAll('a[href="#add-note"]').forEach(function (link) {
        link.addEventListener('click', function () { window.setTimeout(openNote, 0); });
    });
    document.querySelectorAll('[data-inline-organization]').forEach(function (panel) {
        const button = panel.querySelector('[data-create-organization]');
        const name = panel.querySelector('[data-organization-name]');
        const status = panel.querySelector('[data-organization-status]');
        const form = panel.closest('form');
        const select = form.querySelector('[name="organization_id"]');
        button.addEventListener('click', async function () {
            if (!name.value.trim()) {
                status.textContent = 'Enter an organization name';
                name.focus();
                return;
            }
            button.disabled = true;
            status.textContent = 'Creating organization';
            const data = new FormData();
            const csrf = form.querySelector('[name="csrf_token"]');
            if (csrf) data.append('csrf_token', csrf.value);
            data.append('organization_name', name.value.trim());
            try {
                const response = await fetch('create_organization_inline.php', {method: 'POST', body: data, credentials: 'same-origin'});
                const result = await response.json();
                if (!response.ok || !Number.isInteger(result.id) || result.id < 1 || !result.label) {
                    throw new Error(result.error || 'Unable to create the organization. Try again.');
                }
                const option = document.createElement('option');
                option.value = String(result.id);
                option.textContent = result.label;
                select.appendChild(option);
                select.value = String(result.id);
                select.dispatchEvent(new Event('change', {bubbles: true}));
                status.textContent = result.label + ' created and selected';
                name.value = '';
                select.focus();
            } catch (error) {
                status.textContent = error.message || 'Unable to create the organization. Your contact draft is preserved.';
            } finally {
                button.disabled = false;
            }
        });
    });
    openNote();
}());
