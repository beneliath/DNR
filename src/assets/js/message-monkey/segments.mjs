import {$, $$, el, button, setValue, clone, request, workspaceUrl} from './dom.mjs';

export function initializeSegmentBuilder(config, show, currentFiltersReady, filterSummary) {
    const audienceUrl = params => workspaceUrl(config, params);
    const ruleRoot = $('[data-rule-root]');
    let tree = {match: 'all', rules: []};
    const segmentTree = () => {
        const scope = $('[data-segment-status]')?.value || '';
        if (!scope) return tree;
        const conditions = tree.rules.length ? (tree.match === 'all' ? tree.rules : [tree]) : [];
        return {match:'all',rules:[{field:'email_status',operator:'is',value:scope},...conditions]};
    };
    const defaultRule = () => ({field: 'email_status', operator: 'is', value: 'subscribed'});
    const clearPreview = () => { if ($('[data-segment-results]')) $('[data-segment-results]').hidden = true; if ($('[data-segment-error]')) $('[data-segment-error]').hidden = true; };
    const ruleDepth = group => 1 + Math.max(0, ...group.rules.filter(rule => Array.isArray(rule.rules)).map(ruleDepth));
    let pickerNumber = 0, activeFieldPicker = null;
    window.addEventListener('resize', () => activeFieldPicker?.close());
    document.addEventListener('scroll', event => {
        if (activeFieldPicker && !activeFieldPicker.list.contains(event.target)) activeFieldPicker.close();
    }, true);
    // Search stays in the combobox while arrows move its active option.
    const fieldPicker = (value, select, focusKey) => {
        const wrap = el('div', {class: 'rule-field-picker'}), listId = `rule-fields-${++pickerNumber}`;
        const input = el('input', {type: 'text', role: 'combobox', 'aria-label': 'Filter field', 'aria-autocomplete': 'list', 'aria-controls': listId, 'aria-expanded': 'false', autocomplete: 'off', 'data-rule-focus': focusKey, placeholder: 'Select or search a filter', value: config.catalog[value]?.label || ''});
        const list = el('div', {id: listId, role: 'listbox', class: 'rule-field-options', 'aria-label': 'Filter fields', hidden: true});
        let active = -1, options = [];
        const close = () => { list.hidden = true; if (activeFieldPicker?.list === list) activeFieldPicker = null; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); input.value = config.catalog[value]?.label || ''; };
        const position = () => {
            const rect = input.getBoundingClientRect(), dialog = input.closest('dialog')?.getBoundingClientRect();
            const bottom = Math.min(window.innerHeight, dialog?.bottom ?? window.innerHeight) - 12;
            const top = Math.max(0, dialog?.top ?? 0) + 12;
            const below = bottom - rect.bottom - 8, above = rect.top - top - 8;
            const up = below < 240 && above > below, height = Math.max(0, Math.min(320, up ? above : below));
            list.style.position = 'fixed'; list.style.width = Math.min(Math.max(rect.width, 300), window.innerWidth - 32) + 'px';
            list.style.left = Math.max(16, Math.min(rect.left, window.innerWidth - list.offsetWidth - 16)) + 'px';
            list.style.maxHeight = height + 'px';
            list.style.top = (up ? rect.top - list.offsetHeight - 8 : rect.bottom + 8) + 'px';
        };
        const highlight = index => {
            active = index; options.forEach((option, i) => option.setAttribute('aria-selected', String(i === active)));
            if (options[active]) { input.setAttribute('aria-activedescendant', options[active].id); options[active].scrollIntoView({block: 'nearest'}); }
        };
        const search = term => {
            list.replaceChildren(); options = []; active = -1; input.removeAttribute('aria-activedescendant');
            const sections = {};
            for (const [key, entry] of Object.entries(config.catalog || {})) {
                if (!`${entry.label} ${entry.section}`.toLowerCase().includes(term.toLowerCase())) continue;
                if (!sections[entry.section]) {
                    const group = el('div', {role: 'group', 'aria-label': entry.section});
                    group.append(el('div', {class: 'rule-field-section', 'aria-hidden': 'true'}, entry.section)); list.append(group); sections[entry.section] = group;
                }
                const option = el('div', {id: `${listId}-${options.length}`, role: 'option', 'aria-selected': 'false'}, entry.label);
                option.addEventListener('mousedown', event => event.preventDefault());
                option.addEventListener('click', () => select(key)); option.dataset.field = key;
                options.push(option); sections[entry.section].append(option);
            }
            if (!options.length) list.append(el('p', {role: 'status'}, 'No matching filters'));
            if (activeFieldPicker?.list !== list) activeFieldPicker?.close();
            list.hidden = false; input.setAttribute('aria-expanded', 'true');
            activeFieldPicker = {list, close}; position();
        };
        input.addEventListener('focus', () => { input.select(); search(''); });
        input.addEventListener('click', () => { if (list.hidden) search(''); });
        input.addEventListener('input', () => search(input.value));
        input.addEventListener('blur', close);
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !list.hidden) { event.preventDefault(); event.stopPropagation(); close(); }
            else if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault(); if (list.hidden) search('');
                if (options.length) highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
            } else if (event.key === 'Enter' && !list.hidden) { event.preventDefault(); if (options[active]) select(options[active].dataset.field); }
        });
        wrap.append(input, list); return wrap;
    };
    const renderRules = (focusKey = null) => {
        if (!ruleRoot) return;
        clearPreview(); ruleRoot.replaceChildren();
        const renderGroup = (group, depth, parent, index, path = 'root') => {
            const box = el('section', {class: 'segment-rule-group', 'aria-label': depth ? 'Nested condition group' : 'Segment conditions'});
            const header = el('div', {class: 'rule-group-heading'});
            const match = el('select', {'aria-label': 'Match conditions', 'data-rule-focus': `${path}-match`});
            match.append(el('option', {value: 'all'}, 'all'), el('option', {value: 'any'}, 'any')); match.value = group.match;
            match.addEventListener('change', () => { group.match = match.value; renderRules(`${path}-match`); });
            header.append(el('span', {}, 'Match'), match, el('span', {}, 'of the following conditions'));
            if (parent) header.append(button('Remove group', () => { parent.rules.splice(index, 1); renderRules(`${path.substring(0, path.lastIndexOf('-'))}-match`); }));
            box.append(header);
            group.rules.forEach((rule, i) => {
                if (i) box.append(el('span', {class: 'rule-join'}, group.match === 'all' ? 'AND' : 'OR'));
                const rulePath = `${path}-${i}`;
                if (Array.isArray(rule.rules)) { box.append(renderGroup(rule, depth + 1, group, i, rulePath)); return; }
                const row = el('div', {class: 'segment-condition'});
                const field = fieldPicker(rule.field, key => {
                    rule.field = key; delete rule.campaign_id; delete rule.link_hash; delete rule.bots; const definition = config.catalog[key]; rule.operator = Object.keys(config.operators[definition.kind])[0];
                    rule.value = definition.kind === 'membership' ? [] : definition.options ? Object.keys(definition.options)[0] || '' : '';
                    renderRules(`${rulePath}-operator`);
                }, `${rulePath}-field`);
                const definition = config.catalog[rule.field] || {kind: 'text'};
                const operator = el('select', {'aria-label': 'Condition operator', 'data-rule-focus': `${rulePath}-operator`});
                for (const [key, label] of Object.entries(config.operators[definition.kind] || {})) operator.append(el('option', {value: key}, label));
                operator.value = rule.operator;
                operator.addEventListener('change', () => { rule.operator = operator.value; if (definition.kind === 'membership' && !Array.isArray(rule.value)) rule.value = []; else if (definition.kind !== 'membership' && Array.isArray(rule.value)) rule.value = ''; renderRules(`${rulePath}-operator`); });
                row.append(field, operator);
                if (!['empty', 'not_empty', 'ever', 'never'].includes(rule.operator)) {
                    if (definition.kind === 'membership') {
                        const picker = el('details', {class: 'rule-membership'}), checked = Array.isArray(rule.value) ? rule.value.map(String) : [];
                        const summary = el('summary', {}, checked.length ? `${checked.length} selected` : 'Choose values'); const list = el('div', {class: 'rule-membership-options'});
                        for (const [key, label] of Object.entries(definition.options || {})) {
                            const archived = (definition.archived_options || []).includes(Number(key));
                            const choice = el('input', {type: 'checkbox', value: key, checked: checked.includes(key), disabled: archived && !checked.includes(key)});
                            choice.addEventListener('change', () => { rule.value = $$('input:checked', list).map(input => Number(input.value)); summary.textContent = rule.value.length ? `${rule.value.length} selected` : 'Choose values'; clearPreview(); });
                            const labelNode = el('label', {class: 'audience-check'}); labelNode.append(choice, document.createTextNode(label + (archived ? ' (Archived)' : ''))); list.append(labelNode);
                        }
                        if (!list.children.length) list.append(el('p', {}, 'No values available. Create a tag or group first.'));
                        picker.append(summary, list); row.append(picker);
                    } else if (definition.kind === 'choice') {
                        const value = el('select', {'aria-label': 'Condition value'});
                        for (const [key, label] of Object.entries(definition.options || {})) value.append(el('option', {value: key}, label));
                        value.value = String(rule.value ?? ''); value.addEventListener('change', () => { rule.value = value.value; clearPreview(); }); row.append(value);
                    } else {
                        const type = ['last_days', 'not_last_days'].includes(rule.operator) || definition.kind === 'number' ? 'number' : definition.kind === 'date' || (['event', 'engagement'].includes(definition.kind) && ['before', 'after'].includes(rule.operator)) ? 'date' : 'text';
                        const value = el('input', {type, value: rule.value ?? '', 'aria-label': 'Condition value', required: true, ...(type === 'number' ? {step: ['last_days', 'not_last_days'].includes(rule.operator) ? 1 : 'any'} : {}), ...(['last_days', 'not_last_days'].includes(rule.operator) ? {min: 1, max: 36500} : {})});
                        value.addEventListener('input', () => { rule.value = value.value; clearPreview(); }); row.append(value);
                    }
                }
                if (definition.kind === 'engagement') {
                    for (const [property, label, options] of [
                        ['campaign_id', 'Campaign', {'0': 'Any campaign', ...definition.campaigns}],
                        ['bots', 'Engagement data', {filtered: 'Exclude known bots', all: 'Include detected bots'}],
                        ...(rule.field === 'engagement:click' ? [['link_hash', 'Tracked link', {'': 'Any link', ...definition.links}]] : [])
                    ]) {
                        const picker = el('select', {'aria-label': label});
                        for (const [key, text] of Object.entries(options)) picker.append(el('option', {value: key}, text));
                        picker.value = String(rule[property] ?? Object.keys(options)[0]);
                        picker.addEventListener('change', () => { rule[property] = picker.value; clearPreview(); });
                        row.append(picker);
                    }
                }
                const actions = el('div', {class: 'rule-condition-actions'});
                actions.append(button('⧉', () => { group.rules.splice(i + 1, 0, clone(rule)); renderRules(`${path}-${i + 1}-field`); }, 'Duplicate filter'));
                actions.append(button('×', () => { group.rules.splice(i, 1); renderRules(`${path}-match`); }, 'Delete filter'));
                row.append(actions);
                box.append(row);
            });
            const actions = el('div', {class: 'rule-add-actions'});
            for (const join of ['all', 'any']) actions.append(button(join === 'all' ? '＋ Add "and"' : '＋ Add "or"', () => {
                if (group.match === join || group.rules.length < 2) { group.match = join; group.rules.push(defaultRule()); renderRules(`${path}-${group.rules.length - 1}-field`); }
                else if (depth + ruleDepth(group) < 5) { const previous = clone(group); group.match = join; group.rules = [previous, defaultRule()]; renderRules(`${path}-1-field`); }
                else { const notice = $('[data-segment-error]'); notice.textContent = 'This group has reached the nesting limit. Change its match logic or add a condition within an existing group.'; notice.hidden = false; }
            }));
            if (depth < 4) actions.append(button('＋ Add group', () => { group.rules.push({match: 'any', rules: [defaultRule()]}); renderRules(`${path}-${group.rules.length - 1}-0-field`); }));
            box.append(actions); return box;
        };
        ruleRoot.append(renderGroup(tree, 0));
        if (focusKey) $$('[data-rule-focus]', ruleRoot).find(node => node.dataset.ruleFocus === focusKey)?.focus();
    };
    const openSegment = (model = {}) => {
        const form = $('#segment-form'); if (!form) return;
        ['id', 'version', 'name'].forEach(key => setValue(form, key, model[key] || (key === 'name' ? '' : 0)));
        if ($('[data-segment-status]')) $('[data-segment-status]').value = '';
        tree = clone(model.rules || {match: 'all', rules: [defaultRule()]});
        renderRules(); show('segment-builder');
    };
    $$('[data-segment-new]').forEach(b => b.addEventListener('click', () => openSegment()));
    const openCurrentSegment = async () => { if (await currentFiltersReady()) openSegment({...config.segment,rules:config.currentRules || config.rules}); };
    $$('[data-segment-current]').filter(b => !b.closest('[data-audience-filter-summary]')).forEach(b => b.addEventListener('click',openCurrentSegment));
    filterSummary?.addEventListener('click',async event => {
        const action = event.target.closest('[data-segment-current], [data-open-dialog]'); if (!action) return;
        if (action.hasAttribute('data-segment-current')) { openCurrentSegment(); return; }
        if (await currentFiltersReady()) show(action.dataset.openDialog);
    });
    $$('[data-segment-edit], [data-segment-copy]').forEach(b => b.addEventListener('click', () => {
        const model = JSON.parse(b.dataset.segmentEdit || b.dataset.segmentCopy);
        if (b.hasAttribute('data-segment-copy')) { model.id = 0; model.version = 0; model.name = `${model.name} (copy)`.slice(0, 100); }
        openSegment(model);
    }));
    $('[data-segment-status]')?.addEventListener('change',clearPreview);
    $('#segment-form')?.addEventListener('submit', () => setValue($('#segment-form'), 'rules', JSON.stringify(segmentTree())));
    $('[data-segment-preview]')?.addEventListener('click', async event => {
        const trigger = event.currentTarget; trigger.disabled = true; clearPreview();
        try {
            const data = new FormData($('#segment-form')); data.set('action', 'preview_segment'); data.set('rules', JSON.stringify(segmentTree()));
            const result = await request(config.url, {method: 'POST', body: data}), panel = $('[data-segment-results]');
            panel.replaceChildren(el('h3', {}, `${Number(result.count).toLocaleString()} matching connections`));
            if (result.people.length) {
                const list = el('ul'); for (const person of result.people) { const item = el('li'); item.append(el('a', {href: audienceUrl({view: 'contact', id: Number(person.id)})}, person.email)); list.append(item); } panel.append(list);
            } else panel.append(el('p', {}, 'No connections currently match these conditions.'));
            panel.hidden = false;
        } catch (error) { $('[data-segment-error]').textContent = error.message; $('[data-segment-error]').hidden = false; }
        finally { trigger.disabled = false; }
    });
    $('[data-segment-apply]')?.addEventListener('click', () => {
        const url = new URL(config.url, location.href); for (const [key,value] of Object.entries(config.listContext || {})) url.searchParams.set(key,String(value)); url.searchParams.set('rules', JSON.stringify(segmentTree())); location.assign(url);
    });

    return openSegment;
}

