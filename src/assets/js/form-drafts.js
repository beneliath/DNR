(function () {
    'use strict';
    if (window.DnrFormDrafts) return;
    const initialized = new WeakSet();
    const forms = new WeakMap();
    function registerRows(container, name, append, limit = Infinity) {
        const form = container.closest('form[data-recoverable-draft]');
        if (!form) return;
        const pattern = new RegExp('^' + name + '\\[(\\d+)\\]\\[');
        const indexOf = fieldName => pattern.exec(fieldName)?.[1];
        form.addEventListener('form-draft-restore', function (event) {
            const indices = new Set([...new Set(event.detail.fields.map(field => indexOf(field.name))
                .filter(index => index !== undefined && Number.isSafeInteger(Number(index))))].slice(0, limit));
            const rows = new Map();
            for (const row of Array.from(container.children)) {
                const field = row.querySelector(`[name^="${name}["]`);
                if (!field) continue;
                const index = indexOf(field.name);
                if (indices.has(index)) rows.set(index, row);
                else row.remove();
            }
            for (const index of indices) container.appendChild(rows.get(index) || append(Number(index)));
            container.dispatchEvent(new Event('input', {bubbles: true}));
        });
    }
    function initialize(form) {
        const sidebar = document.getElementById('app-sidebar');
        if (!sidebar || !form || initialized.has(form)) return;
        initialized.add(form);
        const prefix = 'dnr.form-draft.' + encodeURIComponent(sidebar.dataset.navPreferenceUser) + '.';
        const key = prefix + form.dataset.recoverableDraft;
        const controls = () => Array.from(form.elements).filter(el => el.name && !el.disabled && !el.hasAttribute('data-draft-ignore')
            && (el.type === 'hidden' ? el.hasAttribute('data-draft-field') : !['file','password','submit','button','reset'].includes(el.type)));
        const versions = () => JSON.stringify(Array.from(form.elements).filter(el => /version/.test(el.name)).map(el => [el.name,el.value]).concat([['record',form.dataset.draftVersion || '']]));
        const capture = () => controls().map(el => ({name:el.name,type:el.type,value:el.value,checked:el.checked}));
        const cleanText = text => String(text || '').replace(/\*/g, '').replace(/\s+/g, ' ').trim();
        const readableName = name => cleanText(name.replace(/_id\b/g, '').replace(/[\[\]_]+/g, ' '))
            .replace(/\b\w/g, letter => letter.toUpperCase());
        function fieldLabel(el) {
            const group = el.closest('.presentation-entry, fieldset');
            const context = cleanText(group?.querySelector(':scope > legend, .presentation-entry-heading')?.textContent);
            const label = el.type === 'radio'
                ? cleanText(el.closest('fieldset')?.querySelector(':scope > legend')?.textContent) || readableName(el.name)
                : cleanText(Array.from(el.labels || []).map(label => label.textContent).join(' '))
                    || cleanText(el.getAttribute('aria-label')) || readableName(el.name);
            return context && context !== label ? context + ' · ' + label : label;
        }
        function displayValue(field, el) {
            if (field.type === 'checkbox') return field.checked ? 'Yes' : 'No';
            const option = Array.from(el?.options || []).find(option => option.value === field.value);
            const radioLabel = field.type === 'radio' && cleanText(el?.labels?.[0]?.textContent);
            return cleanText(option?.textContent) || radioLabel || field.displayValue || field.value || 'Empty';
        }
        function matchingControl(field, elements, consumed) {
            return elements.find(el => !consumed.has(el) && el.name === field.name
                && (['checkbox', 'radio'].includes(field.type)
                    ? el.type === field.type && el.value === field.value
                    : !['checkbox', 'radio'].includes(el.type)));
        }
        function canRestoreValue(field, el) {
            return el && !el.disabled && !el.readOnly && (el.tagName !== 'SELECT'
                || Array.from(el.options).some(option => option.value === field.value
                    && !option.disabled && !option.parentElement?.disabled));
        }
        function restoreValue(field, el) {
            if (!canRestoreValue(field, el)) return false;
            if (['checkbox', 'radio'].includes(el.type)) el.checked = !!field.checked;
            else el.value = field.value;
            // Applying a template would overwrite the restored body and recipient choices.
            if (!el.hasAttribute('data-email-template')) {
                el.dispatchEvent(new Event('input', {bubbles: true}));
                el.dispatchEvent(new Event('change', {bubbles: true}));
            }
            return true;
        }
        let baseline = '';
        let lastSaved = '';
        const captureBaseline = () => {
            baseline = JSON.stringify(capture());
            if (lastSaved === '') lastSaved = baseline;
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', captureBaseline, {once: true});
        else captureBaseline();
        let saved = null;
        let timer;
        let submitting = false;
        let applyingDraft = false;
        let recoveryFields = [];
        const existingPanel = form.querySelector('[data-form-draft-panel]');
        const panel = existingPanel || document.createElement('details'); panel.className = 'form-draft-panel';
        const collapsible = panel.tagName === 'DETAILS';
        const status = document.createElement(collapsible ? 'span' : 'p'); status.setAttribute('role','status');
        const heading = collapsible ? document.createElement('summary') : status;
        if (collapsible) heading.append(status);
        const save = document.createElement('button'); save.type='button'; save.className='button-secondary'; save.textContent='Save Draft';
        const restore = document.createElement('button'); restore.type='button'; restore.className='button-secondary'; restore.textContent='Restore Draft';
        const discard = document.createElement('button'); discard.type='button'; discard.className='button-secondary'; discard.textContent='Discard Draft';
        const restoredHighlights = new Set();
        const highlightTarget = el => (el.dataset.errorTarget && document.getElementById(el.dataset.errorTarget))
            || el.closest('[data-address-country-picker]')?.querySelector('[data-address-country-toggle]')
            || el.closest('[data-phone-country-picker]')?.querySelector('[data-phone-country-toggle]') || el;
        function clearRestoredHighlights() {
            restoredHighlights.forEach(el => { delete el.dataset.draftRestored; });
            restoredHighlights.clear();
        }
        function highlightRestoredChanges(before, source = restore, restored = null) {
            const consumed = new Set();
            const restoredConsumed = new Set();
            const highlight = el => {
                el.dataset.draftRestored = '';
                restoredHighlights.add(el);
            };
            controls().forEach(el => {
                // A related-record return may replace a restored selection with
                // the newly created record. Only values still from the draft count.
                if (restored) {
                    const expected = matchingControl(el, restored, restoredConsumed);
                    if (!expected) return;
                    restoredConsumed.add(expected);
                    if (['checkbox', 'radio'].includes(el.type) ? !!expected.checked !== !!el.checked : expected.value !== el.value) return;
                }
                const previous = matchingControl(el, before, consumed);
                if (previous) consumed.add(previous);
                const unchanged = ['checkbox', 'radio'].includes(el.type)
                    ? !!previous?.checked === !!el.checked
                    : (previous?.value || '').replace(/\r\n?/g, '\n') === el.value.replace(/\r\n?/g, '\n');
                if (unchanged) return;
                highlight(highlightTarget(el));
            });
            if (source) highlight(source);
        }
        forms.set(form, {capture, highlightRestoredChanges});
        const recovery = document.createElement('div'); recovery.className='form-draft-recovery'; recovery.hidden=true;
        const note=document.createElement('p'); note.textContent='Drafts are saved for 30 days in this browser. Files must be selected again. Saving a draft does not save the record or send email.';
        panel.replaceChildren(heading,save,restore,discard,note,recovery);
        if (!existingPanel) form.prepend(panel);
        try {
            const cleared = JSON.parse(existingPanel?.dataset?.clearedDrafts || '[]');
            if (cleared.includes(form.dataset.recoverableDraft)) window.DnrAccountContext.local.removeItem(key);
            saved=JSON.parse(window.DnrAccountContext.local.getItem(key) || 'null');
            if (saved && (!Array.isArray(saved.fields) || saved.expires<Date.now())) { window.DnrAccountContext.local.removeItem(key); saved=null; }
        } catch (_) { saved=null; }
        function renderRecovery(fields) {
            recoveryFields = fields;
            const elements = controls();
            const consumed = new Set();
            const radioNames = new Set();
            const rows = [];
            for (const field of fields) {
                if (field.internal) continue;
                let draft = field;
                let current = matchingControl(field, elements, consumed);
                if (field.type === 'radio') {
                    if (radioNames.has(field.name)) continue;
                    radioNames.add(field.name);
                    draft = fields.find(value => value.name === field.name && value.type === 'radio' && value.checked);
                    current = elements.find(el => el.name === field.name && el.type === 'radio' && el.checked);
                    if (draft?.value === current?.value) continue;
                } else {
                    if (current) consumed.add(current);
                    const draftValue = field.type === 'checkbox' ? !!field.checked : field.value.replace(/\r\n?/g, '\n');
                    const currentValue = field.type === 'checkbox' ? !!current?.checked : (current?.value || '').replace(/\r\n?/g, '\n');
                    if (draftValue === currentValue) continue;
                }
                const el = current || elements.find(el => el.name === field.name);
                const row = document.createElement('tr');
                const label = document.createElement('th'); label.scope = 'row';
                label.textContent = el ? fieldLabel(el) : field.label || readableName(field.name);
                const currentCell = document.createElement('td'); currentCell.dataset.field = 'Current Value';
                currentCell.textContent = current ? displayValue(current, current)
                    : field.type === 'radio' && el ? 'Empty' : 'Field no longer available';
                const draftCell = document.createElement('td'); draftCell.dataset.field = 'Saved Draft'; draftCell.dataset.draftValue = '';
                const draftEl = draft && matchingControl(draft, elements, new Set());
                draftCell.textContent = draft ? displayValue(draft, draftEl) : 'Empty';
                const actions = document.createElement('td'); actions.className = 'form-draft-actions';
                const restoreField = document.createElement('button');
                restoreField.type = 'button'; restoreField.className = 'button-secondary'; restoreField.textContent = 'Restore';
                restoreField.setAttribute('aria-label', 'Restore ' + label.textContent);
                const target = field.type === 'radio' && draft ? draftEl : current;
                restoreField.disabled = !canRestoreValue(draft || field, target);
                restoreField.addEventListener('click', function () {
                    if (!controls().includes(target) || !canRestoreValue(draft || field, target)) {
                        renderRecovery(recoveryFields);
                        return;
                    }
                    const before = capture();
                    applyingDraft = true;
                    try {
                        if (field.type === 'radio') {
                            controls().filter(el => el.name === field.name && el.type === 'radio')
                                .forEach(el => { el.checked = false; });
                        }
                        restoreValue(draft || {...field, value: target.value, checked: false}, target);
                    } finally {
                        applyingDraft = false;
                    }
                    clearTimeout(timer);
                    highlightRestoredChanges(before, status);
                    renderRecovery(recoveryFields);
                    status.textContent = label.textContent + ' restored to the form. Review your changes before saving.';
                    const nextButton = recovery.querySelector('button:not(:disabled)');
                    status.tabIndex = -1;
                    (nextButton || status).focus();
                });
                actions.append(restoreField);
                if (restoreField.disabled) {
                    const reason = document.createElement('small');
                    reason.textContent = target ? 'This saved value is unavailable in the current form.'
                        : 'Add this field to the form to restore its draft value.';
                    actions.append(reason);
                }
                row.append(label, currentCell, draftCell, actions);
                rows.push(row);
            }
            recovery.replaceChildren();
            recovery.hidden = rows.length === 0;
            if (rows.length) {
                const table = document.createElement('table');
                table.setAttribute('aria-label', 'Draft Values That Differ from the Current Form');
                const head = document.createElement('thead');
                const heading = document.createElement('tr');
                for (const title of ['Field', 'Current Value', 'Saved Draft', 'Actions']) {
                    const cell = document.createElement('th'); cell.scope = 'col'; cell.textContent = title;
                    heading.append(cell);
                }
                head.append(heading);
                const body = document.createElement('tbody'); body.append(...rows);
                table.append(head, body);
                recovery.append(table);
            }
            return rows.length;
        }
        function showDraftAvailability() {
            restore.hidden = discard.hidden = !saved;
            status.textContent = saved
                ? 'Draft available from ' + window.DnrUserTime.format(new Date(saved.at))
                : 'No saved draft';
        }
        function showSaved() {
            showDraftAvailability();
            const stale=saved && saved.version!==versions();
            restore.disabled=!!stale;
            const differences = renderRecovery(stale ? saved.fields : []);
            if (stale) status.textContent = differences
                ? 'The record changed since this draft was saved. Restore the values you want, then review the form before saving.'
                : 'The saved draft matches the current form. You can discard it.';
        }
        function persist() {
            const draft={fields:capture(),version:versions(),at:Date.now(),expires:Date.now()+30*86400000};
            controls().forEach((el, index) => {
                draft.fields[index].label = fieldLabel(el);
                draft.fields[index].displayValue = displayValue(draft.fields[index], el);
                if (el.dataset.draftField === 'internal') draft.fields[index].internal = true;
            });
            try {
                const text=JSON.stringify(draft);
                if(text.length>1000000) throw new Error('Draft too large');
                window.DnrAccountContext.local.setItem(key,text); saved=draft; lastSaved=JSON.stringify(capture());
                showSaved(); status.textContent='Draft saved in this browser';
            } catch (_) { status.textContent='Draft could not be saved in this browser. Keep this page open or copy your changes.'; }
        }
        save.addEventListener('click',persist);
        restore.addEventListener('click',async function () {
            if(!saved || saved.version!==versions() || applyingDraft) return;
            const before = capture();
            clearTimeout(timer);
            clearRestoredHighlights();
            const consumed = new Set();
            let remaining = saved.fields.slice();
            const dependency = field => Array.from(form.elements).some(el => el.name === field.name
                && (el.hasAttribute('data-address-country-native') || el.hasAttribute('data-draft-dependency')));
            applyingDraft = true;
            form.dataset.draftRestoring = '';
            save.disabled = restore.disabled = discard.disabled = true;
            status.textContent = 'Restoring draft…';
            try {
                const preparation = [];
                form.dispatchEvent(new CustomEvent('form-draft-restore', {bubbles: true, detail: {
                    fields: saved.fields, waitUntil: promise => preparation.push(promise)
                }}));
                if (preparation.length) await Promise.allSettled(preparation);
                let dependencies = remaining.filter(dependency);
                // Restore selectors that build dependent controls first, then wait
                // for their actual loads before matching the rest of the draft.
                do {
                    const batch = dependencies.length ? dependencies : remaining;
                    const retry = [];
                    const deferred = remaining.filter(field => !batch.includes(field));
                    controls().forEach(el => {
                        if (el.type === 'checkbox' && !consumed.has(el)) el.checked = false;
                    });
                    batch.forEach(function (value) {
                        const el = matchingControl(value, controls(), consumed);
                        if (!restoreValue(value, el)) { retry.push(value); return; }
                        consumed.add(el);
                    });
                    const pending = [];
                    form.dispatchEvent(new CustomEvent('form-draft-ready', {
                        detail: {waitUntil: promise => pending.push(promise)}
                    }));
                    if (pending.length) await Promise.allSettled(pending);
                    remaining = retry.concat(deferred);
                    const progress = retry.length < batch.length;
                    const hadDependencies = dependencies.length > 0;
                    dependencies = [];
                    if (!progress && !pending.length && !hadDependencies) break;
                } while (remaining.length);
            } finally {
                applyingDraft = false;
                delete form.dataset.draftRestoring;
                save.disabled = discard.disabled = false;
                restore.disabled = !!saved && saved.version !== versions();
            }
            clearTimeout(timer);
            const unresolved = renderRecovery(remaining);
            highlightRestoredChanges(before);
            status.textContent=unresolved?'Draft restored where controls still exist. Recreate missing rows using the saved values below; select files again.':'Draft restored. Review the form and select files again before saving.';
            lastSaved=JSON.stringify(capture());
        });
        discard.addEventListener('click',function () { clearTimeout(timer); clearRestoredHighlights(); try { window.DnrAccountContext.local.removeItem(key); } catch (_) {} saved=null; showSaved(); });
        function handleInput(event) {
            if(applyingDraft || panel.contains(event.target)) return;
            const edited = event.target.type === 'radio'
                ? controls().filter(el => el.name === event.target.name && el.type === 'radio') : [event.target];
            edited.forEach(el => {
                const target = highlightTarget(el);
                delete target.dataset.draftRestored;
                restoredHighlights.delete(target);
            });
            // Never overwrite an older draft before the user chooses to restore or discard it.
            if(saved && (!status.textContent || status.textContent.startsWith('Draft available'))) return;
            if(saved && saved.version !== versions()) { showSaved(); return; }
            if (recoveryFields.length) {
                const differences = renderRecovery(recoveryFields);
                if (!differences) status.textContent = 'The saved draft matches the current form. You can discard it.';
                return;
            }
            clearTimeout(timer); timer=setTimeout(persist,800);
        }
        for (const type of ['input', 'change']) {
            form.addEventListener(type, handleInput);
            document.addEventListener(type, event => {
                if (event.target.form === form && !form.contains(event.target)) handleInput(event);
            });
        }
        form.addEventListener('submit',function (event) {
            if (applyingDraft) { event.preventDefault(); return; }
            clearTimeout(timer); persist(); submitting=true;
        });
        window.addEventListener('beforeunload',function (event) {
            if(baseline !== '' && !submitting && JSON.stringify(capture())!==baseline && JSON.stringify(capture())!==lastSaved) { event.preventDefault(); event.returnValue=''; }
        });
        if (document.readyState === 'loading') {
            showDraftAvailability();
            // All controls exist at interactive, before deferred page scripts
            // finish. Resolve stale drafts then so loading those scripts does
            // not move the form. Reconcile once more after their enhancements.
            const parsed = () => {
                if (document.readyState === 'loading') return;
                document.removeEventListener('readystatechange', parsed);
                showSaved();
            };
            document.addEventListener('readystatechange', parsed);
            document.addEventListener('DOMContentLoaded', showSaved, {once: true});
        } else {
            showSaved();
        }
    }
    function scan() {
        const sidebar = document.getElementById('app-sidebar');
        if (!sidebar) return;
        const prefix = 'dnr.form-draft.' + encodeURIComponent(sidebar.dataset.navPreferenceUser) + '.';
        document.querySelectorAll('[data-clear-form-draft]').forEach(function (node) {
            try { window.DnrAccountContext.local.removeItem(prefix + node.dataset.clearFormDraft); } catch (_) {}
        });
        document.querySelectorAll('form[data-recoverable-draft]').forEach(initialize);
    }
    window.DnrFormDrafts = {
        initialize,
        registerRows,
        capture: form => forms.get(form)?.capture() || [],
        highlightRestored: (form, before, restored) => forms.get(form)?.highlightRestoredChanges(before, null, restored)
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, {once: true});
    else scan();
}());
