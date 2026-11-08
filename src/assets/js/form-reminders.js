(function () {
    'use strict';

    const states = new WeakMap();
    const recoveryNode = document.getElementById('form-reminder-recovery');
    const recovery = recoveryNode ? JSON.parse(recoveryNode.textContent) : null;

    function snapshot(form, defaults = false) {
        const values = [];
        for (const field of form.elements) {
            if (!['INPUT', 'SELECT', 'TEXTAREA'].includes(field.tagName) || !field.name || field.matches(':disabled')
                || field.hasAttribute('data-pending-ignore')
                || ['submit', 'button', 'reset', 'image'].includes(field.type)
                || /^(csrf_token|creation_token|_form_reminder)$|(?:^|_)version$/.test(field.name)) continue;
            let value;
            if (field.type === 'file') {
                value = Array.from(field.files, file => [file.name, file.size, file.lastModified]);
            } else if (field.type === 'checkbox' || field.type === 'radio') {
                value = [(defaults ? field.defaultChecked : field.checked), field.value];
            } else if (field.tagName === 'SELECT') {
                const selected = Array.from(field.options).filter(option => defaults ? option.defaultSelected : option.selected);
                if (defaults && !field.multiple && selected.length === 0 && field.options.length) selected.push(field.options[0]);
                value = selected.map(option => option.value);
            } else {
                value = defaults ? field.defaultValue : field.value;
                if (form.method === 'get') value = value.trim();
            }
            values.push([field.name, value]);
        }
        return JSON.stringify(values);
    }

    async function fingerprint(value) {
        if (!window.crypto?.subtle) return '';
        try {
            const bytes = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
            return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
        } catch (_) {
            return '';
        }
    }

    function writeMetadata(state) {
        if (state.form.method !== 'post') return;
        let input = state.form.querySelector('input[name="_form_reminder"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_form_reminder';
            state.form.appendChild(input);
        }
        input.value = JSON.stringify({key: state.key, baseline: state.baselineHash, pending: state.pending});
    }

    function mark(state, pending) {
        state.pending = pending;
        state.buttons.forEach((description, button) => {
            button.classList.toggle('form-submit-pending', pending);
            if (pending) {
                button.setAttribute('aria-description', [description, state.form.method === 'get'
                    ? 'Changed filters are ready to apply.' : 'Unsaved changes.'].filter(Boolean).join(' '));
            } else if (description === null) {
                button.removeAttribute('aria-description');
            } else {
                button.setAttribute('aria-description', description);
            }
        });
        writeMetadata(state);
    }

    function update(form) {
        const state = states.get(form);
        if (!state) return;
        const current = snapshot(form);
        const revision = ++state.revision;
        if (!state.recovered) {
            mark(state, current !== state.baseline);
            return;
        }
        fingerprint(current).then(hash => {
            if (revision !== state.revision) return;
            mark(state, state.baselineHash && hash ? hash !== state.baselineHash : recovery.pending);
        });
    }

    function recordIdentity(form) {
        return JSON.stringify(['id', 'link_id', 'chron_entry_id', 'section'].map(name => form.elements.namedItem(name)?.value || ''));
    }

    function reset(form) {
        const state = states.get(form);
        if (!state) return;
        const identity = recordIdentity(form);
        if (state.recovered && identity === state.identity) return;
        state.recovered = false;
        state.identity = identity;
        state.baseline = snapshot(form);
        state.baselineHash = '';
        const baseline = state.baseline;
        fingerprint(baseline).then(hash => {
            if (baseline !== state.baseline) return;
            state.baselineHash = hash;
            writeMetadata(state);
        });
        update(form);
    }

    function initializeButtons() {
        document.querySelectorAll('[data-pending-reminder]').forEach(button => {
            const form = button.form;
            if (!form) return;
            let state = states.get(form);
            if (!state) {
                const key = window.location.pathname + ':' + button.dataset.pendingReminder;
                const recovered = form.method === 'post' && recovery?.key === key;
                state = {form, key, recovered, baselineHash: recovered ? recovery.baseline : '',
                    baseline: snapshot(form, form.method === 'get'), identity: recordIdentity(form),
                    pending: false, revision: 0, buttons: new Map()};
                states.set(form, state);
                if (!recovered && form.method === 'post') {
                    const baseline = state.baseline;
                    fingerprint(baseline).then(hash => {
                        if (baseline !== state.baseline) return;
                        state.baselineHash = hash;
                        writeMetadata(state);
                    });
                }
                form.addEventListener('reset', () => requestAnimationFrame(() => update(form)));
            }
            if (!state.buttons.has(button)) state.buttons.set(button, button.getAttribute('aria-description'));
            update(form);
        });
    }

    function initialize() {
        initializeButtons();
        document.addEventListener('input', event => update(event.target.form));
        document.addEventListener('change', event => update(event.target.form));
        window.addEventListener('pageshow', initializeButtons);
        new MutationObserver(records => {
            const forms = new Set();
            let addedButtons = false;
            for (const record of records) {
                const form = record.target.closest?.('form');
                if (form && states.has(form)) forms.add(form);
                for (const node of record.addedNodes) {
                    if (node.nodeType === 1 && (node.matches('[data-pending-reminder]') || node.querySelector('[data-pending-reminder]'))) addedButtons = true;
                }
            }
            if (addedButtons) initializeButtons();
            forms.forEach(update);
        }).observe(document.body, {childList: true, subtree: true});
    }

    window.DnrFormReminders = {reset, update};
    if (document.readyState !== 'complete') document.addEventListener('DOMContentLoaded', initialize, {once: true});
    else initialize();
})();
