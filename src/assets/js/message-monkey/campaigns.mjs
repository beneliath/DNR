import {$, $$, el, setValue, hidden, request} from './dom.mjs';
import {enhanceColorInput} from '../audience-color-picker.mjs';
import {wireAudienceFileDrop, enhanceAudienceFileInput} from '../audience-file-drop.mjs';

export function initializeRecentCampaigns(config) {
    const campaignCreate = $('#campaign-create');
    $$('[data-campaign-thumbnail]', campaignCreate || document).forEach(frame => {
        let requested = false;
        const load = async () => {
            requested = true;
            const status = $('[data-campaign-preview-status]', frame.parentElement);
            const data = new FormData();
            for (const [key, value] of Object.entries({action:'campaign_recent_preview',id:frame.dataset.campaignThumbnail,csrf_token:config.csrf,preview_nonce:frame.dataset.previewNonce})) data.set(key, value);
            try {
                const result = await request(config.url, {method:'POST', body:data});
                frame.addEventListener('load', () => { status.hidden = true; }, {once:true});
                frame.srcdoc = result.html;
            } catch (_) { status.textContent = 'Preview unavailable'; }
        };
        // Preserve the email's desktop layout while fitting its thumbnail to the card.
        const fit = () => {
            const box = frame.parentElement, scale = box.clientWidth / 600;
            if (!scale) return;
            frame.style.transform = `scale(${scale})`;
            frame.style.height = `${Math.ceil(box.clientHeight / scale)}px`;
            if (!requested) load();
        };
        new ResizeObserver(fit).observe(frame.parentElement);
    });
    $('[data-campaign-recent-more]')?.addEventListener('click', event => {
        const cards = $$('.campaign-recent-grid article'), hidden = cards.filter(card => card.hidden);
        hidden.slice(0, 4).forEach(card => { card.hidden = false; });
        event.currentTarget.hidden = hidden.length <= 4;
        $('[data-campaign-recent-count]').textContent = `${cards.filter(card => !card.hidden).length} recent emails shown.`;
    });
    $$('[data-campaign-recent-date]').forEach(time => {
        const date = new Date(time.dateTime);
        if (!Number.isNaN(date.getTime())) time.textContent = window.DnrUserTime.format(date, {dateStyle:'medium',timeStyle:'short'});
    });
}

export function initializeCampaignWorkspace(show, confirmations) {
    // Campaign actions reuse native dialogs, confirmations and top-layer menus.
    const campaignColumns = $('[data-campaign-columns]');
    if (campaignColumns) {
        const choices = $$('[data-campaign-column-toggle]:not(:disabled)', campaignColumns);
        const keys = choices.map(input => input.dataset.campaignColumnToggle);
        const storageKey = `campaign-columns:${document.body.dataset.audienceKey || 'default'}`;
        let stored; try { stored = JSON.parse(localStorage.getItem(storageKey)); } catch (_) { /* Optional preference. */ }
        const hiddenColumns = new Set(Array.isArray(stored) ? stored.filter(key => keys.includes(key)) : []);
        const render = () => {
            choices.forEach(input => { input.checked = !hiddenColumns.has(input.dataset.campaignColumnToggle); });
            $$('[data-campaign-column]').forEach(cell => { cell.hidden = hiddenColumns.has(cell.dataset.campaignColumn); });
        };
        const save = () => { try { localStorage.setItem(storageKey, JSON.stringify([...hiddenColumns])); } catch (_) { /* Works without storage. */ } };
        choices.forEach(input => input.addEventListener('change', () => {
            if (input.checked) hiddenColumns.delete(input.dataset.campaignColumnToggle);
            else hiddenColumns.add(input.dataset.campaignColumnToggle);
            render(); save();
        }));
        $('[data-campaign-columns-reset]', campaignColumns).addEventListener('click', () => { hiddenColumns.clear(); render(); save(); });
        render();
    }
    const campaignFilters = $('#campaign-filters');
    $$('[data-campaign-filter]').forEach(item => item.addEventListener('click', () => {
        setValue(campaignFilters, item.dataset.campaignFilter, item.dataset.value);
        campaignFilters?.requestSubmit();
    }));
    $('[data-campaign-direction]')?.addEventListener('click', () => {
        setValue(campaignFilters, 'direction', campaignFilters.elements.direction.value === 'asc' ? 'desc' : 'asc');
        campaignFilters.requestSubmit();
    });
    $('[data-campaign-folder-search]')?.addEventListener('input', event => {
        const query = event.target.value.trim().toLocaleLowerCase(), rows = $$('[data-folder-name]', $('#campaign-folders'));
        rows.forEach(row => { row.hidden = !row.dataset.folderName.includes(query); });
        const empty = $('[data-campaign-folder-empty]');
        if (empty) empty.hidden = query === '' || rows.some(row => !row.hidden);
    });
    const hideCampaignPopovers = () => $$('[popover]:popover-open').forEach(panel => panel.hidePopover());
    $$('[data-campaign-folder]').forEach(button => button.addEventListener('click', () => {
        const folder = JSON.parse(button.dataset.campaignFolder), form = $('#campaign-folder form');
        ['id', 'version', 'name'].forEach(key => setValue(form, key, folder[key]));
        $('#campaign-folder-title').textContent = folder.id ? 'Edit folder' : 'Create folder';
        hideCampaignPopovers(); show('campaign-folder');
    }));
    $$('[data-campaign-folder-delete]').forEach(button => button.addEventListener('click', () => {
        const folder = JSON.parse(button.dataset.campaignFolderDelete), form = $('[data-campaign-folder-delete-form]');
        ['id', 'version'].forEach(key => setValue(form, key, folder[key]));
        hideCampaignPopovers(); form.requestSubmit();
    }));
    const campaignSelection = $('[data-campaign-selection]');
    const campaignItems = $$('[data-campaign-select-item]');
    const campaignAll = $('[data-campaign-select-page]');
    const updateCampaignSelection = () => {
        const chosen = campaignItems.filter(item => item.checked);
        const allResults = campaignSelection?.elements.selection_scope.value === 'all';
        if (campaignAll) { campaignAll.checked = chosen.length > 0 && chosen.length === campaignItems.length; campaignAll.indeterminate = chosen.length > 0 && chosen.length < campaignItems.length; }
        if ($('[data-campaign-bulk]')) $('[data-campaign-bulk]').hidden = !chosen.length;
        if ($('[data-campaign-selection-count]')) $('[data-campaign-selection-count]').textContent = allResults ? 'All matching campaigns selected' : `${chosen.length} selected`;
    };
    campaignItems.forEach(item => item.addEventListener('change', () => { setValue(campaignSelection, 'selection_scope', 'page'); updateCampaignSelection(); }));
    campaignAll?.addEventListener('change', () => { campaignItems.forEach(item => { item.checked = campaignAll.checked; }); setValue(campaignSelection, 'selection_scope', 'page'); updateCampaignSelection(); });
    $('[data-campaign-select-all]')?.addEventListener('click', () => { campaignItems.forEach(item => { item.checked = true; }); setValue(campaignSelection, 'selection_scope', 'all'); updateCampaignSelection(); });
    const populateCampaignSelection = (form, row = null) => {
        const holder = $('[data-campaign-selected-hidden]', form); holder.replaceChildren();
        if (row) { holder.append(hidden('selected[]', row.id), hidden(`versions[${row.id}]`, row.version)); return; }
        if (!campaignSelection) return;
        const data = new FormData(campaignSelection);
        for (const [name, value] of data) if (name.startsWith('selected[') || name.startsWith('versions[') || name.startsWith('selection_')) holder.append(hidden(name, value));
    };
    $$('[data-open-dialog="campaign-move"]').forEach(button => button.addEventListener('click', () => populateCampaignSelection($('#campaign-move form'))));
    const openCampaignLifecycle = async (operation, row = null) => {
        const dialog = $(`#campaign-${operation}`);
        if (!dialog) return;
        const form = $('form', dialog);
        if (!form || (operation === 'delete' && !(await window.DnrAdminUnlock?.request()))) return;
        populateCampaignSelection(form, row);
        $('[data-campaign-lifecycle-summary]', form).textContent = row ? row.name : $('[data-campaign-selection-count]')?.textContent || '';
        if (form.elements.confirm) form.elements.confirm.value = '';
        show(dialog.id);
    };
    $$('[data-campaign-lifecycle-selected]').forEach(button => button.addEventListener('click', () => openCampaignLifecycle(button.dataset.campaignLifecycleSelected)));
    $$('[data-campaign-delete-form],[data-flow-delete-form],#person-action form,#bulk-people-form').forEach(deleteForm => {
        let approved = false, checking = false;
        deleteForm.addEventListener('submit', async event => {
            if (deleteForm.matches('#person-action form,#bulk-people-form') && deleteForm.elements.operation.value !== 'delete') return;
            if (approved) { approved = false; return; }
            event.preventDefault(); event.stopImmediatePropagation();
            if (checking) return;
            checking = true;
            try {
                if (await window.DnrAdminUnlock?.request()) {
                    approved = true;
                    deleteForm.requestSubmit(event.submitter || undefined);
                }
            } finally { checking = false; }
        });
    });
    $$('[data-campaign-row]').forEach(button => button.addEventListener('click', async () => {
        const row = JSON.parse(button.dataset.campaignRow), operation = button.dataset.campaignOp;
        hideCampaignPopovers();
        if (['archive','restore','delete'].includes(operation)) {
            await openCampaignLifecycle(operation, row); return;
        }
        if (operation === 'rename' || operation === 'move') {
            const dialog = operation === 'rename' ? 'campaign-rename' : 'campaign-move';
            const form = $(`#${dialog} form`); populateCampaignSelection(form, row);
            if (operation === 'rename') setValue(form, 'name', row.name);
            show(dialog); return;
        }
        const form = $('[data-campaign-operation-form]'); populateCampaignSelection(form, row); setValue(form, 'operation', operation);
        form.requestSubmit();
    }));
    $$('[data-campaign-cancel-section]').forEach(button => button.addEventListener('click', () => { button.form.reset(); button.closest('details').open = false; }));
    // Campaign checklist, recipient pickers and planning tools.
    $$('[data-recipient-picker]').forEach(picker => {
        const choices = $$('[data-recipient-choice]', picker), summary = $('[data-recipient-summary-label]', picker), original = picker.dataset.emptyLabel;
        const updateSummary = () => {
            const selected = choices.filter(choice => $('input', choice).checked).map(choice => $('span', choice).textContent.trim());
            summary.textContent = selected.length ? selected.join(', ') : original;
        };
        $('[data-recipient-search]', picker).addEventListener('input', event => choices.forEach(choice => { choice.hidden = !choice.textContent.toLowerCase().includes(event.target.value.toLowerCase()); }));
        $$('input[type=checkbox]', picker).forEach(input => input.addEventListener('change', () => {
            if ($$('input[type=checkbox]:checked', picker).length > 10) { input.checked = false; input.setCustomValidity('Select up to 10 tags or segments.'); input.reportValidity(); }
            input.setCustomValidity('');
            updateSummary();
        }));
        picker.addEventListener('focusout', () => requestAnimationFrame(() => { if (!picker.contains(document.activeElement)) picker.open = false; }));
        document.addEventListener('click', event => { if (!picker.contains(event.target)) picker.open = false; });
        picker.addEventListener('keydown', event => { if (event.key === 'Escape' && picker.open) { event.preventDefault(); picker.open = false; $('[data-recipient-summary]', picker).focus(); } });
        picker.closest('form').addEventListener('reset', () => requestAnimationFrame(() => { updateSummary(); picker.open = false; }));
        updateSummary();
    });
    $$('[name=personalize_to]').forEach(input => {
        const fields = $('[data-campaign-personalization]', input.form);
        const updatePersonalization = () => { fields.hidden = !input.checked; $('select', fields).disabled = !input.checked; };
        input.addEventListener('change', updatePersonalization);
        input.form.addEventListener('reset', () => requestAnimationFrame(updatePersonalization));
        updatePersonalization();
    });
    $('[data-campaign-recipient-preview]')?.form.addEventListener('reset', event => { $('[data-campaign-recipient-count]', event.currentTarget).textContent = ''; });
    const dispatchForm = $('#campaign-send-confirm form');
    if (dispatchForm) {
        const dialog = dispatchForm.closest('dialog'), submit = $('[data-campaign-dispatch-submit]', dispatchForm);
        const status = $('[data-campaign-dispatch-status]', dispatchForm), closeButtons = $$('[data-close-dialog]', dialog);
        const originalLabel = submit.textContent, originallyDisabled = submit.disabled;
        let pending = false;
        dispatchForm.addEventListener('submit', event => {
            event.preventDefault();
            if (pending) return;
            pending = true;
            submit.textContent = submit.dataset.pendingLabel;
            submit.classList.add('campaign-dispatch-submitting');
            submit.disabled = true;
            closeButtons.forEach(button => { button.disabled = true; });
            status.hidden = false;
            // Allow the loading state to paint before the browser starts the request.
            window.setTimeout(() => HTMLFormElement.prototype.submit.call(dispatchForm), 60);
        });
        dialog.addEventListener('cancel', event => { if (pending) event.preventDefault(); });
        window.addEventListener('pageshow', event => {
            if (!event.persisted) return;
            pending = false;
            submit.textContent = originalLabel;
            submit.classList.remove('campaign-dispatch-submitting');
            submit.disabled = originallyDisabled;
            closeButtons.forEach(button => { button.disabled = false; });
            status.hidden = true;
        });
    }
    $$('[data-campaign-merge]').forEach(select => select.addEventListener('change', () => { const input = document.getElementById(select.dataset.campaignMerge); if (!select.value || !input) return; const start = input.selectionStart ?? input.value.length; input.setRangeText(select.value, start, input.selectionEnd ?? start, 'end'); input.dispatchEvent(new Event('input', {bubbles:true})); input.focus(); select.value = ''; }));
    $('[data-campaign-recipient-preview]')?.addEventListener('click', async event => {
        const button = event.currentTarget, form = button.form, output = $('[data-campaign-recipient-count]', form), body = new FormData(form); body.set('action', 'campaign_preview');
        // The hidden field named action shadows HTMLFormElement.action. Preserve the campaign query.
        const endpoint = form.getAttribute('action') || location.href;
        const dialog = $('#campaign-recipient-preview'), status = $('[data-campaign-recipient-preview-status]', dialog), results = $('[data-campaign-recipient-results]', dialog);
        results.replaceChildren(); status.textContent = 'Loading recipients…'; output.textContent = '';
        show(dialog.id); button.disabled = true;
        try {
            const result = await request(endpoint, {method:'POST', body});
            output.textContent = status.textContent = `${Number(result.count).toLocaleString()} eligible email subscribers`;
            if (result.people.length) {
                const table = el('table'), head = el('tr'), rows = el('tbody');
                head.append(el('th', {scope:'col'}, 'Name'), el('th', {scope:'col'}, 'Email address'));
                const heading = el('thead'); heading.append(head); table.append(heading, rows);
                result.people.forEach(person => {
                    const row = el('tr'), name = [person.first_name, person.last_name].filter(Boolean).join(' ').trim();
                    row.append(el('td', {}, name || '—'), el('td', {}, person.email)); rows.append(row);
                });
                results.append(table);
                if (result.count > result.people.length) results.append(el('p', {class:'audience-help'}, `Showing the first ${result.people.length} recipients, sorted by email address.`));
            } else results.append(el('p', {}, 'No subscribed, active connections match these selections.'));
        } catch (error) { output.textContent = status.textContent = error.message; }
        finally { button.disabled = false; }
    });
    $('[data-campaign-date-preset]')?.addEventListener('change', event => { const form = event.target.form; $$('input[type=date]', form).forEach(input => { input.readOnly = !['','custom'].includes(event.target.value); if (event.target.value === '') input.value = ''; }); });
    const sendForm = $('[name=send_mode]')?.form;
    if (sendForm) {
        const updateSendMode = () => {
            const mode = $('[name=send_mode]:checked', sendForm)?.value;
            $$('[data-send-time-fields]', sendForm).forEach(group => { group.hidden = group.dataset.sendTimeFields !== mode; $$('input,select', group).forEach(input => { input.disabled = group.hidden; }); });
        };
        $$('[name=send_mode]', sendForm).forEach(input => input.addEventListener('change', updateSendMode)); updateSendMode();
    }
    const reviewLink = $('[data-campaign-review-link]'); if (reviewLink) reviewLink.value = new URL(reviewLink.value, location.href).href;
    $('[data-copy-campaign-review]')?.addEventListener('click', async () => { try { await navigator.clipboard.writeText(reviewLink.value); $('[data-copy-review-status]').textContent = 'Link copied.'; } catch { reviewLink.select(); $('[data-copy-review-status]').textContent = 'Select and copy this link to share with your team.'; } });
    const experiment = $('[data-experiment-form]');
    if (experiment) {
        const update = () => {
            let combinations = 1, variables = 0; $$('[data-experiment-variable]', experiment).forEach(section => {
                const enabled = $('[data-experiment-enabled]', section).checked; const inputs = $$('[data-experiment-values] input', section); if (enabled) { variables++; combinations *= inputs.length; }
                inputs.forEach(input => { input.disabled = !enabled; });
                $$('[data-experiment-remove]', section).forEach(button => { button.disabled = !enabled || inputs.length <= 2; });
                $('[data-experiment-add]', section).disabled = !enabled || inputs.length >= 3;
            });
            const choice = $('[data-experiment-enabled]', experiment), percent = $('[name=test_percent]', experiment);
            choice.setCustomValidity(variables !== 1 || combinations > 3 ? 'Choose one variable with two or three variations.' : '');
            const timeTest = $('[data-experiment-variable=time] [data-experiment-enabled]', experiment).checked;
            percent.setCustomValidity(timeTest && Number(percent.value) !== 100 ? 'Send-time tests must include 100% of recipients.' : '');
            $('[data-experiment-summary]', experiment).textContent = `${variables} variable · ${variables ? combinations : 0} variations · ${percent.value}% of recipients`;
        };
        $$('[data-experiment-add]', experiment).forEach(button => button.addEventListener('click', () => {
            const section = button.closest('[data-experiment-variable]'), host = $('[data-experiment-values]', section), labels = $$('label', host); if (labels.length >= 3) return;
            const label = labels[0].cloneNode(true), input = $('input', label); label.firstChild.textContent = `Variation ${labels.length + 1}`; input.value = section.dataset.experimentVariable === 'content' ? `Variation ${labels.length + 1}` : ''; host.append(label); update();
        }));
        experiment.addEventListener('click', event => { const remove = event.target.closest('[data-experiment-remove]'); if (remove) { remove.closest('label').remove(); update(); } });
        experiment.addEventListener('change', update); experiment.addEventListener('input', update); update();
    }
    const socialDialog = $('#campaign-social');
    if (socialDialog) {
        const render = () => { $('[data-social-preview-title]').textContent = $('[data-social-title]').value; $('[data-social-preview-description]').textContent = $('[data-social-description]').value; };
        socialDialog.addEventListener('input', render);
        $('[data-social-image-upload]').addEventListener('change', event => {
            const file = event.target.files[0], status = $('[data-social-error]'); if (!file) return;
            if (!['image/png','image/jpeg','image/webp','image/gif'].includes(file.type) || file.size > 1000000) { status.textContent = 'Choose a PNG, JPEG, WebP or GIF image up to 1 MB.'; event.target.value = ''; return; }
            const reader = new FileReader(); reader.onload = () => { const image = new Image(); image.onload = () => { if (image.naturalWidth * image.naturalHeight > 16000000) { status.textContent = 'Images must be 16 megapixels or less.'; return; } $('[data-social-image]').value = reader.result; const preview = $('[data-social-preview-image]'); preview.src = reader.result; preview.hidden = false; status.textContent = image.naturalWidth < 600 ? 'For a larger card, choose an image at least 600 pixels wide.' : ''; }; image.src = reader.result; }; reader.readAsDataURL(file);
        });
    }
    const touchpointDialog = $('#campaign-touchpoint-editor'), touchpointForm = $('[data-touchpoint-form]');
    if (touchpointForm) {
        const fields = () => { const type = touchpointForm.elements.touchpoint_type.value; touchpointForm.elements.campaign_id.disabled = type !== 'email'; touchpointForm.elements.campaign_id.closest('label').hidden = type !== 'email'; touchpointForm.elements.event_type.closest('label').hidden = type !== 'event'; const tracking = $('[data-touchpoint-tracking]', touchpointForm); if (tracking) tracking.hidden = type !== 'email'; };
        const openTouchpoint = (model = {}, day = '') => {
            touchpointForm.reset(); const data = model.data_json ? JSON.parse(model.data_json) : {};
            for (const key of ['id','version','plan_id','campaign_id','touchpoint_type','name','state']) if (model[key] != null) setValue(touchpointForm, key, model[key]);
            for (const key of ['notes','event_type']) setValue(touchpointForm, key, data[key] || ''); if (touchpointForm.elements.use_plan_tracking) touchpointForm.elements.use_plan_tracking.checked = !!data.use_plan_tracking;
            for (const key of ['start_at','end_at']) setValue(touchpointForm, key, model[key] ? model[key].replace(' ','T').slice(0,16) : `${day || new Date().toISOString().slice(0,10)}T09:00`);
            $('#campaign-touchpoint-title').textContent = model.id ? 'Edit touchpoint' : 'Add touchpoint';
            const actions = $('[data-touchpoint-actions]'); actions.hidden = !model.id;
            if (model.id) ['id','version','plan_id'].forEach(key => setValue(actions, key, model[key] || 0));
            fields(); show('campaign-touchpoint-editor');
        };
        $$('[data-touchpoint-new]').forEach(button => button.addEventListener('click', () => openTouchpoint({}, button.dataset.touchpointNew)));
        $$('[data-touchpoint-edit]').forEach(button => button.addEventListener('click', () => openTouchpoint(JSON.parse(button.dataset.touchpointEdit))));
        touchpointForm.elements.touchpoint_type.addEventListener('change', fields); fields();
    }
    $$('[data-skip-plan-confirm]').forEach(button => button.addEventListener('click', () => confirmations.approve(button.form)));

    // The content library and Brand Kit share image controls; uploads stay local until Save.
    $$('[data-file-drop]').forEach(wireAudienceFileDrop);
    $$('input[type=file]').forEach(input=>enhanceAudienceFileInput(input));
    $$('[data-brand-form] input[type=color]').forEach(input => enhanceColorInput(input,{tile:true}));
}

