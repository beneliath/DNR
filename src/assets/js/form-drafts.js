(function () {
    'use strict';
    if (window.DnrFormDrafts) return;
    const initialized = new WeakSet();
    function initialize(form) {
        const sidebar = document.getElementById('app-sidebar');
        if (!sidebar || !form || initialized.has(form)) return;
        initialized.add(form);
        const prefix = 'dnr.form-draft.' + encodeURIComponent(sidebar.dataset.navPreferenceUser) + '.';
        const key = prefix + form.dataset.recoverableDraft;
        const controls = () => Array.from(form.elements).filter(el => el.name && !el.disabled && !['hidden','file','password','submit','button','reset'].includes(el.type));
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
        const panel = existingPanel || document.createElement('section'); panel.className = 'form-draft-panel';
        const status = document.createElement('p'); status.setAttribute('role','status');
        const save = document.createElement('button'); save.type='button'; save.className='button-secondary'; save.textContent='Save Draft';
        const restore = document.createElement('button'); restore.type='button'; restore.className='button-secondary'; restore.textContent='Restore Draft';
        const discard = document.createElement('button'); discard.type='button'; discard.className='button-secondary'; discard.textContent='Discard Draft';
        const recovery = document.createElement('div'); recovery.className='form-draft-recovery'; recovery.hidden=true;
        const note=document.createElement('p'); note.textContent='Drafts are saved for 30 days in this browser. Files must be selected again. Saving a draft does not save the record or send email.';
        panel.replaceChildren(status,save,restore,discard,note,recovery);
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
                const currentCell = document.createElement('td'); currentCell.dataset.field = 'Current value';
                currentCell.textContent = current ? displayValue(current, current)
                    : field.type === 'radio' && el ? 'Empty' : 'Field no longer available';
                const draftCell = document.createElement('td'); draftCell.dataset.field = 'Saved draft';
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
                table.setAttribute('aria-label', 'Draft values that differ from the current form');
                const head = document.createElement('thead');
                const heading = document.createElement('tr');
                for (const title of ['Field', 'Current value', 'Saved draft', 'Actions']) {
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
        function showSaved() {
            restore.hidden=discard.hidden=!saved;
            const stale=saved && saved.version!==versions();
            restore.disabled=!!stale;
            const differences = renderRecovery(stale ? saved.fields : []);
            status.textContent = 'No saved draft';
            if (saved) status.textContent = 'Draft available from ' + window.DnrUserTime.format(new Date(saved.at));
            if (stale) status.textContent = differences
                ? 'The record changed since this draft was saved. Restore the values you want, then review the form before saving.'
                : 'The saved draft matches the current form. You can discard it.';
        }
        function persist() {
            const draft={fields:capture(),version:versions(),at:Date.now(),expires:Date.now()+30*86400000};
            controls().forEach((el, index) => {
                draft.fields[index].label = fieldLabel(el);
                draft.fields[index].displayValue = displayValue(draft.fields[index], el);
            });
            try {
                const text=JSON.stringify(draft);
                if(text.length>1000000) throw new Error('Draft too large');
                window.DnrAccountContext.local.setItem(key,text); saved=draft; lastSaved=JSON.stringify(capture());
                showSaved(); status.textContent='Draft saved in this browser';
            } catch (_) { status.textContent='Draft could not be saved in this browser. Keep this page open or copy your changes.'; }
        }
        save.addEventListener('click',persist);
        restore.addEventListener('click',function () {
            if(!saved || saved.version!==versions()) return;
            const consumed=new Set(); const missing=[];
            controls().forEach(el => { if (el.type === 'checkbox') el.checked = false; });
            // Countries rebuild region choices and can switch between a select
            // and a text input. Restore them first, then find the live controls.
            const isCountry = value => Array.from(form.elements).some(el => el.name===value.name && el.hasAttribute('data-address-country-native'));
            const fields = saved.fields.filter(isCountry).concat(saved.fields.filter(value => !isCountry(value)));
            fields.forEach(function (value) {
                const el=matchingControl(value, controls(), consumed);
                if(!el) { missing.push(value); return; }
                consumed.add(el);
                if (!restoreValue(value, el)) missing.push(value);
            });
            clearTimeout(timer);
            renderRecovery(missing);
            status.textContent=missing.length?'Draft restored where controls still exist. Recreate missing rows using the saved values below; select files again.':'Draft restored. Review the form and select files again before saving.';
            lastSaved=JSON.stringify(capture());
        });
        discard.addEventListener('click',function () { clearTimeout(timer); try { window.DnrAccountContext.local.removeItem(key); } catch (_) {} saved=null; showSaved(); });
        function handleInput(event) {
            if(applyingDraft || panel.contains(event.target)) return;
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
        form.addEventListener('input', handleInput);
        form.addEventListener('change', handleInput);
        form.addEventListener('submit',function () { clearTimeout(timer); persist(); submitting=true; });
        window.addEventListener('beforeunload',function (event) {
            if(baseline !== '' && !submitting && JSON.stringify(capture())!==baseline && JSON.stringify(capture())!==lastSaved) { event.preventDefault(); event.returnValue=''; }
        });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', showSaved, {once: true});
        else showSaved();
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
    window.DnrFormDrafts = {initialize};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, {once: true});
    else scan();
}());
