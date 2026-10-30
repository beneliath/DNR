import {$, $$, el, button, setValue, hidden, request, workspaceUrl} from './dom.mjs';
import {initializeSegmentBuilder} from './segments.mjs';

export function initializeConnectionsWorkspace(config, show) {
    const audienceUrl = params => workspaceUrl(config, params);
    const definitionSelection = $('[data-definition-selection]');
    if (definitionSelection) {
        const items = $$('[data-definition-item]'), all = $('[data-definition-all]', definitionSelection);
        const actions = $('[data-definition-actions]', definitionSelection), count = $('[data-definition-count]', definitionSelection);
        const refresh = () => {
            const selected = items.filter(item => item.checked).length;
            all.checked = items.length > 0 && selected === items.length;
            all.indeterminate = selected > 0 && selected < items.length;
            all.disabled = items.length === 0;
            actions.hidden = selected === 0;
            count.textContent = `${selected} Selected`;
            $('button[type="submit"]', definitionSelection).disabled = selected === 0;
            items.forEach(item => item.closest('[data-definition-row],tr')?.classList.toggle('definition-selected', item.checked));
        };
        all.addEventListener('change', () => { items.forEach(item => { item.checked = all.checked; }); refresh(); });
        items.forEach(item => item.addEventListener('change', refresh));
        $('[data-definition-clear]', definitionSelection).addEventListener('click', () => { items.forEach(item => { item.checked = false; }); refresh(); });
        window.addEventListener('pageshow', refresh); refresh();
    }
    $$('[data-filter-search]').forEach(input => input.addEventListener('input', () => {
        const panel = input.closest('[data-filter-panel]');
        const options = $$('[data-filter-choice]', panel);
        options.forEach(choice => { choice.hidden = !choice.textContent.toLowerCase().includes(input.value.toLowerCase()); });
        $('[data-filter-empty]', panel).hidden = options.some(choice => !choice.hidden) || !options.length;
    }));
    const filterForm = $('.audience-filter-popovers'), peopleResults = $('[data-audience-results]'), filterSummary = $('[data-audience-filter-summary]');
    if (peopleResults && document.body.classList.contains('audience-connections-mode')) {
        // Wrapped controls and bulk actions must always leave room for connection rows.
        const reserveRows = () => {
            const height = ['.audience-table-tools','.audience-bulk-toolbar','.mm-pagination','thead']
                .reduce((sum,selector) => sum + ($(selector,peopleResults)?.getBoundingClientRect().height || 0),120);
            document.body.style.setProperty('--audience-connections-reserve',`${Math.ceil(height + 2)}px`);
        };
        const sizes = new ResizeObserver(reserveRows);
        const observeResults = () => {
            sizes.disconnect();
            [peopleResults,...$$('.audience-table-tools,.audience-bulk-toolbar,.mm-pagination,thead',peopleResults)].forEach(node => sizes.observe(node));
            reserveRows();
        };
        new MutationObserver(observeResults).observe(peopleResults,{childList:true});
        observeResults();
    }
    let filterRequest, filterSequence = 0, filterSearchTimer, filterPending = Promise.resolve(true), refreshPeopleColumns = () => {};
    const refreshFilters = () => {
        clearTimeout(filterSearchTimer); filterSearchTimer = undefined; const sequence = ++filterSequence; filterRequest?.abort(); filterRequest = new AbortController();
        const url = new URL(config.url, location.href), data = new FormData(filterForm);
        for (const [key,value] of data) if (!['open_filter','filter_query'].includes(key)) url.searchParams.append(key,String(value));
        peopleResults.inert = true; peopleResults.setAttribute('aria-busy','true'); $('[data-filter-error]').hidden = true;
        filterPending = (async () => {
            try {
                const response = await fetch(url,{credentials:'same-origin',headers:{Accept:'text/html'},cache:'no-store',signal:filterRequest.signal});
                if (!response.ok) throw new Error('Unable to update these filters. Try again.');
                const page = new DOMParser().parseFromString(await response.text(),'text/html');
                if (sequence !== filterSequence) return false;
                const nextResults = $('[data-audience-results]',page), nextConfig = $('#audience-config',page), nextSummary = $('[data-audience-filter-summary]',page);
                if (!nextResults || !nextConfig || !nextSummary) throw new Error('Sign in again, then reload this page to update filters.');
                const problem = $('[data-operation-error]',page); if (problem) throw new Error(problem.textContent.trim());
                const parsed = JSON.parse(nextConfig.textContent), columnsTrigger = $('[popovertarget="columns"]',peopleResults);
                if (columnsTrigger) $('[popovertarget="columns"]',nextResults)?.replaceWith(columnsTrigger);
                peopleResults.replaceChildren(...nextResults.childNodes); filterSummary.replaceChildren(...nextSummary.childNodes);
                const pageSize = $('[data-mm-page-size]',peopleResults)?.value;
                if (pageSize) { filterForm.elements.per_page.value = pageSize; url.searchParams.set('per_page',pageSize); }
                const lifecycle = $('[data-audience-lifecycle]'), nextLifecycle = $('[data-audience-lifecycle]',page);
                if (lifecycle && nextLifecycle) lifecycle.replaceChildren(...nextLifecycle.childNodes);
                Object.assign(config,parsed); $('#audience-config').textContent = nextConfig.textContent;
                for (const selector of ['#bulk-people-form','#save-current-segment form','#filter-campaign form']) {
                    const form = $(selector), next = $(selector,page); if (!form || !next) continue;
                    $$('input[type="hidden"]',form).forEach(input => input.remove());
                    form.append(...$$('input[type="hidden"]',next).map(input => input.cloneNode(true)));
                }
                $$('[data-instant-filter]',filterForm).forEach(input => { const next = $$('[data-instant-filter]',page).find(other => other.name === input.name && other.value === input.value); if (next) input.checked = next.checked; });
                $$('[popovertarget]',filterForm).forEach(trigger => {
                    const next = $$('[popovertarget]',page).find(other => other.getAttribute('popovertarget') === trigger.getAttribute('popovertarget'));
                    if (next) { trigger.replaceChildren(...Array.from(next.childNodes).map(node => node.cloneNode(true))); trigger.className = next.className; }
                });
                const suggestions = $('#connection-search-options'), nextSuggestions = $('#connection-search-options',page); if (suggestions && nextSuggestions) suggestions.replaceChildren(...nextSuggestions.childNodes);
                allMatching = false; selectedBoxes = $$('[data-person-select]'); updateSelection(); refreshPeopleColumns();
                window.dispatchEvent(new Event('resize'));
                history.replaceState(null,'',url); return true;
            } catch (error) {
                if (error.name === 'AbortError' || sequence !== filterSequence) return false;
                const notice = $('[data-filter-error]'); notice.textContent = error.message; notice.hidden = false; return false;
            } finally { if (sequence === filterSequence) { peopleResults.inert = false; peopleResults.removeAttribute('aria-busy'); } }
        })();
        return filterPending;
    };
    const currentFiltersReady = () => filterSearchTimer ? refreshFilters() : filterPending;
    $$('[data-instant-filter]').forEach(input => input.addEventListener('change',refreshFilters));
    filterForm?.addEventListener('submit',event => { event.preventDefault(); refreshFilters(); });
    $('#contact-search')?.addEventListener('input',() => { clearTimeout(filterSearchTimer); filterSearchTimer = setTimeout(refreshFilters,300); });
    $('#contact-search')?.addEventListener('search',refreshFilters);
    document.addEventListener('click', event => {
        $$('details.audience-menu[open], details.audience-filter[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
    });

    // Reference hover help is also available on keyboard focus and remains hoverable.
    let openTooltip = null;
    const hideTooltip = tooltip => { tooltip.hidePopover(); tooltip.hidden = true; if (openTooltip === tooltip) openTooltip = null; };
    $$('[data-audience-tooltip], .action-icon-button[data-tooltip], .audience-account-tools>[data-tooltip]').forEach((target, index) => {
        const icon = target.matches('.action-icon-button, .audience-account-tools>[data-tooltip]');
        const tooltip = el('div', {id: `audience-tooltip-${index}`, role: 'tooltip', class: `audience-tooltip${icon ? ' audience-icon-tooltip' : ''}`, popover: 'manual', hidden: true}, target.dataset.audienceTooltip || target.dataset.tooltip);
        document.body.append(tooltip); target.setAttribute('aria-describedby', tooltip.id);
        let timer;
        const hide = () => hideTooltip(tooltip);
        const display = () => {
            if (target.matches('.audience-nav-item.selected') && document.body.classList.contains('audience-sidebar-collapsed') && !mobileNavigation.matches) return;
            window.clearTimeout(timer); if (openTooltip && openTooltip !== tooltip) hideTooltip(openTooltip);
            tooltip.textContent = target.dataset.audienceTooltip || target.dataset.tooltip;
            tooltip.hidden = false; tooltip.showPopover(); openTooltip = tooltip;
            const rect = target.getBoundingClientRect(), box = tooltip.getBoundingClientRect();
            const beside = !icon && rect.right + box.width + 20 <= innerWidth;
            const above = icon && !target.closest('.admin-unlock-banner, .audience-account-tools') && rect.top >= box.height + 16;
            tooltip.classList.toggle('tooltip-below', !beside && !above);
            tooltip.classList.toggle('tooltip-above', above);
            tooltip.style.left = `${beside ? rect.right + 12 : Math.max(8, Math.min(rect.left, innerWidth - box.width - 8))}px`;
            tooltip.style.top = `${Math.max(8, Math.min(beside ? rect.top + (rect.height - box.height) / 2 : above ? rect.top - box.height - 8 : rect.bottom + 8, innerHeight - box.height - 8))}px`;
        };
        const hideLater = () => { timer = window.setTimeout(() => { if (!target.matches(':hover,:focus') && !tooltip.matches(':hover')) hide(); }, 140); };
        target.addEventListener('mouseenter', display); target.addEventListener('focus', display);
        target.addEventListener('mouseleave', hideLater); target.addEventListener('blur', hideLater);
        tooltip.addEventListener('mouseenter', () => window.clearTimeout(timer)); tooltip.addEventListener('mouseleave', hideLater);
        target.addEventListener('click', hide);
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && openTooltip) { hideTooltip(openTooltip); } });
    window.addEventListener('scroll', () => { if (openTooltip) { hideTooltip(openTooltip); } }, true);

    // Native popovers render above table clipping and retain light-dismiss / Escape behavior.
    $$('[popovertarget]').filter(trigger => trigger.getAttribute('popovertargetaction') !== 'hide').forEach(trigger => {
        const panel = document.getElementById(trigger.getAttribute('popovertarget'));
        if (!panel) return;
        const place = () => {
            const rect = trigger.getBoundingClientRect(), gap = panel.hasAttribute('data-filter-panel') ? 12 : 8;
            const availableBelow = innerHeight - rect.bottom - gap - 8;
            const height = Math.min(panel.scrollHeight, innerHeight - 16);
            const below = availableBelow >= height || availableBelow >= rect.top - 16;
            const columns = panel.id === 'columns';
            panel.style.maxHeight = `${columns ? innerHeight - 16 : Math.max(120, below ? availableBelow : rect.top - gap - 8)}px`;
            const box = panel.getBoundingClientRect();
            panel.style.left = `${Math.max(8, Math.min(panel.hasAttribute('data-filter-panel') ? rect.left : rect.right - box.width, innerWidth - box.width - 8))}px`;
            panel.style.top = `${Math.max(8, columns ? Math.min(rect.bottom + gap, innerHeight - box.height - 8) : below ? rect.bottom + gap : rect.top - box.height - gap)}px`;
        };
        panel.addEventListener('toggle', event => {
            trigger.setAttribute('aria-expanded', String(event.newState === 'open'));
            if (event.newState === 'open') place();
        });
        window.addEventListener('resize', () => { if (panel.matches(':popover-open')) place(); });
        window.addEventListener('scroll', event => { if (panel.matches(':popover-open') && !panel.contains(event.target)) place(); }, true);
        if (panel.hasAttribute('data-filter-panel')) {
            const choices = () => $$('input, a[href]', panel).filter(node => !node.closest('[hidden]'));
            const enter = () => choices()[0]?.focus();
            trigger.addEventListener('keydown', event => { if (event.key === 'ArrowDown') { event.preventDefault(); panel.showPopover(); place(); enter(); } });
            panel.addEventListener('toggle', event => { if (event.newState === 'open') enter(); });
            panel.addEventListener('keydown', event => {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); panel.hidePopover(); trigger.focus(); return; }
                if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
                const items = choices(), index = items.indexOf(document.activeElement);
                event.preventDefault(); items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus();
            });
            if (panel.hasAttribute('data-reopen-filter')) { panel.showPopover(); $('[data-filter-search]',panel)?.dispatchEvent(new Event('input')); }
        }
        if (panel.getAttribute('role') !== 'menu') return;
        const items = () => $$('[role="menuitem"], [role="menuitemradio"]', panel);
        trigger.addEventListener('keydown', event => {
            if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            event.preventDefault(); panel.showPopover(); place();
            const choices = items(); choices[event.key === 'ArrowDown' ? 0 : choices.length - 1]?.focus();
        });
        panel.addEventListener('keydown', event => {
            const choices = items(), index = choices.indexOf(document.activeElement);
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length;
            choices[next]?.focus();
        });
        panel.addEventListener('click', event => {
            const item = event.target.closest('[role="menuitem"]');
            if (item && item.getAttribute('aria-disabled') !== 'true') panel.hidePopover();
        }, true);
    });
    $$('[data-segment-rename]').forEach(trigger => trigger.addEventListener('click', () => {
        const model = JSON.parse(trigger.dataset.segmentRename), form = $('#rename-segment form');
        ['id', 'version', 'name'].forEach(key => setValue(form, key, model[key])); show('rename-segment');
    }));

    // Column edits are staged until Apply; closing the panel discards the draft.
    const columnList = $('[data-column-list]');
    if (columnList) {
        const panel = $('#columns'), key = `audience-columns:${document.body.dataset.audienceKey || 'default'}`;
        const choices = $$('[data-column-choice]', columnList), defaults = choices.map(row => row.dataset.columnChoice);
        const defaultVisible = choices.filter(row => $('[data-column-toggle]', row).checked).map(row => row.dataset.columnChoice);
        let stored; try { stored = JSON.parse(localStorage.getItem(key)); } catch (_) { /* Optional preference. */ }
        // Upgrade the former default while preserving explicitly customized layouts.
        const formerDefaults = [
            ['email', 'first_name', 'last_name', 'tags', 'source', 'created_at', 'updated_at'],
            ['email', 'first_name', 'last_name', 'tags', 'email_status', 'source', 'created_at'],
            ['email', 'first_name', 'last_name', 'tags', 'email_status', 'created_at']
        ];
        if (Array.isArray(stored?.visible) && formerDefaults.some(columns => JSON.stringify([...stored.visible].sort()) === JSON.stringify([...columns].sort()))) stored = {...stored, visible:defaultVisible};
        let committed = {
            order: ['email', ...new Set([...(Array.isArray(stored?.order) ? stored.order : defaults), ...defaults].filter(k => k !== 'email' && defaults.includes(k)))],
            visible: ['email', ...(Array.isArray(stored?.visible) ? stored.visible : defaultVisible).filter(k => k !== 'email' && defaults.includes(k))]
        };
        const restoreDraft = () => committed.order.forEach(key => {
            const row = choices.find(row => row.dataset.columnChoice === key); columnList.append(row);
            $('[data-column-toggle]', row).checked = committed.visible.includes(key);
        });
        const renderColumns = () => {
            for (const row of $$('[data-people-table] tr')) {
                const cells = $$('[data-column]', row);
                for (const key of committed.order) {
                    const cell = cells.find(c => c.dataset.column === key);
                    if (cell) { cell.hidden = !committed.visible.includes(key); row.append(cell); }
                }
            }
        };
        refreshPeopleColumns = renderColumns;
        let stopColumnDrag;
        panel.addEventListener('beforetoggle', event => {
            stopColumnDrag?.();
            if (event.newState === 'open') restoreDraft();
        });
        const announce = row => { $('[data-column-announcement]').textContent = `${$('[data-column-toggle]', row).parentElement.textContent.trim()}, position ${Array.from(columnList.children).indexOf(row) + 1}`; };
        choices.forEach(row => {
            const handle = $('[data-column-drag]', row); if (!handle) return;
            handle.addEventListener('keydown', event => {
                if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
                event.preventDefault();
                if (event.key === 'ArrowUp' && row.previousElementSibling?.dataset.columnChoice !== 'email') row.previousElementSibling?.before(row);
                if (event.key === 'ArrowDown') row.nextElementSibling?.after(row);
                handle.focus(); announce(row);
            });
            handle.addEventListener('pointerdown', event => {
                if (event.button !== 0 || event.isPrimary === false) return;
                event.preventDefault(); handle.focus(); row.classList.add('dragging');
                stopColumnDrag?.();
                const pointerId = event.pointerId;
                let pointerY = event.clientY, frame;
                // Moving a row reparents its handle, which can release pointer capture.
                // Listen on the document so dragging continues after every reorder.
                const reorder = () => {
                    const others = $$('[data-column-choice]', columnList).filter(other => other !== row && other.dataset.columnChoice !== 'email');
                    const next = others.find(other => { const rect = other.getBoundingClientRect(); return pointerY < rect.top + rect.height / 2; });
                    if (next && row.nextElementSibling !== next) next.before(row);
                    else if (!next && row.nextElementSibling) columnList.append(row);
                };
                const scroll = () => {
                    const bounds = columnList.getBoundingClientRect(), before = columnList.scrollTop;
                    if (pointerY < bounds.top + 35) columnList.scrollTop -= 8;
                    else if (pointerY > bounds.bottom - 35) columnList.scrollTop += 8;
                    if (before !== columnList.scrollTop) reorder();
                    frame = requestAnimationFrame(scroll);
                };
                const move = event => {
                    if (event.pointerId !== pointerId) return;
                    event.preventDefault(); pointerY = event.clientY; reorder();
                };
                const finish = event => {
                    if (event?.pointerId != null && event.pointerId !== pointerId) return;
                    cancelAnimationFrame(frame); stopColumnDrag = null;
                    row.classList.remove('dragging'); document.removeEventListener('pointermove', move, true);
                    document.removeEventListener('pointerup', finish, true); document.removeEventListener('pointercancel', finish, true);
                    window.removeEventListener('blur', finish); announce(row); handle.focus({preventScroll:true});
                };
                document.addEventListener('pointermove', move, {capture:true, passive:false});
                document.addEventListener('pointerup', finish, true); document.addEventListener('pointercancel', finish, true);
                window.addEventListener('blur', finish);
                stopColumnDrag = finish; frame = requestAnimationFrame(scroll);
            });
        });
        $('[data-apply-columns]').addEventListener('click', () => {
            const order = $$('[data-column-choice]', columnList);
            committed = {order: order.map(row => row.dataset.columnChoice), visible: order.filter(row => $('[data-column-toggle]', row).checked).map(row => row.dataset.columnChoice)};
            renderColumns(); try { localStorage.setItem(key, JSON.stringify(committed)); } catch (_) { /* Optional preference. */ }
            panel.hidePopover(); $('[popovertarget="columns"]').focus();
        });
        restoreDraft(); renderColumns();
    }

    let selectedBoxes = $$('[data-person-select]');
    let allMatching = false;
    const updateSelection = () => {
        const ids = selectedBoxes.filter(input => input.checked).map(input => input.value);
        const active = allMatching || ids.length > 0;
        const label = allMatching ? 'All matching connections selected' : `${ids.length} connections selected`;
        const toolbar = $('[data-bulk-toolbar]'); if (toolbar) toolbar.hidden = !active;
        if ($('[data-selection-count]')) $('[data-selection-count]').textContent = label;
        if ($('[data-bulk-summary]')) $('[data-bulk-summary]').textContent = label;
        if ($('[data-select-matches]')) $('[data-select-matches]').hidden = allMatching;
        const pageBox = $('[data-select-page]');
        if (pageBox) { pageBox.checked = selectedBoxes.length > 0 && ids.length === selectedBoxes.length; pageBox.indeterminate = ids.length > 0 && ids.length < selectedBoxes.length; }
        for (const [selector, target] of [['#bulk-people-form', '[data-bulk-selection]'], ['#people-export', '[data-export-selection]']]) {
            const form = $(selector); if (!form) continue;
            setValue(form, 'selection_scope', allMatching || (!active && selector === '#people-export') ? 'all' : 'selected');
            $(target, form).replaceChildren(...(allMatching ? [] : ids.map(id => hidden('selected[]', id))));
        }
        if ($('[data-export-label]')) $('[data-export-label]').textContent = active ? '↓ Export selection' : ($('[data-export-label]').dataset.exportBaseLabel || '↓ Export connections');
    };
    peopleResults?.addEventListener('change',event => {
        if (event.target.matches('[data-auto-submit]')) { event.target.form.requestSubmit(); return; }
        if (!event.target.matches('[data-person-select], [data-select-page]')) return;
        allMatching = false; if (event.target.matches('[data-select-page]')) selectedBoxes.forEach(input => { input.checked = event.target.checked; }); updateSelection();
    });
    peopleResults?.addEventListener('click',event => {
        const row = event.target.closest('[data-connection-row]');
        if (row && !event.defaultPrevented && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey
            && !event.target.closest('a, button, input, select, textarea, label, summary, [role="button"], [contenteditable], .selection-cell')
            && !window.getSelection()?.toString()) {
            $('[data-connection-link]',row)?.click();
            return;
        }
        const action = event.target.closest('[data-select-matches], [data-clear-selection], [data-open-dialog]'); if (!action) return;
        if (action.dataset.openDialog) { show(action.dataset.openDialog); return; }
        allMatching = action.hasAttribute('data-select-matches'); selectedBoxes.forEach(input => { input.checked = allMatching; }); updateSelection();
    });
    const descriptions = {
        archive: 'Archive connections to exclude them from campaigns while retaining their profiles and permission history.',
        restore: 'Unarchive connections. Their existing email subscription status is preserved.',
        unsubscribe: 'Stop audience email for these connections. Cleaned addresses remain suppressed.',
        delete: 'Permanently remove personal data and prevent these addresses from being imported again. This cannot be undone.',
        tag: 'Add this tag without changing other tags.', untag: 'Remove this tag. Connections and their other tags will be kept.',
        group: 'Add this group choice. For single-choice categories, it replaces the previous choice.', ungroup: 'Remove this group choice. Connections will be kept.'
    };
    const bulkOperation = $('#bulk-operation');
    const updateBulk = () => {
        if (!bulkOperation) return;
        const action = bulkOperation.value;
        $('[data-bulk-tag]').hidden = !['tag', 'untag'].includes(action);
        $('#bulk-selection-tag').required = ['tag', 'untag'].includes(action);
        $('[data-bulk-group]').hidden = !['group', 'ungroup'].includes(action);
        $('#bulk-selection-group').required = ['group', 'ungroup'].includes(action);
        $('[data-bulk-delete]').hidden = action !== 'delete';
        $('#bulk-delete-word').required = action === 'delete';
        $('[data-bulk-description]').textContent = descriptions[action];
    };
    bulkOperation?.addEventListener('change', updateBulk); updateBulk();
    $$('[data-person-operation]').forEach(b => b.addEventListener('click', async () => {
        const action = b.dataset.personOperation, form = $('#person-action form');
        if (action === 'delete' && !(await window.DnrAdminUnlock?.request())) return;
        setValue(form, 'operation', action); setValue(form, 'confirm_text', '');
        $('[data-person-action-title]').textContent = `${({restore: 'Unarchive', delete: 'Permanently delete'})[action] || action[0].toUpperCase() + action.slice(1)} connection`;
        $('[data-person-action-description]').textContent = descriptions[action];
        $('[data-person-delete-confirm]').hidden = action !== 'delete';
        $('#person-delete-word').required = action === 'delete';
        show('person-action');
    }));
    $$('[data-tag-edit]').forEach(b => b.addEventListener('click', () => {
        const data = JSON.parse(b.dataset.tagEdit), form = $('#tag-editor form');
        ['id', 'version', 'name'].forEach(key => setValue(form, key, data[key]));
        $('[data-tag-heading]').textContent = data.id ? 'Rename tag' : 'Create tag'; show('tag-editor');
    }));
    $$('[data-bulk-tag-id]').forEach(b => b.addEventListener('click', () => { $('#bulk-tag-id').value = b.dataset.bulkTagId; show('bulk-tag'); }));
    const groupOptions = $('[data-group-options]'), groupDialog = $('#group-editor');
    let stopGroupDrag;
    const renumberOptions = () => {
        $$('[data-group-option]', groupOptions).forEach((row, index) => {
            $('input[type=hidden]', row).name = `options[${index}][id]`;
            const input = $('input[type=text]', row);
            input.name = `options[${index}][name]`;
            $('[data-group-drag]', row)?.setAttribute('aria-label', `Reorder ${input.value.trim() || 'group option ' + (index + 1)}`);
        });
    };
    const addGroupOption = (option = {}) => {
        const row = el('div', {'data-group-option': '', class: 'group-option-row'});
        const handle = el('button', {type:'button', class:'group-option-drag', 'data-group-drag':'', 'aria-describedby':'group-options-reorder-help'});
        const icon = $('[data-group-drag-icon]');
        if (icon) handle.append(icon.content.cloneNode(true)); else handle.textContent = '⋮⋮';
        row.append(hidden('', option.id || 0), handle, el('input', {type: 'text', value: option.name || '', maxlength: 100, required: true, 'aria-label': 'Group choice name'}));
        const announce = () => { $('[data-group-order-announcement]').textContent = `${$('input[type=text]', row).value || 'Group option'}, position ${Array.from(groupOptions.children).indexOf(row) + 1}`; };
        handle.addEventListener('keydown', event => {
            if (!['ArrowUp','ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            if (event.key === 'ArrowUp') row.previousElementSibling?.before(row);
            else row.nextElementSibling?.after(row);
            renumberOptions(); handle.focus(); announce();
        });
        handle.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.isPrimary === false) return;
            event.preventDefault(); stopGroupDrag?.(); handle.focus(); row.classList.add('dragging');
            const pointerId = event.pointerId;
            let pointerY = event.clientY, frame;
            const reorder = () => {
                const next = $$('[data-group-option]', groupOptions).filter(other => other !== row).find(other => {
                    const rect = other.getBoundingClientRect(); return pointerY < rect.top + rect.height / 2;
                });
                if (next && row.nextElementSibling !== next) next.before(row);
                else if (!next && row.nextElementSibling) groupOptions.append(row);
                renumberOptions();
            };
            const scroll = () => {
                const bounds = groupDialog.getBoundingClientRect(), before = groupDialog.scrollTop;
                if (pointerY < bounds.top + 35) groupDialog.scrollTop -= 8;
                else if (pointerY > bounds.bottom - 35) groupDialog.scrollTop += 8;
                if (before !== groupDialog.scrollTop) reorder();
                frame = requestAnimationFrame(scroll);
            };
            const move = event => {
                if (event.pointerId !== pointerId) return;
                event.preventDefault(); pointerY = event.clientY; reorder();
            };
            const finish = event => {
                if (event?.pointerId != null && event.pointerId !== pointerId) return;
                cancelAnimationFrame(frame); stopGroupDrag = null; row.classList.remove('dragging');
                document.removeEventListener('pointermove', move, true); document.removeEventListener('pointerup', finish, true);
                document.removeEventListener('pointercancel', finish, true); window.removeEventListener('blur', finish);
                announce(); handle.focus({preventScroll:true});
            };
            document.addEventListener('pointermove', move, {capture:true, passive:false});
            document.addEventListener('pointerup', finish, true); document.addEventListener('pointercancel', finish, true);
            window.addEventListener('blur', finish); stopGroupDrag = finish; frame = requestAnimationFrame(scroll);
        });
        const remove = button('×', async () => {
            if (option.id && !(await window.DnrAdminUnlock?.request())) return;
            const next = row.nextElementSibling || row.previousElementSibling; row.remove(); renumberOptions(); (next?.querySelector('input[type=text]') || $('[data-add-group-option]')).focus();
        }, 'Remove group choice');
        if (option.id) { remove.setAttribute('data-admin-unlock-required', ''); remove.hidden = !config.canDelete; }
        row.append(remove);
        groupOptions.append(row); renumberOptions();
    };
    groupDialog?.addEventListener('close', () => stopGroupDrag?.());
    groupOptions?.addEventListener('input', renumberOptions);
    $('[data-add-group-option]')?.addEventListener('click', () => { addGroupOption(); $('[data-group-option]:last-child input[type=text]', groupOptions)?.focus(); });
    $$('[data-group-edit]').forEach(b => b.addEventListener('click', () => {
        const data = JSON.parse(b.dataset.groupEdit), form = $('#group-editor form');
        ['id', 'version', 'name', 'display_type'].forEach(key => setValue(form, key, data[key]));
        groupOptions.replaceChildren(); (data.options?.length ? data.options : [{}]).forEach(addGroupOption);
        $('[data-group-heading]').textContent = data.id ? 'Edit group' : 'Create group'; show('group-editor');
    }));
    const updateFieldChoices = () => { if ($('[data-field-choices]')) $('[data-field-choices]').hidden = !['dropdown', 'radio', 'checkboxes'].includes($('#field-type').value); };
    $('#field-type')?.addEventListener('change', updateFieldChoices);
    let mergeTagEdited = false;
    $('#field-merge')?.addEventListener('input', () => { mergeTagEdited = true; });
    $('#field-label')?.addEventListener('input', event => {
        if (!mergeTagEdited && !Number($('#field-editor form').elements.namedItem('id').value)) $('#field-merge').value = event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '').replace(/^[^A-Z]+/, '').slice(0, 30);
    });
    const lockFieldType = id => {
        const field = $('#field-type'), form = field.form;
        $('[data-locked-field-type]', form)?.remove(); field.disabled = Number(id) > 0;
        if (field.disabled) { const value = hidden('field_type', field.value); value.dataset.lockedFieldType = ''; form.append(value); }
    };
    $$('[data-field-edit]').forEach(b => b.addEventListener('click', () => {
        const data = JSON.parse(b.dataset.fieldEdit), form = $('#field-editor form'); lockFieldType(0); form.reset();
        ['id', 'version', 'label', 'merge_tag', 'field_type', 'position', 'default_value'].forEach(key => setValue(form, key, data[key] ?? (key === 'position' ? 0 : '')));
        setValue(form, 'options_text', (typeof data.options_json === 'string' ? JSON.parse(data.options_json) : data.options_json || []).join('\n'));
        ['required', 'is_public'].forEach(key => { form.elements.namedItem(key).checked = Number(data[key]) === 1; });
        lockFieldType(data.id); updateFieldChoices(); mergeTagEdited = Number(data.id) > 0;
        $('[data-field-heading]').textContent = Number(data.id) ? 'Edit field' : 'Create new field';
        show('field-editor');
    }));
    const importMethods = $$('input[name="import_method"]');
    const showImportMethod = () => {
        const selected = importMethods.find(input => input.checked)?.value;
        $$('[data-import-method]').forEach(panel => {
            panel.hidden = panel.dataset.importMethod !== selected;
            $$('input, textarea', panel).forEach(control => { control.disabled = panel.hidden; control.required = !panel.hidden; });
        });
    };
    importMethods.forEach(input => input.addEventListener('change', showImportMethod));
    if (importMethods.length) showImportMethod();
    $$('[data-import-mapping]').forEach(select => select.addEventListener('change', () => { $('[data-new-field-name]', select.parentElement).hidden = select.value !== 'new_text'; }));

    const openSegment = initializeSegmentBuilder(config, show, currentFiltersReady, filterSummary);
    if (config.job && ['queued', 'processing'].includes(config.job.state) && $('[data-job-panel]')) {
        let stopped = false, startedAt = 0;
        const showError = message => { $('[data-job-error]').textContent = message; $('[data-job-error]').hidden = false; };
        const start = async () => {
            startedAt = Date.now();
            const data = new FormData(); for (const [key, value] of Object.entries({action: 'process_job', id: config.job.id, audience: config.audience, csrf_token: config.csrf})) data.set(key, value);
            try { await request(config.url, {method: 'POST', body: data}); } catch (error) { if (!stopped) showError(error.message); }
        };
        const poll = async () => {
            if (stopped) return;
            try {
                const status = await request(audienceUrl({api: 'job', id: Number(config.job.id)}));
                $('[data-job-progress]').value = Number(status.processed);
                $('[data-job-progress-label]').textContent = `${Number(status.processed).toLocaleString()} of ${Number(status.total).toLocaleString()} processed`;
                $$('[data-job-stat]').forEach(node => { node.textContent = Number(status[node.dataset.jobStat]).toLocaleString(); });
                if (!['queued', 'processing'].includes(status.state)) { stopped = true; location.reload(); return; }
                if (Date.now() - startedAt > 245000) start();
                window.setTimeout(poll, 1500);
            } catch (error) { showError(`${error.message} You can reload or resume this operation from history.`); }
        };
        start(); window.setTimeout(poll, 1000);
        window.addEventListener('pagehide', () => { stopped = true; });
    }
    // Reopen failed editors with submitted values and the original version token.
    // A stale version must never be silently replaced by the latest record version.
    if (config.recovery) {
        const {dialog: id, values, error} = config.recovery, dialog = document.getElementById(id), form = dialog?.querySelector('form');
        if (form) {
            if (id === 'segment-builder') openSegment({...values, rules: config.rules});
            if (id === 'group-editor') { groupOptions.replaceChildren(); (values.options || []).forEach(addGroupOption); }
            for (const control of form.elements) {
                if (!control.name || !(control.name in values) || control.name === 'csrf_token') continue;
                if (['checkbox', 'radio'].includes(control.type)) control.checked = String(values[control.name]) === control.value;
                else if (typeof values[control.name] !== 'object') control.value = values[control.name];
            }
            if (id === 'tag-editor') $('[data-tag-heading]').textContent = Number(values.id) ? 'Rename tag' : 'Create tag';
            if (id === 'field-editor') { lockFieldType(values.id); updateFieldChoices(); mergeTagEdited = true; }
            const notice = el('p', {class: 'audience-notice error', role: 'alert', tabindex: '-1'}, error);
            form.prepend(notice); show(id); notice.focus();
            dialog.addEventListener('close', () => notice.remove(), {once: true});
        }
    } else $('[data-operation-error]')?.focus();
    const exportHistory = $('#export-history-delete');
    if (exportHistory) {
        const items = $$('[data-export-select]:not(:disabled)', exportHistory), all = $('[data-export-select-page]', exportHistory);
        const update = () => { const count = items.filter(item => item.checked).length; $('[data-export-delete-selected]', exportHistory).disabled = !count; all.checked = items.length > 0 && count === items.length; all.indeterminate = count > 0 && count < items.length; };
        all.addEventListener('change', () => { items.forEach(item => { item.checked = all.checked; }); update(); });
        items.forEach(item => item.addEventListener('change', update));
        $$('[data-export-delete]', exportHistory).forEach(button => button.addEventListener('click', () => { items.forEach(item => { item.checked = item.value === button.dataset.exportDelete; }); update(); exportHistory.requestSubmit($('[data-export-delete-selected]', exportHistory)); }));
        update();
    }
}

