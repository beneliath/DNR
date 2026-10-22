import { enhanceColorInput } from './audience-color-picker.mjs';

export function initEmbeddedForm(form, config) {
    const workspace = document.querySelector('[data-embedded-workspace]');
    const menu = form.querySelector('[data-embedded-menu]');
    const frame = workspace.querySelector('[data-collection-preview]');
    const status = form.querySelector('[data-embedded-status]');
    const codePanel = form.querySelector('[data-embedded-code-panel]');
    const code = form.querySelector('[data-embedded-code]');
    const copy = form.querySelector('[data-embedded-copy]');
    const fieldList = form.querySelector('[data-embedded-fields]');
    const originalOrder = [...fieldList.children].map(row => row.dataset.embeddedField);
    let model = structuredClone(config.document), previewRequest, timer, sequence = 0, busy = false;
    let published = false;
    const canManage = config.canManage;
    form.querySelectorAll('input[type=color]').forEach(input => enhanceColorInput(input));
    const showPanel = key => {
        menu.hidden = Boolean(key);
        form.querySelectorAll('[data-embedded-panel]').forEach(panel => { panel.hidden = panel.dataset.embeddedPanel !== key; });
        form.querySelectorAll('[data-embedded-field-editor]').forEach(panel => { panel.hidden = panel.dataset.embeddedFieldEditor !== key; });
        codePanel.hidden = key !== 'code';
        workspace.classList.toggle('is-code', key === 'code');
        menu.querySelectorAll('[data-embedded-open]').forEach(button => button.classList.toggle('selected', button.dataset.embeddedOpen === key || (!key && button.dataset.embeddedOpen === 'fields')));
        document.querySelector('[data-embedded-continue]')?.toggleAttribute('hidden', key === 'code');
        form.scrollTop = 0;
    };
    document.querySelectorAll('[data-embedded-open]').forEach(button => button.addEventListener('click', () => showPanel(button.dataset.embeddedOpen)));
    form.querySelectorAll('[data-embedded-home]').forEach(button => button.addEventListener('click', () => showPanel('')));
    form.querySelectorAll('[data-embedded-edit]').forEach(button => button.addEventListener('click', () => showPanel(button.dataset.embeddedEdit)));
    workspace.querySelector('[data-embedded-dismiss-notice]').addEventListener('click', event => { event.currentTarget.closest('aside').hidden = true; });
    const rows = () => [...fieldList.children];
    const rowFor = key => rows().find(row => row.dataset.embeddedField === key);
    const escapeAttribute = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
    const updateCode = () => {
        const url = escapeAttribute(config.publicUrl);
        code.value = model.embedded.disable_javascript
            ? `<iframe src="${url}" title="${escapeAttribute(model.title)}" width="100%" height="720" frameborder="0" sandbox="allow-forms allow-same-origin allow-popups" referrerpolicy="origin"></iframe>`
            : `<script src="${url}&amp;embed=1" defer></script>`;
    };
    const sync = () => {
        const previous = JSON.stringify(model);
        form.querySelectorAll('[data-doc]').forEach(input => { model[input.dataset.doc] = input.type === 'number' ? Number(input.value) : input.value; });
        model.embedded ||= {};
        form.querySelectorAll('[data-embedded-option]').forEach(input => {
            if (input.type === 'radio' && !input.checked) return;
            model.embedded[input.dataset.embeddedOption] = input.type === 'checkbox' ? input.checked : input.value;
        });
        for (const [selector, key, attribute] of [['[data-doc-label]', 'labels', 'docLabel'], ['[data-doc-help]', 'help_text', 'docHelp'], ['[data-doc-message]', 'messages', 'docMessage']]) {
            if (!model[key] || Array.isArray(model[key])) model[key] = {...model[key]};
            form.querySelectorAll(selector).forEach(input => { model[key][input.dataset[attribute]] = input.value; });
        }
        form.querySelectorAll('[data-doc-lines]').forEach(input => { model[input.dataset.docLines] = input.value.split('\n').map(line => line.trim()).filter(Boolean); });
        for (const key of ['tag_ids', 'group_ids']) model[key] = [...form.querySelectorAll(`[data-doc-array="${key}"]:checked`)].map(input => Number(input.value));
        model.fields = rows().filter(row => row.querySelector('[data-embedded-visible]').checked).map(row => row.dataset.embeddedField);
        model.required = [...form.querySelectorAll('[data-embedded-required]:checked')].map(input => input.dataset.embeddedRequired);
        rows().forEach((row, index) => {
            const key = row.dataset.embeddedField;
            row.querySelector('[data-embedded-field-label]').textContent = model.labels[key];
            row.querySelector('[data-embedded-required-mark]').hidden = !model.required.includes(key);
            row.classList.toggle('is-disabled', !model.fields.includes(key));
            row.querySelector('[data-embedded-move="up"]').disabled = !canManage || index === 0 || !model.fields.includes(key);
            row.querySelector('[data-embedded-move="down"]').disabled = !canManage || !model.fields.includes(key) || index >= model.fields.length - 1;
        });
        form.querySelector('[data-collection-document]').value = JSON.stringify(model);
        if (previous !== JSON.stringify(model)) {
            published = false; copy.disabled = true;
            form.querySelector('[data-embedded-publish-status]').textContent = 'Publish your changes before copying the code.';
        }
        updateCode();
    };
    const fit = () => {
        try {
            const root = frame.contentDocument?.documentElement;
            if (!root) return;
            frame.style.height = '1px';
            frame.style.height = `${Math.max(120, Math.ceil(root.scrollHeight) + 2)}px`;
        } catch { /* A public cross-origin iframe keeps its own scrollable fallback. */ }
    };
    frame.addEventListener('load', fit);
    const api = async (action, extra = {}, signal) => {
        sync(); const body = new FormData(form); body.set('action', action);
        body.set('name', form.querySelector('[name=name]').value);
        Object.entries(extra).forEach(([key, value]) => body.set(key, String(value)));
        const response = await fetch(location.href, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}, signal});
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Sign in to MessageMonkey in another tab, then try again. Your draft is still open.');
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'The form could not be updated.');
        return result;
    };
    const preview = async () => {
        if (config.previewOnly) { fit(); return; }
        previewRequest?.abort(); previewRequest = new AbortController(); const current = ++sequence;
        try {
            const result = await api('collection_preview', {preview_nonce: config.previewNonce}, previewRequest.signal);
            if (current !== sequence) return;
            frame.srcdoc = result.html; status.textContent = '';
        } catch (error) { if (error.name !== 'AbortError' && current === sequence) status.textContent = error.message; }
    };
    const changed = () => { sync(); clearTimeout(timer); timer = setTimeout(preview, 250); };
    const normalizeFields = () => {
        const all = rows();
        all.filter(row => row.querySelector('[data-embedded-visible]').checked).forEach(row => fieldList.append(row));
        all.filter(row => !row.querySelector('[data-embedded-visible]').checked)
            .sort((a,b) => originalOrder.indexOf(a.dataset.embeddedField) - originalOrder.indexOf(b.dataset.embeddedField)).forEach(row => fieldList.append(row));
    };
    form.addEventListener('input', changed);
    form.addEventListener('change', event => {
        const input = event.target;
        if (input.matches('[data-embedded-visible]')) {
            if (!input.checked) {
                const key = input.closest('[data-embedded-field]').dataset.embeddedField;
                const required = [...form.querySelectorAll('[data-embedded-required]')].find(box => box.dataset.embeddedRequired === key);
                if (required && !required.disabled) required.checked = false;
            }
            normalizeFields();
        }
        if (input.matches('[data-embedded-required]') && input.checked) {
            rowFor(input.dataset.embeddedRequired).querySelector('[data-embedded-visible]').checked = true;
            normalizeFields();
        }
        changed();
    });
    rows().forEach(row => {
        row.querySelectorAll('[data-embedded-move]').forEach(button => button.addEventListener('click', () => {
            if (!canManage) return;
            const other = button.dataset.embeddedMove === 'up' ? row.previousElementSibling : row.nextElementSibling;
            if (!other || !other.querySelector('[data-embedded-visible]').checked) return;
            if (button.dataset.embeddedMove === 'up') other.before(row); else other.after(row);
            changed(); button.focus();
        }));
        row.addEventListener('dragstart', event => {
            if (!canManage || !row.querySelector('[data-embedded-visible]').checked) { event.preventDefault(); return; }
            event.dataTransfer.setData('text/plain', row.dataset.embeddedField); event.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragover', event => { if (canManage && row.querySelector('[data-embedded-visible]').checked) event.preventDefault(); });
        row.addEventListener('drop', event => {
            event.preventDefault(); if (!canManage) return;
            const from = rowFor(event.dataTransfer.getData('text/plain'));
            if (!from || from === row || !from.querySelector('[data-embedded-visible]').checked || !row.querySelector('[data-embedded-visible]').checked) return;
            if (rows().indexOf(from) < rows().indexOf(row)) row.after(from); else row.before(from);
            changed();
        });
    });
    const tagSearch = form.querySelector('[data-embedded-tag-search]');
    const filterTags = () => form.querySelectorAll('[data-embedded-tags]>label').forEach(label => { label.hidden = !label.textContent.toLowerCase().includes(tagSearch.value.toLowerCase()); });
    tagSearch.addEventListener('input', filterTags);
    form.querySelector('[data-embedded-tag-create]').addEventListener('click', async event => {
        if (!canManage) return;
        const input = form.querySelector('[data-embedded-tag-name]'), feedback = form.querySelector('[data-embedded-tag-status]');
        if (!input.value.trim()) { feedback.textContent = 'Enter a tag name.'; input.focus(); return; }
        const button = event.currentTarget; button.disabled = true;
        try {
            const result = await api('collection_new_tag', {tag_name: input.value.trim()});
            let checkbox = [...form.querySelectorAll('[data-doc-array="tag_ids"]')].find(box => Number(box.value) === result.id);
            if (!checkbox) {
                const label = document.createElement('label'); label.className = 'embedded-check';
                checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.dataset.docArray = 'tag_ids'; checkbox.value = String(result.id);
                label.append(checkbox, document.createTextNode(result.name)); form.querySelector('[data-embedded-tags]').append(label);
            }
            checkbox.checked = true; input.value = ''; tagSearch.value = ''; filterTags();
            form.querySelector('[data-embedded-no-tags]').hidden = true; feedback.textContent = 'Tag added.'; changed();
        } catch (error) { feedback.textContent = error.message; }
        button.disabled = false;
    });
    const valid = () => {
        const invalid = form.querySelector(':invalid');
        if (!invalid) return true;
        const panel = invalid.closest('[data-embedded-panel],[data-embedded-field-editor]');
        showPanel(panel?.dataset.embeddedPanel || panel?.dataset.embeddedFieldEditor || 'code');
        invalid.reportValidity(); return false;
    };
    const save = async () => {
        const result = await api('collection_save');
        form.querySelector('[name=version]').value = String(result.version);
        await preview();
        status.textContent = 'Draft saved.';
        return result;
    };
    const run = async action => {
        if (busy || !canManage || !valid()) return;
        busy = true; clearTimeout(timer);
        const fieldsets = [...form.querySelectorAll('fieldset')].map(fieldset => [fieldset, fieldset.disabled]);
        fieldsets.forEach(([fieldset]) => { fieldset.disabled = true; });
        document.querySelectorAll('[data-embedded-finish],[data-embedded-continue],[data-embedded-publish]').forEach(button => { button.disabled = true; });
        try { await action(); } catch (error) { status.textContent = error.message; }
        finally { busy = false; fieldsets.forEach(([fieldset, disabled]) => { fieldset.disabled = disabled; }); document.querySelectorAll('[data-embedded-finish],[data-embedded-continue],[data-embedded-publish]').forEach(button => { button.disabled = false; }); }
    };
    document.querySelector('[data-embedded-continue]')?.addEventListener('click', () => run(async () => { await save(); showPanel('code'); }));
    document.querySelector('[data-embedded-finish]')?.addEventListener('click', () => run(async () => { await save(); location.assign(config.exitUrl); }));
    form.addEventListener('submit', event => { event.preventDefault(); run(save); });
    form.querySelector('[data-embedded-publish]').addEventListener('click', () => run(async () => {
        await save();
        const result = await api('collection_operation', {operation: 'publish'});
        form.querySelector('[name=version]').value = String(result.version);
        published = true; copy.disabled = false;
        form.querySelector('[data-embedded-publish-status]').textContent = 'Published. Your embed code is ready to copy.';
        status.textContent = '';
    }));
    form.querySelectorAll('[data-embedded-operation]').forEach(button => button.addEventListener('click', () => {
        const operation = button.dataset.embeddedOperation;
        const confirm = form.querySelector('[data-embedded-remove-confirm]');
        if (operation === 'remove' && confirm.value !== 'REMOVE') { status.textContent = 'Type REMOVE before removing this form.'; confirm.focus(); return; }
        run(async () => {
            await save();
            const result = await api('collection_operation', {operation, confirm: confirm.value});
            if (operation === 'remove') location.assign(config.exitUrl);
            else if (operation === 'replicate') location.assign(`${config.formUrl}&id=${result.id}`);
            else {
                form.querySelector('[name=version]').value = String(result.version);
                published = false; copy.disabled = true;
                status.textContent = 'Paused. Public collection has stopped.';
                form.querySelector('[data-embedded-publish-status]').textContent = 'Paused. Publish the form to resume collection.';
            }
        });
    }));
    copy.addEventListener('click', async () => {
        if (!published) return;
        const feedback = form.querySelector('[data-embedded-copy-status]');
        try { await navigator.clipboard.writeText(code.value); feedback.textContent = 'Code copied.'; }
        catch { code.focus(); code.select(); feedback.textContent = 'Select and copy this code.'; }
    });
    window.addEventListener('resize', fit);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fit).observe(workspace.querySelector('.embedded-preview-stage'));
    normalizeFields(); sync(); preview();
}
